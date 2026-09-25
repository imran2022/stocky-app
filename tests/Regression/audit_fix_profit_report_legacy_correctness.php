<?php
// Inventory Costing — update 5: Legacy Profit Report correctness, follow-up to update 4 (historical cost). An
// external code review of update 4 found the Legacy branch of ProfitReportController had several further,
// pre-existing defects (not caused by update 4): pending sales counted as revenue, received returns never netted,
// box/pack sales undercosted (raw sale-unit quantity, not base units), and the update-4 historical average blending
// different warehouses' costs into one number. Fixed via App\Support\Reporting\LegacyProfitLines (normalized,
// completed+returns, base-unit quantities) and a warehouse-keyed App\Support\Reporting\HistoricalCostAtDate. Two
// further findings are deliberately NOT fixed (documented Legacy limitations, not bugs): one flat report-window
// average (not transaction-time cost), and adjustment history still valued at today's master cost.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\ProfitReportController as PRC;
use App\Services\Costing\InventoryCostingService as Svc;
use App\Services\Costing\CostingReader;
use Illuminate\Support\Facades\DB;

try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
Svc::setMethod('legacy'); Svc::forgetMethodCache();
check('legacy costing is active for this test', ! CostingReader::active());

$W = 1;
$W2 = (int) DB::table('warehouses')->whereNull('deleted_at')->where('id', '!=', $W)->value('id');
check('a second real warehouse exists for the per-warehouse test', $W2 > 0, $W2);
$now = now();

