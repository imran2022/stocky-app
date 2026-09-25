<?php
// Audit fix (MF-14, external "Must-Fix" audit 2026-09-25): App\Support\Reporting\InventoryWriteOffFigures::cost()
// (legacy/costing-off mode) used to value every Damage and Adjustment-decrease write-off at TODAY's live
// product/variant `cost` column -- so a write-off from LAST month, already reported, silently changed value the
// moment someone edited that product's cost today. Fixed with the same date-anchored average (as of the report's
// `$to` date) that ProfitReportController's legacy COGS branch already uses via HistoricalCostAtDate, built from
// purchase/adjustment history up to that date instead of today's master cost.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\DamageController;
use App\Support\Reporting\InventoryWriteOffFigures;
use Illuminate\Support\Facades\DB;

$W = 3; // matches _audit_purch.php's default purchase warehouse

function mkProductMF14($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price; $src['type'] = 'is_single';
    return DB::table('products')->insertGetId($src);
}

DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0]);

// Purchase this product at 15/unit (its master cost, deliberately set differently below, must NOT be what gets
// used), dated BEFORE the reporting window below so it counts as history as-of that window's end date.
$P1 = mkProductMF14('MF14-WRITEOFF-HIST', 'MF14WH', 999, 40);
[$c, $b] = mkPurchase($P1, 10, 15, $W, ['date' => '2026-07-01']);
check('purchase of 10 @ 15 accepted', $c == 200, "$c $b");

// Damage 4 units, dated in the past ("last month" relative to a fixed reporting window below).
$pastDate = '2026-08-10';
[$c, $b] = call(DamageController::class, 'store', [
    'warehouse_id' => $W, 'date' => $pastDate, 'notes' => 'mf14', 'details' => [['product_id' => $P1, 'product_variant_id' => null, 'quantity' => 4]],
]);
check('damage of 4 (past-dated) accepted', $c == 200, "$c $b");

$reportFrom = '2026-08-01';
$reportTo = '2026-08-31'; // report window covers the damage but NOT the purchase's cost-changing "today"

$before = InventoryWriteOffFigures::cost($reportFrom, $reportTo, $W, [$W]);
check('write-off cost for the August report = 4 x 15 (purchase cost, not master cost 999)', abs($before - 60.0) < 0.01, "got $before, expected 60");

// Now edit the product's MASTER cost today (simulating "someone corrected the cost column much later").
DB::table('products')->where('id', $P1)->update(['cost' => 500]);

$after = InventoryWriteOffFigures::cost($reportFrom, $reportTo, $W, [$W]);
check(
    'the ALREADY-REPORTED August write-off figure is unchanged by today\'s master-cost edit (still 60, not 4 x 500 = 2000)',
    abs($after - 60.0) < 0.01,
    "got $after, expected 60 (unchanged)"
);

// ---------------------------------------------------------------- fallback: no purchase/adjustment history yet -----
// A product that has NEVER been purchased or adjusted: the historical-cost table has no row for it, so the figure
// must fall back to today's master/variant cost (same documented fallback HistoricalCostAtDate already uses).
$P2 = mkProductMF14('MF14-NO-HISTORY', 'MF14NH', 77, 100);
// Give it stock directly (bypassing Purchase/Adjustment) so Damage has something to write off but no cost history exists.
DB::table('product_warehouse')->where('product_id', $P2)->where('warehouse_id', $W)->whereNull('product_variant_id')->delete();
$pwSrc = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', $W)->whereNull('product_variant_id')->first();
if (! $pwSrc) { $pwSrc = ['manage_stock' => 1, 'created_at' => now(), 'updated_at' => now()]; } else { unset($pwSrc['id']); }
$pwSrc['product_id'] = $P2; $pwSrc['warehouse_id'] = $W; $pwSrc['qte'] = 20; $pwSrc['product_variant_id'] = null;
DB::table('product_warehouse')->insert($pwSrc);

[$c, $b] = call(DamageController::class, 'store', [
    'warehouse_id' => $W, 'date' => $pastDate, 'notes' => 'mf14-nohist', 'details' => [['product_id' => $P2, 'product_variant_id' => null, 'quantity' => 3]],
]);
check('damage of the no-purchase-history product accepted', $c == 200, "$c $b");

$noHist = InventoryWriteOffFigures::cost($reportFrom, $reportTo, $W, [$W]) - $after;
check('no-history product falls back to master cost: 3 x 77 = 231', abs($noHist - 231.0) < 0.01, "got $noHist, expected 231");

// ---------------------------------------------------------------- Adjustment decrease uses the same historical cost
$P3 = mkProductMF14('MF14-ADJ-DECREASE', 'MF14AD', 999, 40);
[$c, $b] = mkPurchase($P3, 10, 25, $W, ['date' => '2026-07-01']);
check('purchase of P3 10 @ 25 accepted', $c == 200, "$c $b");

$adjPayload3 = adjPayload($W, 'sub', 5, $P3);
$adjPayload3['date'] = $pastDate;
[$c, $b] = call(App\Http\Controllers\AdjustmentController::class, 'store', $adjPayload3);
check('adjustment decrease of 5 (past-dated) accepted', $c == 200, "$c $b");

$withAdj = InventoryWriteOffFigures::cost($reportFrom, $reportTo, $W, [$W]);
$adjOnly = $withAdj - $after - $noHist;
check('adjustment-decrease write-off uses the same purchase-anchored cost: 5 x 25 = 125', abs($adjOnly - 125.0) < 0.01, "got $adjOnly, expected 125");

finish('MF-14: InventoryWriteOffFigures legacy branch uses date-anchored historical cost, not today\'s master cost');
