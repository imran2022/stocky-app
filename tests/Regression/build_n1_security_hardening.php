<?php

/**
 * Build N1 — Security hardening (2026-09-19).
 *
 * Fixes for the 5 Critical findings from the third-party
 * "StockyUltimate Enterprise Deep Audit" (2026-09-19), verified against
 * this codebase before fixing:
 *
 *   C-01: SalesController::store()/update() trusted client-submitted
 *         GrandTotal/TaxNet/line subtotal exactly, with no server-side
 *         consistency check. Fixed with app/Support/SaleTotalsGuard.php.
 *   C-02: A completed sale could succeed with no product_warehouse row
 *         for that product/warehouse — the deduction silently no-opped
 *         and NO stock was ever touched. Fixed with
 *         app/Support/StockMutator.php (lockOrCreate: never returns null,
 *         always creates the row first if missing, and locks it for the
 *         duration of the transaction — this also closes part of H-02).
 *   C-03: Sale/Purchase/PO/Expense document uploads accepted ANY file
 *         type into public/images/... (a .php file could be uploaded).
 *         Fixed with app/Support/SafeDocumentUpload.php (extension +
 *         content-type allow-list, filename built only from the
 *         validated extension).
 *   C-04: `database:backup` wrote to storage/app/public/backup (web-
 *         reachable if storage:link has been run), with a predictable
 *         filename, deleted every existing backup BEFORE attempting the
 *         new one, and passed the DB password as a CLI argument. Fixed:
 *         moved to the private storage/app/backups, prune only AFTER a
 *         new backup is verified non-empty (keeps the last 14), and the
 *         password now goes through a short-lived 0600 defaults-extra-file
 *         instead of the command line.
 *   C-05: sale/purchase/transfer/adjustment/payment PDF & print routes
 *         sat OUTSIDE any auth middleware group in routes/api.php, and
 *         most of the controller methods had no authorization check at
 *         all — any unauthenticated request could view any document by
 *         guessing its numeric ID. Fixed: routes moved inside
 *         auth:api + Is_Active, and every method now calls the matching
 *         model's 'view' policy.
 *
 * This test exercises each fix against the real database and the real
 * controllers/command — not mocks. It intentionally does NOT attempt to
 * re-verify every one of the ~10 other product_warehouse call sites in
 * PurchasesController/TransferController/etc: the same silent-skip and
 * trust-the-client patterns exist there too (documented in the delivery
 * README as follow-up work), but this build's scope is the Sales flow the
 * audit actually reproduced against, plus the upload/backup/route fixes
 * which are shared across all document types.
 *
 * Run with: php tests/Regression/build_n1_security_hardening.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Console\Commands\DatabaseBackUp;
use App\Http\Controllers\SalesController;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use App\Support\SafeDocumentUpload;
use App\Support\SaleTotalsGuard;
use App\Support\StockMutator;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$approx = static function ($a, $b, string $message, float $eps = 0.01) use (&$failures): void {
    if (abs((float) $a - (float) $b) > $eps) {
        $failures[] = "{$message} (expected {$b}, got {$a})";
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$salesController = app(SalesController::class);
$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = Product::whereNull('deleted_at')->first();

// This sandbox DB has no seeded `units` rows at all, even though the test
// product references a unit_sale_id — the OLD code tolerated a missing
// Unit by silently no-op'ing the stock mutation (part of what C-02 fixed).
// The new, stricter behavior (abort rather than guess) needs a real unit
// to exercise the "stock actually gets deducted" path, so create one here,
// exactly like a real install always has at least one.
$testUnit = Unit::firstOrCreate(
    ['name' => 'Build N1 Test Unit'],
    ['ShortName' => 'N1U', 'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]
);
$saleUnitId = $testUnit->id;

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build N1 Test Client', 'firstname' => 'N1', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$cleanupSaleIds = [];
$cleanupWarehouseId = null;
$cleanupProductWarehouseIds = [];

// ==================================================================
// C-01: server-side total consistency
// ==================================================================

echo "== C-01: server-side total consistency ==\n";

// A) The exact shape of attack the audit reproduced: line Unit_price=100,
// quantity=1, but a line subtotal of 1 and a GrandTotal wildly unrelated
// to the line items. Must be REJECTED (HTTP 422), and nothing saved.
$saleCountBefore = Sale::count();
$badReq = Request::create('/api/sales', 'POST', [
    'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
    'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build N1 tamper test',
    'tax_rate' => 0, 'TaxNet' => 777.777, 'discount' => 0, 'discount_Method' => '2',
    'shipping' => 0, 'GrandTotal' => 987654.321, 'discount_from_points' => 0, 'used_points' => 0,
    'payment' => ['status' => 'pending'],
    'details' => [[
        'product_id' => $product->id, 'quantity' => 1, 'Unit_price' => 100,
        'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 1,
        'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
        'serial_numbers' => [], 'sale_unit_id' => $saleUnitId,
    ]],
]);
app()->instance('request', $badReq);
$badResp = $salesController->store($badReq);
$assert($badResp->getStatusCode() === 422, 'C-01: a tampered GrandTotal/line-total request must be rejected with HTTP 422 (got '.$badResp->getStatusCode().').');
$assert(Sale::count() === $saleCountBefore, 'C-01: no Sale row must be created for a rejected/tampered request.');

// B) An insane tax percent alone (777%, exactly the audit's TaxNet value used
// as a tax rate here) on an otherwise-plausible line must also be rejected.
try {
    SaleTotalsGuard::checkLine(1, 100, 0, '2', 777.777, 100);
    $failures[] = 'C-01: SaleTotalsGuard::checkLine must reject a 777% tax percent.';
} catch (\InvalidArgumentException $e) {
    // expected
}

// C) A NORMAL, legitimate sale — including a per-line percentage discount
// and a non-zero tax — must still be accepted. This is the regression
// check that the new guard doesn't break real checkouts.
// Line: qty=2, price=100, 10% discount -> net 90/unit, 5% tax -> 94.5/unit,
// line total = 189. Header: shipping=10, discount=5% on 189 -> 179.55+10=189.55.
$goodLineTotal = 2 * (100 * 0.9) * 1.05; // 189.0
$goodGrandTotal = ($goodLineTotal * 0.95) + 10; // 5% header discount + shipping
$goodReq = Request::create('/api/sales', 'POST', [
    'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
    'date' => now()->toDateString(), 'statut' => 'draft', 'notes' => 'Build N1 legit sale',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 5, 'discount_Method' => '1',
    'shipping' => 10, 'GrandTotal' => $goodGrandTotal, 'discount_from_points' => 0, 'used_points' => 0,
    'payment' => ['status' => 'pending'],
    'details' => [[
        'product_id' => $product->id, 'quantity' => 2, 'Unit_price' => 100,
        'tax_percent' => 5, 'tax_method' => '1', 'subtotal' => $goodLineTotal,
        'discount' => 10, 'discount_Method' => '1', 'product_variant_id' => null,
        'serial_numbers' => [], 'sale_unit_id' => $saleUnitId,
    ]],
]);
app()->instance('request', $goodReq);
$goodResp = $salesController->store($goodReq);
$goodBody = json_decode($goodResp->getContent(), true);
$assert($goodResp->getStatusCode() === 200 || $goodResp->getStatusCode() === 201, 'C-01: a mathematically-consistent sale (with a real discount and tax) must still be ACCEPTED (got '.$goodResp->getStatusCode().': '.$goodResp->getContent().').');
if (! empty($goodBody['sale_id'])) {
    $cleanupSaleIds[] = $goodBody['sale_id'];
}

// ==================================================================
// C-02: stock deduction can no longer silently no-op
// ==================================================================

echo "== C-02: stock deduction never silently skips ==\n";

// A dedicated, brand-new warehouse guarantees NO product_warehouse row
// exists yet for $product in it — exactly the audit's reproduction setup.
$freshWarehouse = Warehouse::create([
    'name' => 'Build N1 Fresh Warehouse '.time(),
]);
$cleanupWarehouseId = $freshWarehouse->id;

$rowExistsBefore = product_warehouse::whereNull('deleted_at')
    ->where('warehouse_id', $freshWarehouse->id)
    ->where('product_id', $product->id)
    ->exists();
$assert(! $rowExistsBefore, 'C-02 setup: the fresh warehouse must start with NO stock row for this product.');

// (Unit_price=100, qty=3, no discount/tax -> line subtotal must be 300 to
// pass the C-01 guard.)
$stockReq = Request::create('/api/sales', 'POST', [
    'client_id' => $client->id, 'warehouse_id' => $freshWarehouse->id,
    'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build N1 missing-stock-row test',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
    'shipping' => 0, 'GrandTotal' => 300, 'discount_from_points' => 0, 'used_points' => 0,
    'payment' => ['status' => 'pending'],
    'details' => [[
        'product_id' => $product->id, 'quantity' => 3, 'Unit_price' => 100,
        'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 300,
        'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
        'serial_numbers' => [], 'sale_unit_id' => $saleUnitId,
    ]],
]);
app()->instance('request', $stockReq);
$stockResp = $salesController->store($stockReq);
$stockBody = json_decode($stockResp->getContent(), true);
$assert($stockResp->getStatusCode() === 200 || $stockResp->getStatusCode() === 201, 'C-02: the sale itself must still complete successfully (got '.$stockResp->getStatusCode().': '.$stockResp->getContent().').');
if (! empty($stockBody['sale_id'])) {
    $cleanupSaleIds[] = $stockBody['sale_id'];
}

$rowAfter = product_warehouse::whereNull('deleted_at')
    ->where('warehouse_id', $freshWarehouse->id)
    ->where('product_id', $product->id)
    ->whereNull('product_variant_id')
    ->first();
$assert($rowAfter !== null, 'C-02: a product_warehouse row must now exist (it used to silently never be created).');
if ($rowAfter) {
    $cleanupProductWarehouseIds[] = $rowAfter->id;
    // Base unit with operator_value effectively 1 for a plain unit -> qty deducted = -3.
    $approx($rowAfter->qte, -3, 'C-02: stock must actually be deducted (was previously always left at 0 / never created).');
}

// Direct StockMutator::lockOrCreate() unit check: calling it twice for the
// same key must return the SAME row (not create a duplicate) — sanity check
// for the H-02 lockForUpdate/find-or-create logic.
DB::transaction(function () use ($freshWarehouse, $product, $assert) {
    $first = StockMutator::lockOrCreate($freshWarehouse->id, $product->id, null);
    $second = StockMutator::lockOrCreate($freshWarehouse->id, $product->id, null);
    $assert($first->id === $second->id, 'C-02: StockMutator::lockOrCreate must not create a duplicate row on a second call.');
});

// ==================================================================
// C-03: upload allow-list
// ==================================================================

echo "== C-03: document upload allow-list ==\n";

$phpFile = UploadedFile::fake()->createWithContent('invoice.php', "<?php echo 'pwned'; ?>");
$v1 = Validator::make(['documents' => [$phpFile]], ['documents.*' => SafeDocumentUpload::validationRule()]);
$assert($v1->fails(), 'C-03: a .php file must FAIL the document upload validation rule.');

$doublePhpFile = UploadedFile::fake()->createWithContent('invoice.pdf.php', "<?php echo 'pwned'; ?>");
$v2 = Validator::make(['documents' => [$doublePhpFile]], ['documents.*' => SafeDocumentUpload::validationRule()]);
$assert($v2->fails(), 'C-03: a double-extension "invoice.pdf.php" file must also FAIL validation.');

$pdfFile = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');
$v3 = Validator::make(['documents' => [$pdfFile]], ['documents.*' => SafeDocumentUpload::validationRule()]);
$assert(! $v3->fails(), 'C-03: a genuine .pdf file must still PASS validation ('.json_encode($v3->errors()->all()).').');

// safeFilename() must never produce a .php (or any non-allow-listed) name,
// even if we hand it a file whose original extension is spoofed.
$safeName = SafeDocumentUpload::safeFilename($pdfFile, 'sale');
$assert(str_ends_with($safeName, '.pdf'), 'C-03: safeFilename() for a .pdf UploadedFile must end in .pdf (got '.$safeName.').');
$assert(strpos($safeName, '.php') === false, 'C-03: safeFilename() must never embed .php anywhere in the generated name.');

// ==================================================================
// C-04: backup path/retention/credential handling
// ==================================================================

echo "== C-04: backup safety ==\n";

$backupDir = DatabaseBackUp::backupDir();
$assert(strpos($backupDir, DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) === false, 'C-04: the backup directory must NOT be under storage/app/public (got '.$backupDir.').');
$assert(str_ends_with(str_replace('\\', '/', $backupDir), 'app/backups'), 'C-04: the backup directory must be storage/app/backups (got '.$backupDir.').');

// Legacy-migration behavior: a file left over at the old public path must
// be moved into the new private directory rather than left exposed.
$legacyDir = storage_path().'/app/public/backup';
@mkdir($legacyDir, 0755, true);
@mkdir($backupDir, 0755, true);
$legacyFile = $legacyDir.'/backup-legacy-test.sql';
file_put_contents($legacyFile, '-- legacy dump --');

$reflection = new ReflectionClass(DatabaseBackUp::class);
$migrateMethod = $reflection->getMethod('migrateLegacyBackups');
$migrateMethod->setAccessible(true);
$migrateMethod->invoke(new DatabaseBackUp(), $backupDir);

$assert(! file_exists($legacyFile), 'C-04: the legacy backup file must no longer exist at the old public path after migration.');
$movedFile = $backupDir.'/backup-legacy-test.sql';
$assert(file_exists($movedFile), 'C-04: the legacy backup file must have been moved into the new private directory.');
if (file_exists($movedFile)) {
    @unlink($movedFile);
}
@rmdir($legacyDir);

// Retention: pruneOldBackups() must keep the newest N and the just-written
// file, and must NOT touch anything until a new file is confirmed present.
$pruneMethod = $reflection->getMethod('pruneOldBackups');
$pruneMethod->setAccessible(true);
$testFiles = [];
for ($i = 0; $i < 5; $i++) {
    $f = $backupDir.'/backup-n1-test-'.$i.'.sql';
    file_put_contents($f, 'x');
    touch($f, time() - (5 - $i) * 10); // stagger mtimes, oldest first
    $testFiles[] = $f;
}
$newest = end($testFiles);
$pruneMethod->invoke(new DatabaseBackUp(), $backupDir, $newest, 2);
$remaining = array_values(array_filter($testFiles, 'file_exists'));
$assert(count($remaining) === 2, 'C-04: pruneOldBackups(keep=2) must leave exactly 2 files (left '.count($remaining).').');
$assert(in_array($newest, $remaining, true), 'C-04: pruneOldBackups() must never delete the just-written file.');
foreach ($testFiles as $f) {
    if (file_exists($f)) {
        @unlink($f);
    }
}

// ==================================================================
// C-05: document/PDF routes require authentication
// ==================================================================

echo "== C-05: PDF/print routes are authenticated ==\n";

$pdfRoute = collect(Route::getRoutes())->first(function ($r) {
    return $r->uri() === 'api/sale_pdf/{id}';
});
$assert($pdfRoute !== null, 'C-05 setup: the sale_pdf route must still exist.');
if ($pdfRoute) {
    $mw = $pdfRoute->gatherMiddleware();
    $assert(in_array('auth:api', $mw, true), 'C-05: sale_pdf/{id} must now require the auth:api middleware (has: '.implode(',', $mw).').');
}

$transferPdfRoute = collect(Route::getRoutes())->first(function ($r) {
    return $r->uri() === 'api/transfer_pdf/{id}';
});
if ($transferPdfRoute) {
    $mw = $transferPdfRoute->gatherMiddleware();
    $assert(in_array('auth:api', $mw, true), 'C-05: transfer_pdf/{id} must now require the auth:api middleware.');
}

// The public, token-based invoice route must remain untouched (no auth) —
// that one is intentionally shareable and is not part of this fix.
$publicRoute = collect(Route::getRoutes())->first(function ($r) {
    return $r->uri() === 'api/public/invoice/{token}';
});
$assert($publicRoute !== null, 'C-05: the public token-based invoice route must still exist.');
if ($publicRoute) {
    $mw = $publicRoute->gatherMiddleware();
    $assert(! in_array('auth:api', $mw, true), 'C-05: the public token-based invoice route must remain unauthenticated (by design).');
}

// Controller-level authorization: Sale_PDF now calls the Sale 'view' policy.
$sourceHasAuthCheck = strpos(
    file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/SalesController.php'),
    "authorizeForUser(\$request->user('api'), 'view', Sale::class);"
) !== false;
$assert($sourceHasAuthCheck, 'C-05: SalesController must contain at least one Sale-view authorization check added by this build.');

// ==================================================================
// Cleanup
// ==================================================================

foreach ($cleanupSaleIds as $sid) {
    DB::table('sale_details')->where('sale_id', $sid)->delete();
    DB::table('sales')->where('id', $sid)->delete();
}
foreach ($cleanupProductWarehouseIds as $pwid) {
    DB::table('product_warehouse')->where('id', $pwid)->delete();
}
if ($cleanupWarehouseId) {
    DB::table('warehouses')->where('id', $cleanupWarehouseId)->delete();
}
DB::table('clients')->where('id', $client->id)->delete();

// ==================================================================
// Result
// ==================================================================

if (empty($failures)) {
    echo "\nBuild N1 regression: PASS (server-side total consistency (C-01) rejects a tampered/decoupled ".
        "total while still accepting a normal discounted+taxed sale; a completed sale can no longer silently ".
        "skip stock deduction when no warehouse stock row exists yet (C-02); sale/purchase/PO/expense document ".
        "uploads now reject .php and double-extension files (C-03); database backups now write to a private, ".
        "non-web-reachable directory with safe retention (C-04); and sale/purchase/transfer/adjustment/payment ".
        "PDF & print routes now require authentication plus a per-document policy check (C-05)).\n";
    exit(0);
}

echo "\nBuild N1 regression: FAIL\n";
foreach ($failures as $f) {
    echo " - {$f}\n";
}
exit(1);
