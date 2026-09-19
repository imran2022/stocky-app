<?php

/**
 * Build M8 — Invoice Receivables Report (2026-09-19).
 *
 * A new, dedicated invoice-wise receivables report (Reports > Invoice
 * Receivables Report): one row per completed Sale showing Invoice Total,
 * Sales Return Amount, Net Invoice Amount (= Invoice Total - Return),
 * Paid Amount, Remaining Receivable (= Net - Paid, floored at 0), Due
 * Date, Overdue Days, and a derived 4-state Payment Status
 * (Paid/Partial/Due/Overdue) — plus summary cards and Date/Customer/
 * Status/"Show Outstanding Only" filters, per the user's spec.
 *
 * Reuses existing infrastructure rather than reinventing it:
 *   - app/Support/PaymentTerms.php (Build M1) for due-date/overdue logic —
 *     sales.due_date is already snapshotted per-invoice.
 *   - app/Support/SaleDocumentMath::outstanding() for the floored-at-0
 *     Net-minus-Paid subtraction.
 *   - The Build D2 / ClientStatementService precedent that only
 *     sale_returns rows with statut='received' count as an actual
 *     reduction in what's owed (a 'pending' return must NOT reduce Net
 *     Invoice Amount).
 *
 * This test creates 5 real sales (via the real SalesController::store()
 * flow) on one dedicated test client, force-sets each one's `paid_amount`/
 * `due_date` (and creates real SaleReturn rows against two of them) to hit
 * every one of the 4 status states plus the pending-return-exclusion rule,
 * then calls ReportController::Report_InvoiceReceivables() directly and
 * asserts every computed field against hand-worked expected values.
 * Filtering by this test's own client_id isolates it from the sandbox's
 * other "today"-dated fixture sales, so no delta-baseline dance is needed.
 *
 * Run with: php tests/Regression/build_m8_invoice_receivables_report.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesController;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
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

$reportController = app(ReportController::class);
$salesController = app(SalesController::class);
$warehouse = Warehouse::whereNull('deleted_at')->first();
$product = DB::table('products')->whereNull('deleted_at')->first();

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M8 Test Client', 'firstname' => 'M8', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$makeSale = static function (float $grandTotal) use ($salesController, $client, $warehouse, $product) {
    $req = Request::create('/api/sales', 'POST', [
        'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
        'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build M8 invoice receivables test',
        'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
        'shipping' => 0, 'GrandTotal' => $grandTotal, 'discount_from_points' => 0, 'used_points' => 0,
        'payment' => ['status' => 'pending'],
        'details' => [[
            'product_id' => $product->id, 'quantity' => 1, 'Unit_price' => $grandTotal,
            'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => $grandTotal,
            'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
            'serial_numbers' => [], 'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
        ]],
    ]);
    app()->instance('request', $req);

    return json_decode($salesController->store($req)->getContent(), true)['sale_id'] ?? null;
};

// ==================== Build 5 test invoices, one per status ====================

// A) Fully paid, with a RECEIVED return that reduces what was owed.
//    Invoice 1000, return(received) 100 -> Net 900, paid 900 -> Remaining 0 -> paid.
$idPaid = $makeSale(1000);
$assert($idPaid !== null, 'Setup: Sale A (paid) must have been created.');
if ($idPaid) {
    SaleReturn::create([
        'date' => now()->toDateString(), 'Ref' => 'M8-RET-A', 'GrandTotal' => 100,
        'user_id' => $admin->id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
        'sale_id' => $idPaid, 'statut' => 'received', 'payment_statut' => 'unpaid',
    ]);
    Sale::where('id', $idPaid)->update(['paid_amount' => 900, 'due_date' => Carbon::today()->addDays(10)->toDateString()]);
}

// B) Partially paid, not yet due.
//    Invoice 1000, no return, paid 400 -> Remaining 600 -> due_date in the future -> partial.
$idPartial = $makeSale(1000);
$assert($idPartial !== null, 'Setup: Sale B (partial) must have been created.');
if ($idPartial) {
    Sale::where('id', $idPartial)->update(['paid_amount' => 400, 'due_date' => Carbon::today()->addDays(10)->toDateString()]);
}

// C) Nothing paid, due date already passed -> overdue, with a known day count.
//    Invoice 500, no payment, due_date 6 days ago -> overdue, overdue_days = 6.
$idOverdue = $makeSale(500);
$assert($idOverdue !== null, 'Setup: Sale C (overdue) must have been created.');
if ($idOverdue) {
    Sale::where('id', $idOverdue)->update(['paid_amount' => 0, 'due_date' => Carbon::today()->subDays(6)->toDateString()]);
}

// D) Nothing paid, not yet due -> due.
$idDue = $makeSale(300);
$assert($idDue !== null, 'Setup: Sale D (due) must have been created.');
if ($idDue) {
    Sale::where('id', $idDue)->update(['paid_amount' => 0, 'due_date' => Carbon::today()->addDays(5)->toDateString()]);
}

// E) A PENDING (not yet 'received') return must NOT reduce Net Invoice Amount.
//    Invoice 200, return(pending) 50 -> Net must stay 200, not 150.
$idPendingReturn = $makeSale(200);
$assert($idPendingReturn !== null, 'Setup: Sale E (pending return) must have been created.');
if ($idPendingReturn) {
    SaleReturn::create([
        'date' => now()->toDateString(), 'Ref' => 'M8-RET-E', 'GrandTotal' => 50,
        'user_id' => $admin->id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id,
        'sale_id' => $idPendingReturn, 'statut' => 'pending', 'payment_statut' => 'unpaid',
    ]);
    Sale::where('id', $idPendingReturn)->update(['paid_amount' => 0, 'due_date' => Carbon::today()->addDays(5)->toDateString()]);
}

// ==================== Fetch the report, scoped to this test's client ====================
$req = Request::create('/api/report/invoice_receivables', 'GET', ['client_id' => $client->id]);
app()->instance('request', $req);
$res = json_decode($reportController->Report_InvoiceReceivables($req)->getContent(), true);

$rowsById = collect($res['invoices'] ?? [])->keyBy('id');

$assert(($res['totalRows'] ?? 0) === 5, 'Test 0: exactly the 5 test invoices must be returned when scoped to this client (no leakage from other fixtures).');

// -------- A) paid --------
$a = $rowsById[$idPaid] ?? null;
$assert($a !== null, 'Test A: paid invoice must be present.');
if ($a) {
    $approx($a['invoice_total'], 1000, 'Test A: invoice_total');
    $approx($a['return_amount'], 100, 'Test A: return_amount (received return counted)');
    $approx($a['net_invoice'], 900, 'Test A: net_invoice = 1000 - 100');
    $approx($a['paid_amount'], 900, 'Test A: paid_amount');
    $approx($a['remaining'], 0, 'Test A: remaining = 900 - 900');
    $assert($a['status'] === 'paid', "Test A: status must be 'paid', got '{$a['status']}'.");
    $assert($a['is_overdue'] === false, 'Test A: a paid invoice must never be overdue.');
}

// -------- B) partial --------
$b = $rowsById[$idPartial] ?? null;
$assert($b !== null, 'Test B: partial invoice must be present.');
if ($b) {
    $approx($b['net_invoice'], 1000, 'Test B: net_invoice (no return)');
    $approx($b['remaining'], 600, 'Test B: remaining = 1000 - 400');
    $assert($b['status'] === 'partial', "Test B: status must be 'partial', got '{$b['status']}'.");
    $assert($b['is_overdue'] === false, 'Test B: not yet due must not be overdue.');
}

// -------- C) overdue, with a known day count --------
$c = $rowsById[$idOverdue] ?? null;
$assert($c !== null, 'Test C: overdue invoice must be present.');
if ($c) {
    $approx($c['remaining'], 500, 'Test C: remaining = full 500 (nothing paid)');
    $assert($c['status'] === 'overdue', "Test C: status must be 'overdue', got '{$c['status']}'.");
    $assert($c['is_overdue'] === true, 'Test C: is_overdue must be true.');
    $approx($c['overdue_days'], 6, 'Test C: overdue_days', 0.5);
}

// -------- D) due --------
$d = $rowsById[$idDue] ?? null;
$assert($d !== null, 'Test D: due invoice must be present.');
if ($d) {
    $assert($d['status'] === 'due', "Test D: status must be 'due', got '{$d['status']}'.");
    $assert($d['is_overdue'] === false, 'Test D: not yet due must not be overdue.');
    $assert(($d['overdue_days'] ?? 0) === 0, 'Test D: overdue_days must be 0 when not overdue.');
}

// -------- E) pending return must not reduce Net Invoice Amount --------
$e = $rowsById[$idPendingReturn] ?? null;
$assert($e !== null, 'Test E: pending-return invoice must be present.');
if ($e) {
    $approx($e['return_amount'], 0, 'Test E: a PENDING return must not count as return_amount');
    $approx($e['net_invoice'], 200, 'Test E: net_invoice must stay at the full 200, pending return ignored');
}

// ==================== Summary cards (over the whole filtered/scoped set) ====================
$summary = $res['summary'] ?? [];
$approx($summary['total_invoice'] ?? null, 1000 + 1000 + 500 + 300 + 200, 'Test Summary: total_invoice');
$approx($summary['total_return'] ?? null, 100, 'Test Summary: total_return (only the received one)');
$approx($summary['net_invoice'] ?? null, 900 + 1000 + 500 + 300 + 200, 'Test Summary: net_invoice');
$approx($summary['total_paid'] ?? null, 900 + 400, 'Test Summary: total_paid');
$approx($summary['total_remaining'] ?? null, 0 + 600 + 500 + 300 + 200, 'Test Summary: total_remaining');
$approx($summary['total_overdue'] ?? null, 500, 'Test Summary: total_overdue (only Sale C)');

// ==================== Filters: status + outstanding_only ====================
$reqStatus = Request::create('/api/report/invoice_receivables', 'GET', ['client_id' => $client->id, 'status' => 'overdue']);
app()->instance('request', $reqStatus);
$resStatus = json_decode($reportController->Report_InvoiceReceivables($reqStatus)->getContent(), true);
$assert(($resStatus['totalRows'] ?? null) === 1, 'Test Filter: status=overdue must return exactly 1 row (Sale C).');

$reqOutstanding = Request::create('/api/report/invoice_receivables', 'GET', ['client_id' => $client->id, 'outstanding_only' => 1]);
app()->instance('request', $reqOutstanding);
$resOutstanding = json_decode($reportController->Report_InvoiceReceivables($reqOutstanding)->getContent(), true);
$assert(($resOutstanding['totalRows'] ?? null) === 4, 'Test Filter: outstanding_only must exclude the fully-paid Sale A (4 of 5 remain).');

// ==================== Cleanup ====================
SaleReturn::where('Ref', 'like', 'M8-RET-%')->forceDelete();
Sale::where('notes', 'Build M8 invoice receivables test')->forceDelete();
Client::where('id', $client->id)->forceDelete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M8 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M8 regression: PASS (Invoice Receivables Report computes Net Invoice / Remaining / Overdue Days / 4-state Payment Status correctly against real sales, correctly excludes pending sale returns, and its Date/Customer/Status/Outstanding-only filters and summary cards all match hand-worked expected values).\n";
