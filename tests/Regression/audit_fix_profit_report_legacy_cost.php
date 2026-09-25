<?php
// Inventory Costing — update 4: legacy-mode Profit Report used to value every sale line at TODAY's master/variant
// cost (App\Http\Controllers\ProfitReportController::index, $costExpr) instead of the cost that was actually in
// effect back when the sale happened — so editing a product's cost today silently rewrote past periods' reported
// profit. Fixed with App\Support\Reporting\HistoricalCostAtDate (a date-anchored average cost from purchase /
// adjustment history up to the report's own `to` date, mirroring App\Traits\CalculatesCogsAndAverageCost's own
// averageCostBulk() logic used by the Profit & Loss report). This test reproduces the exact "cost edited today"
// scenario and proves the historical report no longer moves.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\ProfitReportController as PRC;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
Svc::setMethod('legacy'); Svc::forgetMethodCache();
check('legacy costing is active for this test', ! \App\Services\Costing\CostingReader::active());

$W = 1; $now = now();
function pr_mkProduct($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    return DB::table('products')->insertGetId($src);
}

// Product HIST: has real purchase history at 100, then 120 -> weighted-average historical cost should anchor near
// there, NOT at whatever the master cost is edited to later.
$HIST = pr_mkProduct('PRFIX-HIST', 'PRFIXH', 100, 500);
[$c] = mkPurchase($HIST, 10, 100, $W, ['date' => '2030-01-01']); check('GRN1 (10 @ 100)', $c == 200, $c);
[$c] = mkPurchase($HIST, 10, 120, $W, ['date' => '2030-01-05']); check('GRN2 (10 @ 120)', $c == 200, $c);
// weighted avg cost as of any date on/after 2030-01-05 = (10*100 + 10*120) / 20 = 110
[$c, $b, $sid] = mkSale([line($HIST, 5, 500)], ['date' => '2030-01-10', 'warehouse_id' => $W]);
check('sale of 5 @ 500 created', $c == 200, "$c $b");

// Product NOHIST: no purchase/adjustment history at all (opening stock via direct product_warehouse write, exactly
// like an import/marketplace sync) — the ONLY case the fix is allowed to still fall back to the current master cost.
$NOHIST = pr_mkProduct('PRFIX-NOHIST', 'PRFIXN', 60, 300);
DB::table('product_warehouse')->where('product_id', $NOHIST)->where('warehouse_id', $W)->update(['qte' => 100]);
if (! DB::table('product_warehouse')->where('product_id', $NOHIST)->where('warehouse_id', $W)->exists()) {
    DB::table('product_warehouse')->insert(['product_id' => $NOHIST, 'warehouse_id' => $W, 'product_variant_id' => null, 'qte' => 100, 'manage_stock' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
[$c2, $b2, $sid2] = mkSale([line($NOHIST, 3, 300)], ['date' => '2030-01-10', 'warehouse_id' => $W]);
check('NOHIST sale of 3 @ 300 created', $c2 == 200, "$c2 $b2");

$params = fn ($extra = []) => array_merge(['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => -1], $extra);
$profit = function ($dim, $extra = []) use ($params) {
    [$code, $body] = call(PRC::class, 'index', $params($extra), 'GET', [$dim]);
    if ($code !== 200) { throw new RuntimeException("ProfitReportController::index($dim) -> $code: $body"); }
    return json_decode($body, true);
};
$byProduct = fn () => collect($profit('product')['rows'])->keyBy('label');

$before = $byProduct();
$histRowBefore = $before['PRFIX-HIST'] ?? null; $noHistRowBefore = $before['PRFIX-NOHIST'] ?? null;
check('HIST row present before cost edit', $histRowBefore !== null, json_encode($before->keys()));
check('HIST cost = 5 x 110 (weighted-average purchase history, NOT master cost 100)', $histRowBefore && abs($histRowBefore['cost'] - 550) < 0.01, json_encode($histRowBefore));
check('NOHIST cost = 3 x 60 (falls back to master cost — no purchase/adjustment history exists)', $noHistRowBefore && abs($noHistRowBefore['cost'] - 180) < 0.01, json_encode($noHistRowBefore));

// ---- the audit-stability guarantee: editing master cost TODAY must not move a PAST period's Profit Report --------
DB::table('products')->where('id', $HIST)->update(['cost' => 777]);
DB::table('products')->where('id', $NOHIST)->update(['cost' => 999]);

$after = $byProduct();
$histRowAfter = $after['PRFIX-HIST'] ?? null; $noHistRowAfter = $after['PRFIX-NOHIST'] ?? null;
check('HIST cost UNCHANGED after master cost 100 -> 777 (this is the bug fix)', $histRowAfter && abs($histRowAfter['cost'] - $histRowBefore['cost']) < 0.01, json_encode([$histRowBefore['cost'], $histRowAfter['cost']]));
check('HIST profit UNCHANGED after master cost 100 -> 777', $histRowAfter && abs($histRowAfter['profit'] - $histRowBefore['profit']) < 0.01, json_encode([$histRowBefore['profit'], $histRowAfter['profit']]));
// NOHIST has no purchase/adjustment history, so it is EXPECTED to move with the master cost — that is the
// documented, correct fallback, not a regression.
check('NOHIST cost DOES follow the new master cost 999 (no history -> fallback to current master cost, by design)', $noHistRowAfter && abs($noHistRowAfter['cost'] - 3 * 999) < 0.01, json_encode($noHistRowAfter));

// ---- KPI totals must still equal the sum of the rows (temp table join didn't fan out/duplicate any line) --------
$kpis = $profit('product')['kpis'];
$rowCostSum = $after->sum('cost');
check('KPI cost == sum of row costs (no fan-out from the historical-cost join)', abs($kpis['cost'] - $rowCostSum) < 0.01, json_encode([$kpis['cost'], $rowCostSum]));

// ---- other dimensions must reconcile too (same join, different group-by) ------------------------------------------
foreach (['warehouse', 'date', 'category', 'customer', 'unit'] as $dim) {
    $r = $profit($dim);
    check("legacy Profit report by $dim: rows sum to KPI after the historical-cost fix", abs(collect($r['rows'])->sum('cost') - $r['kpis']['cost']) < 0.5, json_encode($r['kpis']));
}

finish('Profit Report legacy historical-cost fix');
