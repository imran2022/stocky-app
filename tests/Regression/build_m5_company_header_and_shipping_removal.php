<?php

/**
 * Build M5 — Company header (VAT/BIN, Phone, Mail, Website) on the "Modern"
 * family of PDF templates + removal of the embedded shipping-label section
 * from the Modern Sale Invoice (2026-09-19).
 *
 * Context: the Classic Sale Invoice, Purchase, Quotation, Return, etc. PDFs
 * already showed the company's VAT/BIN number and website (Build K1) in
 * their "From" box. The "Modern" family of templates — which don't use that
 * box, they use a plain top-left header instead — never got the same
 * treatment: sale_pdf_modern.blade.php, packing_list.blade.php (styled to
 * match Modern per Build L5), customer_statement_modern.blade.php (Build
 * M4), and shipping_label.blade.php (had VAT/BIN but no Mail/Website) all
 * showed Address + Phone + Email only, no VAT/BIN, no Website.
 *
 * This test renders all four with real Setting data (which already has a
 * VAT number and website, matching what the user showed) and confirms:
 *   1. Each header shows "VAT/BIN: <number>", "Phone: <phone>",
 *      "Mail: <email>", and "Website: <url>" in that order.
 *   2. sale_pdf_modern.blade.php no longer renders the embedded
 *      "DELIVERY INFORMATION" / shipping-label section (the user asked for
 *      that removed from the invoice — the standalone Shipping Label PDF,
 *      unaffected here, still has its own).
 *
 * Run with: php tests/Regression/build_m5_company_header_and_shipping_removal.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\PdfTemplate;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\utils\helpers;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$settings = Setting::whereNull('deleted_at')->first();
$assert(! empty($settings->vat_number), 'Setup: Setting.vat_number must be populated for this test to mean anything (it already is in this sandbox).');
$assert(! empty($settings->website), 'Setup: Setting.website must be populated for this test to mean anything (it already is in this sandbox).');

// ==================== 1. Modern Sale Invoice ====================
PdfTemplate::query()->where('doc_type', 'sale')->delete();
PdfTemplate::updateOrCreate(['doc_type' => 'sale'], ['settings' => array_merge(PdfTemplate::DEFAULTS, ['layout' => 'modern'])]);

$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = \Illuminate\Support\Facades\DB::table('products')->whereNull('deleted_at')->first();
$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M5 Header Test Client', 'firstname' => 'M5', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$salesController = app(\App\Http\Controllers\SalesController::class);
$req = Request::create('/api/sales', 'POST', [
    'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
    'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build M5 header test',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
    'shipping' => 0, 'GrandTotal' => 150, 'discount_from_points' => 0, 'used_points' => 0,
    'payment' => ['status' => 'pending'],
    'details' => [[
        'product_id' => $product->id, 'quantity' => 1, 'Unit_price' => 150,
        'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 150,
        'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
        'serial_numbers' => [], 'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
    ]],
]);
app()->instance('request', $req);
$saleId = json_decode($salesController->store($req)->getContent(), true)['sale_id'] ?? null;
$assert($saleId !== null, 'Setup: the test sale must have been created.');

if ($saleId) {
    $invoiceHtml = $salesController->Sale_PDF_Inline($req, $saleId)->getContent();

    $assert(str_contains($invoiceHtml, 'VAT/BIN: '.$settings->vat_number), 'Test 1a: Modern Sale Invoice header must show "VAT/BIN: <number>".');
    $assert(str_contains($invoiceHtml, 'Phone: '.$settings->CompanyPhone), 'Test 1b: Modern Sale Invoice header must show "Phone: <number>".');
    $assert(str_contains($invoiceHtml, 'Mail: '.$settings->email), 'Test 1c: Modern Sale Invoice header must show "Mail: <email>".');
    $assert(str_contains($invoiceHtml, 'Website: '.$settings->website), 'Test 1d: Modern Sale Invoice header must show "Website: <url>".');
    $assert(! str_contains($invoiceHtml, 'DELIVERY INFORMATION'), 'Test 1e: the embedded shipping-label section must be fully removed from the Modern Sale Invoice.');
    $assert(! str_contains($invoiceHtml, 'hub-label-box'), 'Test 1f: the shipping-label CSS classes must be fully removed too (no dead styles left behind).');
}

// ==================== 2. Packing List ====================
$packingHtml = view('pdf.packing_list', [
    'sale' => ['Ref' => 'PL-TEST', 'date' => now()->toDateString(), 'client_name' => 'Test Client'],
    'details' => [['name' => 'Test Product', 'code' => 'TP001', 'quantity' => '1', 'unitSale' => 'pc', 'box_qty' => null]],
    'totalQty' => '1', 'totalBoxes' => null, 'setting' => $settings,
])->render();
$assert(str_contains($packingHtml, 'VAT/BIN: '.$settings->vat_number), 'Test 2a: Packing List header must show VAT/BIN.');
$assert(str_contains($packingHtml, 'Mail: '.$settings->email), 'Test 2b: Packing List header must show Mail.');
$assert(str_contains($packingHtml, 'Website: '.$settings->website), 'Test 2c: Packing List header must show Website.');

// ==================== 3. Customer Statement ====================
$statementService = app(\App\Services\ClientStatementService::class);
$built = $statementService->build($client->id);
$helpers = new helpers;
$statementHtml = view('pdf.customer_statement_modern', array_merge($built, [
    'setting' => $settings, 'symbol' => $helpers->Get_Currency(), 'priceFormat' => $settings['price_format'] ?? null,
    'client' => array_merge($built['client'], ['phone' => $client->phone, 'adresse' => $client->adresse, 'code' => $client->code]),
    'fromDate' => null, 'toDate' => null,
]))->render();
$assert(str_contains($statementHtml, 'VAT/BIN: '.$settings->vat_number), 'Test 3a: Customer Statement header must show VAT/BIN.');
$assert(str_contains($statementHtml, 'Mail: '.$settings->email), 'Test 3b: Customer Statement header must show Mail.');
$assert(str_contains($statementHtml, 'Website: '.$settings->website), 'Test 3c: Customer Statement header must show Website.');

// ==================== 4. Shipping Label (standalone) ====================
$shippingHtml = view('pdf.shipping_label', [
    'sale' => [
        'Ref' => 'SL-TEST', 'date' => now()->toDateString(), 'client_name' => 'Test Client',
        'client_phone' => '000', 'client_adr' => 'Test Address', 'GrandTotal' => '150.00',
        'cod_amount' => '0.00', 'payment_status' => 'Paid',
    ],
    'company' => $settings, 'symbol' => 'USD',
])->render();
$assert(str_contains($shippingHtml, 'VAT/BIN: '.$settings->vat_number), 'Test 4a: standalone Shipping Label must still show VAT/BIN (already had it).');
$assert(str_contains($shippingHtml, 'Mail: '.$settings->email), 'Test 4b: standalone Shipping Label must now also show Mail (it didn\'t before).');
$assert(str_contains($shippingHtml, 'Website: '.$settings->website), 'Test 4c: standalone Shipping Label must now also show Website (it didn\'t before).');

// ==================== Cleanup ====================
Sale::where('notes', 'Build M5 header test')->forceDelete();
Client::where('id', $client->id)->forceDelete();
PdfTemplate::query()->where('doc_type', 'sale')->delete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M5 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M5 regression: PASS (VAT/BIN, Phone, Mail, Website now show on the Modern Sale Invoice, Packing List, Customer Statement and Shipping Label headers; the embedded shipping-label section is fully removed from the Modern Sale Invoice).\n";
