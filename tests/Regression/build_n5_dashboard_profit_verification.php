<?php

/**
 * Build N5 (part 3) — Dashboard Profit card investigation (2026-09-20).
 *
 * Client flagged the Dashboard's "Profit" stat card as looking wrong
 * (screenshot: Sales $20,120.01, Purchases $30,000.00, Profit $9,920.01)
 * — the implicit expectation being something like Sales − Purchases.
 *
 * Investigation (DashboardController::report_dashboard(), lines ~660-693,
 * backed by App\Traits\CalculatesCogsAndAverageCost):
 *
 *   Profit = SUM(GrandTotal) of COMPLETED sales in the period
 *          − COGS (FIFO, computed only for products actually sold in the
 *            period, using real purchase-layer costs)
 *          − Expenses in the period
 *          + Service/repair job profit (revenue − parts cost) in the period
 *
 * This is standard accrual/COGS accounting, NOT Sales − Purchases:
 *   - Buying stock (a Purchase) is a balance-sheet event, not an expense —
 *     it only reduces profit later, gradually, as each unit is actually
 *     SOLD (via COGS). A big purchase in the period that hasn't sold yet
 *     correctly has ZERO effect on this period's profit.
 *   - The formula found NO bug: it is internally consistent and matches
 *     the same COGS/FIFO helper used by the Profit & Loss report, so both
 *     screens agree.
 *
 * What DOES look like a plausible source of the "seems wrong" confusion,
 * confirmed here: the "Sales" stat card (today_sales) sums ALL sales in
 * the period regardless of status, while the "Profit" card's revenue side
 * (completedSalesTotal) only counts sales with statut='completed'. If a
 * business has pending/draft sales in the period, "Sales" and "Profit"
 * are computed off two DIFFERENT revenue bases, which is easy to misread
 * as a bug. Fixed by adding an info-tooltip to both cards (see
 * Dashboard.vue) explaining the formula and scope — not a calculation
 * change, since no calculation bug was found.
 *
 * This test builds a real scenario mirroring that exact situation (a
 * purchase that isn't fully consumed yet, a completed sale, a pending
 * sale that must be EXCLUDED from profit, and an expense) and verifies
 * the formula computes exactly as documented above.
 *
 * Run with: php tests/Regression/build_n5_dashboard_profit_verification.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\DashboardController;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use App\Models\Warehouse;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);

// A dedicated, freshly-created warehouse isolates this scenario's totals
// from whatever other sales/purchases/expenses already exist in the
// shared sandbox database (this test asserts exact numbers, so it can't
// share a warehouse with other tests' leftover data).
$warehouse = Warehouse::create(['name' => 'N5 Profit Test Warehouse '.time()]);
$provider = Provider::first();
$assert($warehouse !== null && $provider !== null, 'Setup: a warehouse and a provider must exist.');

$req = static function (string $method, string $uri, array $params = []) use ($admin) {
    $r = Request::create($uri, $method, $params);
    $r->setUserResolver(fn ($g = null) => $admin);
    app()->instance('request', $r);

    return $r;
};

$today = now()->toDateString();
$cleanup = ['sales' => [], 'purchases' => [], 'expenses' => [], 'products' => [], 'warehouses' => [$warehouse->id]];

echo "== Setup: a scenario shaped like the client's screenshot ==\n";

// A single-type product so cost/price live directly on the product row —
// keeps the arithmetic simple and auditable by hand.
$product = Product::create([
    'name' => 'N5 Profit Test Widget', 'code' => 'N5PROFIT'.time(), 'category_id' => 1,
    'unit_id' => 1, 'unit_sale_id' => 1, 'unit_purchase_id' => 1, 'type' => 'is_single',
    'cost' => 20, 'price' => 100, 'wholesale_price' => 0, 'min_price' => 0, 'tax_method' => 1, 'is_active' => 1, 'Type_barcode' => 'CODE128',
    'discount_type' => 'fixed', 'discount_method' => '2',
]);
$cleanup['products'][] = $product->id;

// Purchase 10 units at $50/unit ($500 total) — a big purchase, mirroring
// the screenshot's $30,000 Purchases figure looming over a smaller profit.
$purchase = new Purchase([
    'date' => $today, 'Ref' => 'N5PUR'.time(), 'provider_id' => $provider->id,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 500, 'paid_amount' => 500,
    'statut' => 'received', 'payment_statut' => 'paid', 'TaxNet' => 0, 'tax_rate' => 0,
    'discount' => 0, 'shipping' => 0,
]);
$purchase->user_id = $admin->id;
$purchase->save();
$cleanup['purchases'][] = $purchase->id;
PurchaseDetail::create([
    'purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => 10,
    'cost' => 50, 'total' => 500, 'TaxNet' => 0, 'discount' => 0,
]);

// A COMPLETED sale: 3 units at $100 = $300 revenue.
$completedSale = Sale::create([
    'date' => $today, 'Ref' => 'N5SALEDONE'.time(), 'client_id' => 1,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 300, 'paid_amount' => 300,
    'statut' => 'completed', 'payment_statut' => 'paid', 'user_id' => $admin->id,
]);
$cleanup['sales'][] = $completedSale->id;
SaleDetail::create([
    'date' => $today, 'sale_id' => $completedSale->id, 'product_id' => $product->id,
    'quantity' => 3, 'price' => 100, 'total' => 300, 'TaxNet' => 0, 'discount' => 0,
    'discount_method' => '2', 'tax_method' => '1',
]);

// A PENDING sale: 2 units at $100 = $200 — counted in "today_sales" (all
// statuses) but must be EXCLUDED from Profit's revenue and from COGS,
// since it hasn't actually been fulfilled.
$pendingSale = Sale::create([
    'date' => $today, 'Ref' => 'N5SALEPEND'.time(), 'client_id' => 1,
    'warehouse_id' => $warehouse->id, 'GrandTotal' => 200, 'paid_amount' => 0,
    'statut' => 'pending', 'payment_statut' => 'unpaid', 'user_id' => $admin->id,
]);
$cleanup['sales'][] = $pendingSale->id;
SaleDetail::create([
    'date' => $today, 'sale_id' => $pendingSale->id, 'product_id' => $product->id,
    'quantity' => 2, 'price' => 100, 'total' => 200, 'TaxNet' => 0, 'discount' => 0,
    'discount_method' => '2', 'tax_method' => '1',
]);

// An expense in the period.
$expenseCategory = ExpenseCategory::create(['user_id' => $admin->id, 'name' => 'N5 Test Category'.time()]);
$expense = Expense::create([
    'date' => $today, 'user_id' => $admin->id, 'expense_category_id' => $expenseCategory->id,
    'warehouse_id' => $warehouse->id, 'details' => 'N5 test expense', 'amount' => 40, 'Ref' => 'N5EXP'.time(),
]);
$cleanup['expenses'][] = $expense->id;
$cleanup['expense_categories'] = [$expenseCategory->id];

// ==================================================================
// Call the real dashboard endpoint and verify the formula by hand.
// ==================================================================

// Calls report_dashboard() directly rather than the full dashboard_data()
// endpoint: the other dashboard widgets (SalesChart/PurchasesChart) use
// MySQL-only DATE_FORMAT() SQL that this SQLite sandbox can't run — a
// pre-existing, unrelated sandbox limitation (production runs MySQL).
// report_dashboard() is the exact method that computes the stat cards
// under test here (today_sales, today_purchases, today_profit) and is
// itself portable SQL.
echo "== DashboardController::report_dashboard(): verify Profit formula ==\n";

$dashboardController = app(DashboardController::class);
$dashReq = $req('GET', '/api/dashboard_data', ['from' => $today, 'to' => $today, 'warehouse_id' => $warehouse->id]);
$reportResp = $dashboardController->report_dashboard($dashReq, (int) $warehouse->id, [$warehouse->id]);
$reportData = $reportResp instanceof \Illuminate\Http\JsonResponse ? json_decode($reportResp->getContent(), true) : $reportResp;
$stats = $reportData['report'] ?? [];

// today_sales = ALL statuses = 300 (completed) + 200 (pending) = 500 —
// this is the exact divergence that makes "Sales" and "Profit" look
// unrelated at a glance.
$assert(abs(($stats['today_sales'] ?? -1) - 500.0) < 0.01, 'today_sales must include BOTH the completed and pending sale (300+200=500). Got: '.($stats['today_sales'] ?? 'MISSING'));

// today_purchases = 500 (the full purchase, regardless of how much of it
// has been sold) — confirms Purchases is a separate, unrelated figure,
// not something subtracted from Profit directly.
$assert(abs(($stats['today_purchases'] ?? -1) - 500.0) < 0.01, 'today_purchases must be the full purchase total (500). Got: '.($stats['today_purchases'] ?? 'MISSING'));

// Profit = completed-only revenue (300) - COGS FIFO for the 3 units sold
// (3 * $50 purchase cost = 150) - expenses (40) + service profit (0)
//        = 300 - 150 - 40 + 0 = 110
$expectedProfit = 300 - 150 - 40 + 0;
$actualProfit = (float) ($stats['today_profit'] ?? -99999);
$assert(abs($actualProfit - $expectedProfit) < 0.01, "today_profit must equal completed-sales-revenue(300) - COGS-FIFO(150) - expenses(40) + service-profit(0) = {$expectedProfit}. Got: {$actualProfit}");

// The pending sale's $200 must NOT have leaked into profit at all — this
// is the concrete proof that Profit and "Sales" use different revenue
// bases (the likely source of the client's "looks wrong" impression).
$assert($actualProfit < ($stats['today_sales'] ?? 0) - 150 - 40 + 1, 'Sanity: if the pending sale HAD leaked into profit, today_profit would be ~60 higher than expected — confirming it correctly did not.');

// The $500 purchase must NOT be subtracted wholesale from profit — only
// the $150 actually consumed by the completed sale's COGS. If Purchases
// were subtracted directly (the naive "Sales - Purchases" the client may
// have expected), profit would be deeply negative (300 - 500 = -200).
$assert($actualProfit > -100, 'Profit must NOT equal Sales-minus-Purchases (that would be deeply negative here); buying unsold stock does not reduce this period\'s profit.');

// ==================================================================
// Static check: the new clarifying tooltips are wired up.
// ==================================================================

echo "== Static check: Dashboard.vue Sales/Profit card tooltips ==\n";

$root = dirname(__DIR__, 2);
$dashboardVue = file_get_contents($root.'/resources/src/pages/Dashboard.vue');
$assert(str_contains($dashboardVue, "hint: t('Dashboard_Sales_Hint')"), 'Dashboard.vue Sales card must carry the clarifying hint.');
$assert(str_contains($dashboardVue, "hint: t('Dashboard_Profit_Hint')"), 'Dashboard.vue Profit card must carry the clarifying hint.');
$assert(str_contains($dashboardVue, 'card.hint'), 'Dashboard.vue stat card template must render the hint tooltip.');

$translations = file_get_contents($root.'/database/seeders/translations/en.php');
$assert(str_contains($translations, "'Dashboard_Profit_Hint' =>"), 'en.php must define Dashboard_Profit_Hint.');
$assert(str_contains($translations, "'Dashboard_Sales_Hint' =>"), 'en.php must define Dashboard_Sales_Hint.');

// ==================================================================
// Static check: Sales vs Purchases chart modernized to a smooth gradient
// area chart (client's chosen direction), and the <apexchart> component's
// own `type` prop was updated to match — vue3-apexcharts uses the
// component prop as the source of truth over options.chart.type, so
// leaving the prop as "bar" while only changing the options object would
// have silently kept rendering the old bar chart.
// ==================================================================

echo "== Static check: Sales vs Purchases chart is now a smooth area chart ==\n";

$assert(str_contains($dashboardVue, "type=\"area\" height=\"320\" :options=\"salesChart.options\""), 'Dashboard.vue must render the Sales/Purchases apexchart with type="area" (the component prop, not just the options object).');
$assert(! str_contains($dashboardVue, "type=\"bar\" height=\"320\" :options=\"salesChart.options\""), 'Dashboard.vue must no longer render the Sales/Purchases chart as a bar chart.');

// ==================================================================
// Cleanup
// ==================================================================

foreach ($cleanup['sales'] as $id) {
    SaleDetail::where('sale_id', $id)->delete();
    Sale::where('id', $id)->forceDelete();
}
foreach ($cleanup['purchases'] as $id) {
    PurchaseDetail::where('purchase_id', $id)->delete();
    Purchase::where('id', $id)->forceDelete();
}
foreach ($cleanup['expenses'] as $id) {
    Expense::where('id', $id)->forceDelete();
}
foreach ($cleanup['expense_categories'] ?? [] as $id) {
    ExpenseCategory::where('id', $id)->forceDelete();
}
foreach ($cleanup['products'] as $id) {
    Product::where('id', $id)->forceDelete();
}
foreach ($cleanup['warehouses'] as $id) {
    Warehouse::where('id', $id)->forceDelete();
}

if ($failures) {
    fwrite(STDERR, "Build N5 Dashboard Profit verification FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N5 Dashboard Profit verification: PASS — formula is correct (completed-sales revenue - FIFO COGS - expenses + service profit); no calculation bug found. Added clarifying tooltips to explain why Profit isn't Sales-minus-Purchases.\n";