function lp_mkProduct($name, $code, $cost, $price) {
    $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price;
    return DB::table('products')->insertGetId($src);
}
$params = fn ($extra = []) => array_merge(['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => -1], $extra);
$profit = function ($dim, $extra = []) use ($params) {
    [$code, $body] = call(PRC::class, 'index', $params($extra), 'GET', [$dim]);
    if ($code !== 200) { throw new RuntimeException("index($dim) -> $code: $body"); }

    return json_decode($body, true);
};
$rowFor = fn ($dim, $label, $extra = []) => collect($profit($dim, $extra)['rows'])->firstWhere('label', $label);

// ---------------------------------------------------------------------------------------------------------------
// 1. Completed vs pending sale — only the completed one is counted.
// ---------------------------------------------------------------------------------------------------------------
$P1 = lp_mkProduct('LPC-COMPLETED', 'LPCC1', 50, 200);
[$c] = mkPurchase($P1, 10, 50, $W, ['date' => '2030-01-01']); check('P1 purchase', $c == 200, $c);
[$c] = mkSale([line($P1, 4, 200)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P1 completed sale', $c == 200, $c);
[$c] = mkSale([line($P1, 100, 200)], ['date' => '2030-01-10', 'warehouse_id' => $W, 'statut' => 'pending']); check('P1 pending sale (huge qty, would dominate if counted)', $c == 200, $c);
$r1 = $rowFor('product', 'LPC-COMPLETED');
check('1) only the completed sale is counted (qty 4, not 104)', $r1 && abs($r1['qty'] - 4) < 0.01 && abs($r1['revenue'] - 800) < 0.01, json_encode($r1));

// ---------------------------------------------------------------------------------------------------------------
// 2. Received vs pending sale return — only the received one nets off.
// ---------------------------------------------------------------------------------------------------------------
$P2 = lp_mkProduct('LPC-RETURN', 'LPCR1', 50, 200);
[$c] = mkPurchase($P2, 20, 50, $W, ['date' => '2030-01-01']); check('P2 purchase', $c == 200, $c);
[$c, $b, $sid] = mkSale([line($P2, 10, 200)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P2 sale of 10', $c == 200, $b);
$detId = DB::table('sale_details')->where('sale_id', $sid)->value('id');
$mkReturn = fn ($qty, $statut) => call(App\Http\Controllers\SalesReturnController::class, 'store', [
    'sale_id' => $sid, 'client_id' => 2, 'warehouse_id' => $W, 'date' => '2030-01-15', 'statut' => $statut,
    'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $qty * 200,
    'details' => [['id' => 0, 'product_id' => $P2, 'product_variant_id' => null, 'quantity' => $qty, 'Unit_price' => 200,
        'sale_detail_id' => $detId, 'subtotal' => $qty * 200, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0,
        'discount_Method' => '2', 'sale_unit_id' => 1, 'imei_number' => null]],
]);
[$c] = $mkReturn(2, 'received'); check('P2 RECEIVED return of 2', $c == 200, $c);
[$c] = $mkReturn(3, 'pending'); check('P2 PENDING return of 3', $c == 200, $c);
$r2 = $rowFor('product', 'LPC-RETURN');
check('2) only the RECEIVED return (2) nets off — qty 10-2=8, not 10-2-3=5', $r2 && abs($r2['qty'] - 8) < 0.01 && abs($r2['revenue'] - 1600) < 0.01 && abs($r2['cost'] - 400) < 0.01, json_encode($r2));

// ---------------------------------------------------------------------------------------------------------------
// 3. Plain base-unit sale (sanity: unaffected by the base-unit conversion work).
// ---------------------------------------------------------------------------------------------------------------
$P3 = lp_mkProduct('LPC-BASEUNIT', 'LPCB1', 25, 100);
[$c] = mkPurchase($P3, 50, 25, $W, ['date' => '2030-01-01']); check('P3 purchase', $c == 200, $c);
[$c] = mkSale([line($P3, 6, 100)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P3 sale', $c == 200, $c);
$r3 = $rowFor('product', 'LPC-BASEUNIT');
check('3) base-unit sale costs qty x unit cost (6 x 25 = 150)', $r3 && abs($r3['cost'] - 150) < 0.01, json_encode($r3));

// ---------------------------------------------------------------------------------------------------------------
// 4. Box/pack sale — cost must use the BASE-unit quantity (qty x pack size), not the raw sale-unit count.
// ---------------------------------------------------------------------------------------------------------------
$boxUnit = DB::table('units')->insertGetId(['name' => 'LPC Box of 12', 'ShortName' => 'LPCBOX', 'operator' => '*', 'operator_value' => 12, 'base_unit' => 1, 'created_at' => $now, 'updated_at' => $now]);
$P4 = lp_mkProduct('LPC-PACK', 'LPCP1', 10, 300);
[$c] = mkPurchase($P4, 120, 10, $W, ['date' => '2030-01-01']); check('P4 purchase (120 base units @ 10)', $c == 200, $c);
[$c] = mkSale([line($P4, 2, 300, ['sale_unit_id' => $boxUnit])], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P4 sale of 2 boxes', $c == 200, $c);
$r4 = $rowFor('product', 'LPC-PACK');
check('4) box sale costs the BASE quantity (2 boxes x 12 x 10 = 240, not 2 x 10 = 20)', $r4 && abs($r4['cost'] - 240) < 0.01, json_encode($r4));

// ---------------------------------------------------------------------------------------------------------------
// 5. Variant + pack sale together — correct variant AND converted base quantity.
// ---------------------------------------------------------------------------------------------------------------
$P5 = lp_mkProduct('LPC-VARIANT', 'LPCV1', 999, 999);   // parent cost must NOT be used
$variantId = DB::table('product_variants')->insertGetId(['product_id' => $P5, 'name' => 'Red', 'cost' => 999, 'price' => 500, 'code' => 'LPCV1-RED', 'created_at' => $now, 'updated_at' => $now]);
[$c] = mkPurchase($P5, 60, 15, $W, ['date' => '2030-01-01', 'GrandTotal' => 900], [], $variantId); check('P5 variant purchase (60 base units @ 15)', is_array($c) ? false : $c == 200, json_encode($c));
DB::table('purchase_details')->where('product_id', $P5)->update(['product_variant_id' => $variantId]);
[$c] = mkSale([line($P5, 3, 500, ['product_variant_id' => $variantId, 'sale_unit_id' => $boxUnit])], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P5 variant box sale of 3 boxes', $c == 200, $c);
$r5 = $rowFor('product', 'LPC-VARIANT');
check('5) variant + box sale costs base qty at the variant\'s own purchase history (3 x 12 x 15 = 540)', $r5 && abs($r5['cost'] - 540) < 1, json_encode($r5));

// ---------------------------------------------------------------------------------------------------------------
// 6/7. Two warehouses, different purchase costs — each keeps its own; the all-warehouse total is their exact sum.
// ---------------------------------------------------------------------------------------------------------------
$P6 = lp_mkProduct('LPC-WHSEP', 'LPCW1', 999, 500);
[$c] = mkPurchase($P6, 10, 100, $W, ['date' => '2030-01-01']); check('P6 purchase WH1 @ 100', $c == 200, $c);
[$c] = mkPurchase($P6, 10, 300, $W2, ['date' => '2030-01-01']); check('P6 purchase WH2 @ 300', $c == 200, $c);
[$c] = mkSale([line($P6, 2, 500)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P6 sale in WH1', $c == 200, $c);
[$c] = mkSale([line($P6, 2, 500)], ['date' => '2030-01-10', 'warehouse_id' => $W2]); check('P6 sale in WH2', $c == 200, $c);
$whRows = $profit('warehouse')['rows'];
$wh1Name = DB::table('warehouses')->where('id', $W)->value('name');
$wh2Name = DB::table('warehouses')->where('id', $W2)->value('name');
$costPerUnit = fn ($rows, $name) => collect($rows)->firstWhere('label', $name);
// isolate the P6-only effect by comparing per-product cost directly (the warehouse dimension totals include the
// other scenarios' sales above too, since they share the same date range)
$p6ProductRows = collect($profit('product', ['search' => 'LPC-WHSEP'])['rows']);
check('6) warehouse dimension total product LPC-WHSEP resolvable via product search', $p6ProductRows->count() === 1);
// direct per-warehouse verification via the underlying temp table (unambiguous, no confound from other scenarios)
$histTable = \App\Support\Reporting\HistoricalCostAtDate::temp('2030-01-31', null, null, [$P6]);
$wh1Cost = DB::table($histTable)->where('product_id', $P6)->where('warehouse_id', $W)->value('avg_cost');
$wh2Cost = DB::table($histTable)->where('product_id', $P6)->where('warehouse_id', $W2)->value('avg_cost');
check('6) warehouse 1 keeps its own cost (100)', abs((float) $wh1Cost - 100) < 0.01, $wh1Cost);
check('6) warehouse 2 keeps its own cost (300), not blended with warehouse 1', abs((float) $wh2Cost - 300) < 0.01, $wh2Cost);
// the KPI itself is intentionally NOT narrowed by `search` (see check 11 below — it always covers the whole
// filtered set), so isolate P6's own total via its (search-filtered) product ROW instead, not the KPI.
$p6Row = $p6ProductRows->first();
check('7) all-warehouse total = exact sum of each warehouse\'s own cost (2x100 + 2x300 = 800)', $p6Row && abs($p6Row['cost'] - 800) < 1, json_encode($p6Row));

// ---------------------------------------------------------------------------------------------------------------
// 8. Purchase after sale — DOCUMENTED Legacy limitation, not fixed: the later purchase still moves the earlier
//    sale's reported cost WITHIN THE SAME REPORT WINDOW (one flat average per window, not transaction-time cost).
// ---------------------------------------------------------------------------------------------------------------
$P8 = lp_mkProduct('LPC-LATEPURCH', 'LPCL1', 100, 500);
[$c] = mkPurchase($P8, 10, 100, $W, ['date' => '2030-01-01']); check('P8 purchase1', $c == 200, $c);
[$c] = mkSale([line($P8, 5, 500)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P8 sale on Jan 10', $c == 200, $c);
$before8 = $rowFor('product', 'LPC-LATEPURCH');
[$c] = mkPurchase($P8, 10, 300, $W, ['date' => '2030-01-25']); check('P8 purchase2 (after the sale, same report window)', $c == 200, $c);
$after8 = $rowFor('product', 'LPC-LATEPURCH');
check('8) DOCUMENTED LIMITATION: a later purchase in the same window still moves the earlier sale\'s cost (not a regression to fix here — see CUSTOMIZATIONS.md)',
    $before8 && $after8 && abs($before8['cost'] - $after8['cost']) > 0.01, json_encode([$before8, $after8]));

// ---------------------------------------------------------------------------------------------------------------
// 9. Adjustment + later master-cost edit — DOCUMENTED Legacy limitation, not fixed: adjustment history has no
//    stamped cost, so it still follows a later master-cost edit (unlike purchase-only history).
// ---------------------------------------------------------------------------------------------------------------
$P9 = lp_mkProduct('LPC-ADJONLY', 'LPCA1', 40, 200);
$adjHdr = DB::table('adjustments')->insertGetId(['date' => '2030-01-01', 'time' => '10:00:00', 'Ref' => 'ADJ-LPC', 'warehouse_id' => $W, 'items' => 1, 'notes' => 'opening', 'user_id' => 2, 'created_at' => $now, 'updated_at' => $now]);
DB::table('adjustment_details')->insert(['adjustment_id' => $adjHdr, 'product_id' => $P9, 'product_variant_id' => null, 'quantity' => 20, 'type' => 'add', 'created_at' => $now, 'updated_at' => $now]);
DB::table('product_warehouse')->where('product_id', $P9)->where('warehouse_id', $W)->update(['qte' => 20]);
[$c] = mkSale([line($P9, 3, 200)], ['date' => '2030-01-10', 'warehouse_id' => $W]); check('P9 adjustment-only sale', $c == 200, $c);
$before9 = $rowFor('product', 'LPC-ADJONLY');
DB::table('products')->where('id', $P9)->update(['cost' => 999]);
$after9 = $rowFor('product', 'LPC-ADJONLY');
check('9) DOCUMENTED LIMITATION: adjustment-only history still follows a later master-cost edit (not a regression to fix here)',
    $before9 && $after9 && abs($before9['cost'] - $after9['cost']) > 0.01, json_encode([$before9, $after9]));

// ---------------------------------------------------------------------------------------------------------------
// 10. Sale return by all dimensions — rows still reconcile to KPI after netting returns.
// ---------------------------------------------------------------------------------------------------------------
foreach (['warehouse', 'date', 'category', 'customer', 'unit'] as $dim) {
    $r = $profit($dim);
    check("10) legacy Profit report by $dim: rows sum to KPI (returns netted)", abs(collect($r['rows'])->sum('cost') - $r['kpis']['cost']) < 1, json_encode($r['kpis']));
}

// ---------------------------------------------------------------------------------------------------------------
// 11. Search and pagination — do not change KPI/chart totals; rows remain correctly paginated.
// ---------------------------------------------------------------------------------------------------------------
$full = $profit('product');
$searched = $profit('product', ['search' => 'LPC-PACK']);
check('11) search narrows rows but the KPI stays the full-set total (unaffected by search)', abs($searched['kpis']['cost'] - $full['kpis']['cost']) < 0.01);
check('11) search actually narrowed the row set', count($searched['rows']) < count($full['rows']));
$page1 = $profit('product', ['limit' => 2, 'page' => 1]);
$page2 = $profit('product', ['limit' => 2, 'page' => 2]);
check('11) pagination returns different rows per page', collect($page1['rows'])->pluck('label')->all() !== collect($page2['rows'])->pluck('label')->all());
check('11) pagination does not change totalRows/kpis', $page1['totalRows'] === $full['totalRows'] && abs($page1['kpis']['cost'] - $full['kpis']['cost']) < 0.01);

// ---------------------------------------------------------------------------------------------------------------
// 12. Moving Average control — completely unaffected by this change.
// ---------------------------------------------------------------------------------------------------------------
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
(new Svc)->syncNewDocuments();
check('12) Moving Average active', CostingReader::active());
$maRow = $rowFor('product', 'LPC-BASEUNIT');
check('12) Moving Average Profit Report still answers normally (control — untouched by this change)', $maRow !== null, json_encode($maRow));
Svc::setMethod('legacy'); Svc::forgetMethodCache();

finish('Legacy Profit Report correctness (status, returns, base units, per-warehouse cost)');
