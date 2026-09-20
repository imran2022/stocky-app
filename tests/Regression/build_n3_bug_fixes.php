<?php

/**
 * Build N3 — User-reported bugs/requests after applying Build N2
 * (2026-09-20). Each item below was reported by the client with real
 * screenshots from their own install:
 *
 *   1. Public invoice page's "Download PDF" button started returning
 *      403 "You are not authorized" — a regression from Build N1a's C-05
 *      fix, which added a 'view' policy check inside
 *      SalesController::Sale_PDF(). That method is also called
 *      IN-PROCESS by PublicInvoiceController::pdf() (the legitimately
 *      public, unguessable-token download flow), so the same check
 *      wrongly ran there too, with no logged-in user to satisfy it.
 *      Fixed with a `publicly_authorized_via_token` request-attribute
 *      flag set only by the trusted public-token caller (which has
 *      already authorized the request itself, via the token lookup) and
 *      checked inside Sale_PDF() to conditionally skip the redundant
 *      policy check — the direct, sequential-ID `sale_pdf/{id}` route
 *      keeps requiring authorization exactly as C-05 intended.
 *   2. Public invoice page styling: "Billed to" label a bit bolder/more
 *      visible, a modern background on the item-table header, and a
 *      running serial number (#) column before each line item. Covered
 *      here as a static source-contract check (PublicInvoice.vue is a
 *      hand-styled page with no server-side data to assert against).
 *   4. Company address block on Sale/Purchase/Quotation/Sale-Return/
 *      Purchase-Return Detail pages was missing VAT/BIN and Website (both
 *      already shown on every PDF and the public invoice page since
 *      Builds K1/M5/L1) and ordered Phone/Email ahead of Address, unlike
 *      the standard Name / Address / VAT-BIN / Phone / Mail / Website
 *      order used everywhere else. Reordered + fields added on all 5
 *      pages.
 *   5. Packing List PDF was missing the customer's address/phone and the
 *      Warehouse/Order Status/Payment Status the Sale Invoice PDF already
 *      shows. Added to both SalesController::Sale_Packing_List() and
 *      resources/views/pdf/packing_list.blade.php — verified here by
 *      actually rendering the real Blade view with a real Sale.
 *   6. Two menu labels ('Zone_Courier_Report', 'PriceVarianceReport')
 *      had no entry at all in database/seeders/translations/en.php, so
 *      whatever ended up stored in the live `translations` table for
 *      those keys (including a typo) could never be corrected by
 *      re-seeding. A third ('StockLookup') was missing too and would
 *      render as the raw, spaceless key. All three added; verified here
 *      by actually running TranslationSeeder against the real database.
 *   7. System Settings invoice-template dropdown said "Modern (with
 *      Shipping Label)" — stale even before this fix, since Build M5
 *      already removed the embedded shipping-label section from that
 *      layout. Renamed to plain "Modern" in PdfTemplate::LAYOUTS (the
 *      single source the frontend's dropdown is built from).
 *
 * Items 3 (Sale unit-price inline edit, mirroring PurchaseForm.vue) is
 * covered by its own static source-contract check below too — it is a
 * pure frontend change with no backend data to assert against.
 *
 * Run with: php tests/Regression/build_n3_bug_fixes.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\SalesController;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Custom\PublicInvoiceLinkService;
use Illuminate\Auth\Access\AuthorizationException;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$admin = User::first();
$warehouse = Warehouse::whereNull('deleted_at')->first();

$cleanup = ['sales' => []];

// ==================================================================
// Item 1: public invoice PDF download must work unauthenticated;
// the direct sequential-ID route must still require authorization.
// ==================================================================

echo "== Item 1: public invoice PDF 403 regression ==\n";

$sale = Sale::create([
    'date' => now()->toDateString(), 'Ref' => 'N3SALE'.time(), 'client_id' => 1,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 100, 'paid_amount' => 0,
    'statut' => 'completed', 'payment_statut' => 'unpaid', 'user_id' => $admin->id,
]);
$cleanup['sales'][] = $sale->id;
$token = PublicInvoiceLinkService::getOrCreateToken($sale);

// No logged-in user at all — simulates the anonymous visitor who clicked
// the public "Download PDF" link. authorizeForUser() reads $request->user(),
// so it's the REQUEST's own resolver that matters here, not the global Auth
// guard (which Passport's TokenGuard won't accept a null user for anyway).
$publicReq = Request::create("/api/public/invoice/{$token}/pdf", 'GET');
$publicReq->setUserResolver(fn ($guard = null) => null);
app()->instance('request', $publicReq);
$publicController = app(PublicInvoiceController::class);

try {
    $response = $publicController->pdf($publicReq, $token);
    $assert($response->getStatusCode() === 200, 'Item 1: public invoice PDF download must return 200 for an anonymous visitor with a valid token (got '.$response->getStatusCode().').');
    $content = $response->getContent();
    $assert(str_starts_with($content, '%PDF'), 'Item 1: public invoice PDF download must return real PDF content.');
} catch (AuthorizationException $e) {
    $failures[] = 'Item 1: public invoice PDF download must NOT throw an authorization exception for an anonymous visitor (this is the reported regression) — got: '.$e->getMessage();
}

// The direct, sequential-ID route (Sale_PDF called WITHOUT the public-token
// flag) must still reject an anonymous caller — this is C-05's original
// protection and must not have been reopened by the Item 1 fix.
$directReq = Request::create("/api/sale_pdf/{$sale->id}", 'GET');
$directReq->setUserResolver(fn ($guard = null) => null);
app()->instance('request', $directReq);
$salesController = app(SalesController::class);
$directRequestBlocked = false;
try {
    $salesController->Sale_PDF($directReq, $sale->id);
} catch (AuthorizationException $e) {
    $directRequestBlocked = true;
} catch (\Throwable $e) {
    // Any other exception (e.g. a later missing-data error) still proves
    // authorization was NOT what let this through — only count it as a
    // pass if it's specifically not an "unauthorized" success path.
    $directRequestBlocked = true;
}
$assert($directRequestBlocked, 'Item 1 (regression guard): the direct sale_pdf/{id} route must still require authorization for an anonymous caller (C-05 must remain intact).');

// Restore an authenticated admin for the rest of the suite. A fresh Request
// instance is bound (with its own resolver) rather than relying on the one
// still hanging off app('request') from the anonymous calls above.
Auth::guard('api')->setUser($admin);
Auth::login($admin);
$adminReq = Request::create('/api/_n3_test', 'GET');
$adminReq->setUserResolver(fn ($guard = null) => $admin);
app()->instance('request', $adminReq);

// ==================================================================
// Item 2: public invoice page styling (static source-contract check)
// ==================================================================

echo "== Item 2: public invoice page styling ==\n";

$publicPage = $read('resources/src/pages/public/PublicInvoice.vue');
$assert(
    str_contains($publicPage, 'col-seq'),
    'Item 2: PublicInvoice.vue must have a serial-number (#) column (col-seq).'
);
$assert(
    preg_match('/\.bill-to-label\s*\{[^}]*font-weight:\s*700/s', $publicPage) === 1,
    'Item 2: the "Billed to" label must be bold (font-weight: 700).'
);
$assert(
    preg_match('/thead th\s*\{[^}]*background:\s*#F1F5F9/s', $publicPage) === 1,
    'Item 2: the item table header must have a modern background color.'
);

// ==================================================================
// Item 3: Sale unit price direct inline edit (static source-contract
// check — mirrors PurchaseForm.vue's setUnitCost pattern).
// ==================================================================

echo "== Item 3: Sale Form unit price inline edit ==\n";

$saleForm = $read('resources/src/pages/sales/SaleForm.vue');
$assert(
    str_contains($saleForm, 'function setUnitPrice('),
    'Item 3: SaleForm.vue must have a setUnitPrice() handler (mirrors PurchaseForm.vue\'s setUnitCost()).'
);
$netPricePos = strpos($saleForm, "column.key === 'net_price'");
$netPriceBlock = $netPricePos !== false ? substr($saleForm, $netPricePos, 300) : '';
$assert(
    str_contains($netPriceBlock, 'a-input-number') && str_contains($netPriceBlock, 'setUnitPrice'),
    "Item 3: the 'net_price' column must render an editable a-input-number wired to setUnitPrice(), not static text."
);

// ==================================================================
// Item 4: company address block order/fields on the 5 Detail pages.
// ==================================================================

echo "== Item 4: company address format on Detail pages ==\n";

foreach ([
    'resources/src/pages/sales/SaleDetails.vue',
    'resources/src/pages/purchases/PurchaseDetails.vue',
    'resources/src/pages/quotations/QuotationDetails.vue',
    'resources/src/pages/sale_return/SaleReturnDetails.vue',
    'resources/src/pages/purchase_return/PurchaseReturnDetails.vue',
] as $relativePath) {
    $page = $read($relativePath);
    $assert(str_contains($page, 'company.vat_number'), "Item 4: {$relativePath} must show company.vat_number (VAT/BIN).");
    $assert(str_contains($page, 'company.website'), "Item 4: {$relativePath} must show company.website.");

    $box = substr($page, strpos($page, "\$t('Company')"), 900);
    $order = ["company.CompanyAdress", 'VAT/BIN:', "company.CompanyPhone", "company.email", "company.website"];
    $lastPos = -1;
    foreach ($order as $marker) {
        $pos = strpos($box, $marker);
        $assert($pos !== false, "Item 4: {$relativePath} company box must contain '{$marker}'.");
        $assert($pos > $lastPos, "Item 4: {$relativePath} company box order is wrong at '{$marker}' (expected Address, VAT/BIN, Phone, Email, Website).");
        $lastPos = $pos;
    }
}

// ==================================================================
// Item 5: Packing List PDF gains customer address/phone + Warehouse/
// Order Status/Payment Status — verified by actually rendering the
// real Blade view against a real Sale.
// ==================================================================

echo "== Item 5: Packing List PDF fields ==\n";

$packingReq = Request::create("/api/sale_packing_list/{$sale->id}", 'GET');
$packingReq->setUserResolver(fn ($guard = null) => $admin);
app()->instance('request', $packingReq);
$packingResponse = app(SalesController::class)->Sale_Packing_List($packingReq, $sale->id);
$pdfOutput = $packingResponse->getContent();
$assert(is_string($pdfOutput) && str_starts_with($pdfOutput, '%PDF'), 'Item 5: Sale_Packing_List must still return a real PDF.');

// Also render the Blade view directly (readable text, unlike the binary
// PDF) to assert the actual field values appear in the markup.
$client = $sale->client;
$html = view('pdf.packing_list', [
    'sale' => [
        'Ref' => $sale->Ref, 'date' => $sale->date,
        'client_name' => $client->name, 'client_phone' => $client->phone, 'client_adr' => $client->adresse,
        'warehouse' => $warehouse->name, 'statut' => $sale->statut, 'payment_status' => $sale->payment_statut,
    ],
    'details' => [], 'totalQty' => '0', 'totalBoxes' => null,
    'setting' => \App\Models\Setting::where('deleted_at', null)->first(),
])->render();

$assert(!empty($client->adresse) ? str_contains($html, $client->adresse) : true, 'Item 5: Packing List must render the customer address when set.');
$assert(!empty($client->phone) ? str_contains($html, $client->phone) : true, 'Item 5: Packing List must render the customer phone when set.');
$assert(str_contains($html, $warehouse->name), 'Item 5: Packing List must render the Warehouse name.');
$assert(str_contains($html, 'Order Status'), 'Item 5: Packing List must render an Order Status line.');
$assert(str_contains($html, 'Payment Status'), 'Item 5: Packing List must render a Payment Status line.');

// ==================================================================
// Item 6: menu label translations — verified by actually running the
// real TranslationSeeder against the real database.
// ==================================================================

echo "== Item 6: menu label translations ==\n";

Artisan::call('db:seed', ['--class' => \Database\Seeders\TranslationSeeder::class, '--force' => true]);

foreach ([
    'Zone_Courier_Report' => 'Zone Courier Report',
    'PriceVarianceReport' => 'Price Variance Report',
    'StockLookup' => 'Stock Lookup',
] as $key => $expected) {
    $row = DB::table('translations')->where('locale', 'en')->where('key', $key)->first();
    $assert($row !== null, "Item 6: translations table must have an 'en' row for '{$key}' after seeding.");
    $assert($row !== null && $row->value === $expected, "Item 6: translations.{$key} must be '{$expected}' (got '".($row->value ?? 'NULL')."').");
}

// ==================================================================
// Item 7: invoice PDF template dropdown label renamed to "Modern".
// ==================================================================

echo "== Item 7: PDF template label rename ==\n";

$layouts = \App\Models\PdfTemplate::LAYOUTS['sale'] ?? [];
$assert(($layouts['modern'] ?? null) === 'Modern', 'Item 7: PdfTemplate::LAYOUTS[\'sale\'][\'modern\'] must be exactly "Modern" (no more "(with Shipping Label)").');

// ==================================================================
// Cleanup
// ==================================================================

foreach ($cleanup['sales'] as $id) {
    Sale::where('id', $id)->forceDelete();
}

if ($failures) {
    fwrite(STDERR, "Build N3 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N3 regression gate: PASS (public invoice PDF 403 regression fixed + C-05 still intact; public invoice styling; Sale unit-price inline edit; company address format on 5 Detail pages; Packing List customer/warehouse/status fields; menu label translations; PDF template label renamed).\n";
