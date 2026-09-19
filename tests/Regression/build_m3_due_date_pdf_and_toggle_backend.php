<?php

/**
 * Build M3 — enable_payment_terms toggle (backend) + Due Date on the real
 * rendered invoice PDF, with its own show/hide toggle (2026-09-19).
 *
 * Companion to Build M2's JS/static checks. This is a real, DB-backed test
 * that boots the actual Laravel app and:
 *   1. Confirms store() skips Payment Terms resolution entirely (leaves
 *      payment_term_days/due_date both null) when enable_payment_terms is
 *      OFF, and resolves normally again once it's back ON.
 *   2. Renders the REAL invoice HTML (SalesController::Sale_PDF_Inline,
 *      the same template used by the downloadable PDF) for a sale with a
 *      due date and confirms the Due Date line appears, shows "Overdue"
 *      when applicable, and disappears when the new
 *      PdfTemplate show_due_date toggle is turned off — mirroring the
 *      existing "Previous Dues" toggle exactly.
 *
 * Run with: php tests/Regression/build_m3_due_date_pdf_and_toggle_backend.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\SalesController;
use App\Models\Client;
use App\Models\PdfTemplate;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

/**
 * PdfTemplate::settingsFor() caches its result in a `static $cache` local
 * to that method for the lifetime of the PHP process — correct and cheap
 * in real usage (one process per HTTP request under PHP-FPM/Apache). This
 * test makes several "requests" for the SAME doc_type in a row inside ONE
 * process, so steps that change the PDF template settings and need to see
 * that change take effect are run in a fresh `php` subprocess instead —
 * exactly like a real second HTTP request would see it.
 */
function renderSaleInlineInFreshProcess(int $saleId): string
{
    $root = dirname(__DIR__, 2);
    $php = <<<PHP
        require '{$root}/vendor/autoload.php';
        \$app = require '{$root}/bootstrap/app.php';
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        \$admin = App\Models\User::first();
        Illuminate\Support\Facades\Auth::guard('api')->setUser(\$admin);
        Illuminate\Support\Facades\Auth::login(\$admin);
        app('request')->setUserResolver(fn (\$g = null) => \$admin);
        \$req = Illuminate\Http\Request::create('/api/sales/{$saleId}/pdf-inline', 'GET');
        app()->instance('request', \$req);
        \$controller = app(App\Http\Controllers\SalesController::class);
        echo \$controller->Sale_PDF_Inline(\$req, {$saleId})->getContent();
        PHP;
    $tmpFile = tempnam(sys_get_temp_dir(), 'm3_render_').'.php';
    file_put_contents($tmpFile, "<?php\n".$php);
    $output = shell_exec('php '.escapeshellarg($tmpFile).' 2>&1');
    unlink($tmpFile);

    return $output ?? '';
}

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$warehouse = Warehouse::whereNull('deleted_at')->first();
$settingsRow = Setting::whereNull('deleted_at')->first();
$originalEnablePaymentTerms = $settingsRow->enable_payment_terms;
$originalDefaultDays = $settingsRow->default_payment_term_days;

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M3 Test Client', 'firstname' => 'M3', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$salesController = app(SalesController::class);
$product = DB::table('products')->whereNull('deleted_at')->first();

function m3SaleRequest($client, $warehouse, $product, array $overrides = []): Request
{
    $base = [
        'client_id' => $client->id,
        'warehouse_id' => $warehouse->id,
        'date' => now()->toDateString(),
        'statut' => 'pending',
        'notes' => 'Build M3 test',
        'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
        'shipping' => 0, 'GrandTotal' => 100,
        'discount_from_points' => 0, 'used_points' => 0,
        'payment' => ['status' => 'pending'],
        'details' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'Unit_price' => 100,
            'tax_percent' => 0,
            'tax_method' => '1',
            'subtotal' => 100,
            'discount' => 0,
            'discount_Method' => '2',
            'product_variant_id' => null,
            'serial_numbers' => [],
            'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
        ]],
    ];
    $req = Request::create('/api/sales', 'POST', array_merge($base, $overrides));
    app()->instance('request', $req);

    return $req;
}

