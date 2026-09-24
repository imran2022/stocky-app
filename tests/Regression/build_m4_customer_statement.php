<?php

/**
 * Build M4 — Customer Statement (admin) (2026-09-19).
 *
 * Real, DB-backed test covering:
 *   1. App\Services\ClientStatementService::build() produces a correctly
 *      ordered, correctly-summed running-balance ledger for a client with an
 *      opening balance, a completed sale, and a payment.
 *   2. The customer portal (PortalStatementController::index(), refactored to
 *      call the same service) and the new admin endpoint
 *      (ClientStatementController::index()) return IDENTICAL entries and
 *      closing_balance for the same client — proving the "single source of
 *      truth" claim: they can no longer drift apart.
 *   3. The new PDF template (resources/views/pdf/customer_statement_modern.
 *      blade.php) renders real data without error and shows the client name,
 *      the sale's Ref, and the correct closing balance.
 *   4. App\Exports\ClientStatementExport (used for the "Download Excel"
 *      button) maps entries to the expected row shape.
 *   5. ClientController::show() (GET clients/{id}) already returns the
 *      client's `adresse` field — confirming the Customer Details page's
 *      "Address" fix only needed a template change, not a backend one.
 *
 * Run with: php tests/Regression/build_m4_customer_statement.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Exports\ClientStatementExport;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientStatementController;
use App\Http\Controllers\Api\Portal\PortalStatementController;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Models\PortalClient;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ClientStatementService;

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

$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = \Illuminate\Support\Facades\DB::table('products')->whereNull('deleted_at')->first();

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M4 Statement Client', 'firstname' => 'M4', 'lastname' => 'Client',
    'code' => (string) (7770000 + $nextClientCode),
    'opening_balance' => 500, // customer owes 500 to start
    'adresse' => '221B Baker Street, Test City',
    'phone' => '+1-555-0100',
    'email' => 'm4stmt@example.test',
]);

// ==================== 1. ClientController::show() returns `adresse` ====================
$showResp = (new ClientController)->show($client->id);
$showData = json_decode($showResp->getContent(), true);
$assert(($showData['client']['adresse'] ?? null) === '221B Baker Street, Test City', 'Test 1: GET clients/{id} (ClientController::show) must include the client\'s adresse field — the Customer Details Address fix relies on this already being there.');

// ==================== Build a real sale + payment for this client ====================
$salesController = app(\App\Http\Controllers\SalesController::class);
$req = Request::create('/api/sales', 'POST', [
    'client_id' => $client->id,
    'warehouse_id' => $warehouse->id,
    'date' => now()->toDateString(),
    'statut' => 'completed',
    'notes' => 'Build M4 statement test sale',
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
    'shipping' => 0, 'GrandTotal' => 300,
    'discount_from_points' => 0, 'used_points' => 0,
    'payment' => ['status' => 'pending'],
    'details' => [[
        'product_id' => $product->id,
        'quantity' => 1,
        'Unit_price' => 300,
        'tax_percent' => 0,
        'tax_method' => '1',
        'subtotal' => 300,
        'discount' => 0,
        'discount_Method' => '2',
        'product_variant_id' => null,
        'serial_numbers' => [],
        'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
    ]],
]);
app()->instance('request', $req);
$saleResp = $salesController->store($req);
$saleId = json_decode($saleResp->getContent(), true)['sale_id'] ?? null;
$assert($saleId !== null, 'Setup: the test sale must have been created.');

// A partial payment against that sale (100 of the 300).
if ($saleId) {
    PaymentSale::create([
        'sale_id' => $saleId,
        'montant' => 100,
        'date' => now()->toDateString(),
        'Ref' => 'M4-PMT-TEST',
        'user_id' => $admin->id,
    ]);
    // Reflect the payment on the sale row itself, matching how the real payment flow updates it.
    Sale::where('id', $saleId)->update(['paid_amount' => 100, 'payment_statut' => 'Partial']);
}

// ==================== 2. ClientStatementService::build() — shape + math ====================
$service = app(ClientStatementService::class);
$built = $service->build($client->id);

$assert(($built['client']['name'] ?? null) === 'Build M4 Statement Client', 'Test 2a: the statement must identify the correct client.');
$assert((float) $built['opening_balance'] === 500.0, "Test 2b: opening_balance must equal the client's opening_balance (500), got {$built['opening_balance']}.");
// Expected closing balance: 500 (opening) + 300 (invoice) - 100 (payment) = 700.
$assert(abs((float) $built['closing_balance'] - 700.0) < 0.01, "Test 2c: closing_balance must be 500 + 300 - 100 = 700, got {$built['closing_balance']}.");

$entryTypes = array_column($built['entries'], 'type');
$assert(in_array('opening', $entryTypes, true), 'Test 2d: entries must include an "opening" row for the non-zero opening balance.');
$assert(in_array('invoice', $entryTypes, true), 'Test 2e: entries must include an "invoice" row for the completed sale.');
$assert(in_array('payment', $entryTypes, true), 'Test 2f: entries must include a "payment" row for the sale payment.');

// Running balance must never be computed out of order: the LAST entry's
// balance must equal the closing_balance the service reports separately.
$lastEntry = end($built['entries']);
$assert(abs((float) $lastEntry['balance'] - (float) $built['closing_balance']) < 0.01, 'Test 2g: the last entry\'s running balance must equal the reported closing_balance.');

// ==================== 3. Portal and Admin agree exactly (single source of truth) ====================
$portalClient = new PortalClient(['client_id' => $client->id, 'status' => 1]);
Auth::guard('portal')->setUser($portalClient);
$portalReq = Request::create('/api/portal/statement', 'GET');
$portalResp = (new PortalStatementController)->index($portalReq, $service);
$portalData = json_decode($portalResp->getContent(), true);

$adminReq = Request::create("/api/clients/{$client->id}/statement", 'GET');
app()->instance('request', $adminReq);
$adminResp = (new ClientStatementController)->index($adminReq, $client->id, $service);
$adminData = json_decode($adminResp->getContent(), true);

$assert($portalData['closing_balance'] === $adminData['closing_balance'], 'Test 3a: portal and admin closing_balance must match exactly (same service, same data).');
$assert(count($portalData['entries']) === count($adminData['entries']), 'Test 3b: portal and admin must return the same number of ledger entries.');
$assert(json_encode(array_column($portalData['entries'], 'ref')) === json_encode(array_column($adminData['entries'], 'ref')), 'Test 3c: portal and admin must return entries in the same order with the same refs.');

// ==================== 4. PDF template renders real data ====================
$settings = \App\Models\Setting::whereNull('deleted_at')->first();
$helpers = new \App\utils\helpers;
$pdfHtml = view('pdf.customer_statement_modern', array_merge($built, [
    'setting' => $settings,
    'symbol' => $helpers->Get_Currency(),
    'priceFormat' => $settings['price_format'] ?? null,
    'client' => array_merge($built['client'], [
        'phone' => $client->phone, 'adresse' => $client->adresse, 'code' => $client->code,
    ]),
    'fromDate' => null,
    'toDate' => null,
]))->render();

$assert(str_contains($pdfHtml, 'Build M4 Statement Client'), 'Test 4a: the rendered statement PDF must show the client name.');
$assert(str_contains($pdfHtml, 'ACCOUNT STATEMENT'), 'Test 4b: the rendered statement PDF must show the "ACCOUNT STATEMENT" title.');
$assert(str_contains($pdfHtml, '700.00'), 'Test 4c: the rendered statement PDF must show the correct closing balance (700.00).');
$assert(str_contains($pdfHtml, '221B Baker Street'), 'Test 4d: the rendered statement PDF must show the client\'s address.');
$assert(! str_contains($pdfHtml, (string) $client->code), 'Test 4e: the rendered statement PDF must NOT show the customer code (removed per feedback).');
$assert(! str_contains($pdfHtml, 'm4stmt@example.test'), 'Test 4f: the rendered statement PDF must NOT show the customer\'s email (removed per feedback).');

// ==================== 5. Excel export row mapping ====================
$export = new ClientStatementExport($built['entries'], [
    'client_name' => $built['client']['name'],
    'period' => 'All time',
    'opening_balance' => number_format((float) $built['opening_balance'], 2),
    'closing_balance' => number_format((float) $built['closing_balance'], 2),
]);
$headingRows = $export->headings();
$assert($headingRows[count($headingRows) - 1] === ['Date', 'Type', 'Ref', 'Description', 'Debit', 'Credit', 'Balance'], 'Test 5a: the Excel export\'s column header row must match the on-screen table.');
$invoiceEntry = collect($built['entries'])->firstWhere('type', 'invoice');
$mappedInvoice = $export->map($invoiceEntry);
$assert($mappedInvoice[1] === 'Invoice', 'Test 5b: the Excel export must render a human label ("Invoice") for the invoice row type, not the raw "invoice" key.');
$assert($mappedInvoice[4] === '300.00', "Test 5c: the Excel export's Debit column must show the invoice amount, got '{$mappedInvoice[4]}'.");

// ==================== 6. Real generated .xlsx has the bold Closing Balance total row ====================
$xlsxTmpBase = tempnam(sys_get_temp_dir(), 'm4_stmt_');
unlink($xlsxTmpBase); // tempnam() already creates this bare (extension-less) file — not the one we write to.
$xlsxPath = $xlsxTmpBase.'.xlsx';
\Maatwebsite\Excel\Facades\Excel::store($export, basename($xlsxPath), null, null, ['path' => sys_get_temp_dir()]);
// Maatwebsite\Excel::store() writes relative to the configured local disk root
// (usually storage/app); resolve wherever it actually landed before reading it back.
$storedRelative = basename($xlsxPath);
$possiblePaths = [
    $xlsxPath,
    storage_path('app/'.$storedRelative),
    storage_path('app/public/'.$storedRelative),
];
$actualPath = null;
foreach ($possiblePaths as $candidate) {
    if (file_exists($candidate)) {
        $actualPath = $candidate;
        break;
    }
}
if ($actualPath) {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($actualPath);
    $sheet = $spreadsheet->getActiveSheet();
    $lastDataRow = 7 + count($built['entries']);
    $totalRow = $lastDataRow + 1;
    $assert($sheet->getCell("A{$totalRow}")->getValue() === 'Closing Balance', "Test 6a: the generated .xlsx must have a 'Closing Balance' label on row {$totalRow}, got '".$sheet->getCell("A{$totalRow}")->getValue()."'.");
    $assert($sheet->getCell("A{$totalRow}")->getStyle()->getFont()->getBold() === true, 'Test 6b: the Closing Balance total row must be bold.');
    $totalCellValue = $sheet->getCell("G{$totalRow}")->getValue();
    $assert(abs((float) $totalCellValue - (float) $built['closing_balance']) < 0.01, "Test 6c: the total row's balance figure must equal the computed closing balance (700), got '{$totalCellValue}'.");
    @unlink($actualPath);
} else {
    $assert(false, 'Test 6: could not locate the generated .xlsx file to verify the total row (checked: '.implode(', ', $possiblePaths).').');
}
@unlink($xlsxPath);

// ==================== Cleanup ====================
\Illuminate\Support\Facades\DB::table('payment_sales')->where('Ref', 'M4-PMT-TEST')->delete();
Sale::where('notes', 'Build M4 statement test sale')->forceDelete();
Client::where('id', $client->id)->forceDelete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M4 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M4 regression: PASS (statement service math + ordering correct, portal and admin agree exactly, PDF template renders real data, Excel export maps rows correctly, Address field confirmed present in clients/{id}).\n";