// ==================== 1. Toggle OFF -> no term/due date at all ====================
Setting::whereNull('deleted_at')->update(['enable_payment_terms' => false, 'default_payment_term_days' => 10]);
$reqOff = m3SaleRequest($client, $warehouse, $product);
$respOff = $salesController->store($reqOff);
$saleIdOff = json_decode($respOff->getContent(), true)['sale_id'] ?? null;
$saleOff = $saleIdOff ? Sale::find($saleIdOff) : null;
$assert($saleOff !== null, 'Test 1: sale should have been created even with the feature off.');
if ($saleOff) {
    $assert($saleOff->payment_term_days === null, "Test 1: with enable_payment_terms OFF, payment_term_days must stay null (no silent resolution), got {$saleOff->payment_term_days}.");
    $assert($saleOff->due_date === null, "Test 1: with enable_payment_terms OFF, due_date must stay null, got {$saleOff->due_date}.");
}

// ==================== 2. Toggle back ON -> resolves normally again ====================
Setting::whereNull('deleted_at')->update(['enable_payment_terms' => true]);
$reqOn = m3SaleRequest($client, $warehouse, $product);
$respOn = $salesController->store($reqOn);
$saleIdOn = json_decode($respOn->getContent(), true)['sale_id'] ?? null;
$saleOn = $saleIdOn ? Sale::find($saleIdOn) : null;
$assert($saleOn !== null, 'Test 2: sale should have been created.');
if ($saleOn) {
    $assert((int) $saleOn->payment_term_days === 10, "Test 2: with the feature back ON, the system default (10) should resolve normally, got {$saleOn->payment_term_days}.");
    $assert($saleOn->due_date !== null, 'Test 2: due_date should be set again once the feature is back on.');
}

// ==================== 3. Real rendered invoice HTML: Due Date line ====================
if ($saleOn) {
    // Back-date so it's overdue, matching the "Overdue" badge path.
    $saleOn->due_date = now()->subDays(3)->toDateString();
    $saleOn->save();

    PdfTemplate::query()->where('doc_type', 'sale')->delete(); // reset to defaults (show_due_date = true)
    $html = renderSaleInlineInFreshProcess($saleOn->id);

    $assert(str_contains($html, 'Due Date'), 'Test 3a: the rendered invoice HTML must contain a "Due Date" line when the sale has a due date and the toggle is on.');
    $assert(str_contains($html, $saleOn->due_date), "Test 3b: the rendered invoice HTML must show the actual due date value ({$saleOn->due_date}).");
    $assert(str_contains($html, 'Overdue'), 'Test 3c: the rendered invoice HTML must flag the line as Overdue for a backdated due date with an outstanding balance.');
}

// ==================== 4. Turning the PDF-level toggle off hides the line ====================
if ($saleOn) {
    PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['show_due_date' => false])]);
    $html2 = renderSaleInlineInFreshProcess($saleOn->id);
    $assert(! str_contains($html2, 'Due Date'), 'Test 4: turning off the "Show Due Date" PDF setting must remove the line from the rendered invoice, exactly like the existing Previous Dues toggle.');

    // Restore the PDF template row to defaults so this test leaves no trace.
    PdfTemplate::query()->where('doc_type', 'sale')->delete();
}

// ==================== 5. A sale with no due date at all never shows the line ====================
Setting::whereNull('deleted_at')->update(['enable_payment_terms' => false]);
$reqNone = m3SaleRequest($client, $warehouse, $product);
$respNone = $salesController->store($reqNone);
$saleIdNone = json_decode($respNone->getContent(), true)['sale_id'] ?? null;
if ($saleIdNone) {
    $html3 = renderSaleInlineInFreshProcess((int) $saleIdNone);
    $assert(! str_contains($html3, 'Due Date'), 'Test 5: a sale with no due date at all (feature was off when it was created) must not show a Due Date line, even with the PDF toggle on.');
}

// ==================== Cleanup ====================
Sale::where('notes', 'Build M3 test')->forceDelete();
Client::where('id', $client->id)->forceDelete();
Setting::whereNull('deleted_at')->update([
    'enable_payment_terms' => $originalEnablePaymentTerms,
    'default_payment_term_days' => $originalDefaultDays,
]);
PdfTemplate::query()->where('doc_type', 'sale')->delete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M3 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M3 regression: PASS (enable_payment_terms toggle correctly skips/resumes resolution, and the real rendered invoice HTML shows/hides the Due Date + Overdue line exactly as configured).\n";
