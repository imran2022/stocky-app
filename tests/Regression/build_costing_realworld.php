<?php
// Moving Average costing: a multi-day, two-warehouse, multi-unit "real business" run through the REAL controllers, checked against an
// INDEPENDENT oracle (a plain re-statement of the moving-average rules over the list of operations this script performs), then compared
// across EVERY report that shows profit, COGS or stock value. Finally: edit / delete / master-cost change and check history behaves.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\DashboardController as DASH;
use App\Http\Controllers\ProfitReportController as PRC;
use App\Http\Controllers\ReportController as RC;
use App\Http\Controllers\SalesReturnController as SR;
use App\Http\Controllers\TodaySummaryController as TODAY;
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
DB::table('units')->insertOrIgnore(['id' => 2, 'name' => 'Box', 'ShortName' => 'bx', 'base_unit' => 1, 'operator' => '*', 'operator_value' => 12, 'is_active' => 1]);
$now = now(); $near = fn ($a, $b, $e = 0.02) => abs($a - $b) <= $e;

// ---------------------------------------------------------------- oracle -----------------------------------------------------------------
class Oracle
{
    public array $st = [];
    private function s($pid, $wh) { return $this->st["$pid:$wh"] ??= ['q' => 0.0, 'avg' => 0.0]; }
    public function in($pid, $wh, $q, $c) { $s = $this->s($pid, $wh);
        if ($s['q'] <= 0) { $nq = $s['q'] + $q; if ($nq > 0) { $s['avg'] = $c; } elseif ($s['avg'] <= 0) { $s['avg'] = $c; } $s['q'] = $nq; }
        else { $s['avg'] = ($s['q'] * $s['avg'] + $q * $c) / ($s['q'] + $q); $s['q'] += $q; }
        $this->st["$pid:$wh"] = $s; }
    public function out($pid, $wh, $q) { $s = $this->s($pid, $wh); $u = $s['avg']; $s['q'] -= $q; $this->st["$pid:$wh"] = $s; return $u; }
    public function outAt($pid, $wh, $q, $c) { $s = $this->s($pid, $wh); $nq = $s['q'] - $q;
        if ($nq > 0) { $na = ($s['q'] * $s['avg'] - $q * $c) / $nq; if ($na >= 0) { $s['avg'] = $na; } } $s['q'] = $nq; $this->st["$pid:$wh"] = $s; }
}
const PRIO = ['purchase' => 10, 'sale' => 20, 'transfer' => 30, 'adjust' => 50, 'sale_return' => 60, 'purchase_return' => 70, 'damage' => 80];
/** Run the operation list through the oracle. Returns [saleUnitCost by op key, saleReturn cost by key, oracle]. */
function runOracle(array $ops, array $opening)
{
    $o = new Oracle(); $unit = []; $retCost = [];
    foreach ($opening as [$pid, $wh, $q, $c]) { $o->in($pid, $wh, $q, $c); }
    uasort($ops, fn ($a, $b) => [$a['date'], PRIO[$a['type']], $a['seq']] <=> [$b['date'], PRIO[$b['type']], $b['seq']]);
    foreach ($ops as $k => $op) {
        switch ($op['type']) {
            case 'purchase': $o->in($op['pid'], $op['wh'], $op['base_q'], $op['base_cost']); break;
            case 'sale': $unit[$k] = $o->out($op['pid'], $op['wh'], $op['base_q']); break;
            case 'transfer': $u = $o->out($op['pid'], $op['from'], $op['q']); $o->in($op['pid'], $op['to'], $op['q'], $u); break;
            case 'sale_return': $u = $unit[$op['orig']] ?? $o->st[$op['pid'].':'.$op['wh']]['avg']; $retCost[$k] = $u; $o->in($op['pid'], $op['wh'], $op['q'], $u); break;
            case 'purchase_return': $o->outAt($op['pid'], $op['wh'], $op['q'], $op['cost']); break;
            case 'adjust': $op['add'] ? $o->in($op['pid'], $op['wh'], $op['q'], $op['cost']) : $o->out($op['pid'], $op['wh'], $op['q']); break;
            case 'damage': $o->out($op['pid'], $op['wh'], $op['q']); break;
        }
    }
    return [$unit, $retCost, $o];
}

// ---------------------------------------------------------------- data -------------------------------------------------------------------
function mkProd($name, $code, $cost, $price) { $src = (array) DB::table('products')->where('id', 1)->first(); unset($src['id']);
    $src['name'] = $name; $src['code'] = $code; $src['cost'] = $cost; $src['price'] = $price; $src['unit_id'] = 1; $src['unit_sale_id'] = 1; $src['unit_purchase_id'] = 1;
    return DB::table('products')->insertGetId($src); }
function setStockRow($pid, $wh, $q) { $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', 1)->first(); unset($pw['id']);
    $pw['product_id'] = $pid; $pw['warehouse_id'] = $wh; $pw['qte'] = $q; $pw['product_variant_id'] = null; DB::table('product_warehouse')->insert($pw); }
$X = mkProd('CST-X', 'CSTX', 8, 20);       // master cost 8: only the imported opening stock will ever use it
$Y = mkProd('CST-Y', 'CSTY', 15, 30);      // master cost deliberately wrong
DB::table('product_warehouse')->whereIn('product_id', [$X, $Y])->delete();
setStockRow($X, 1, 100);                    // imported opening stock: NO document
$opening = [[$X, 1, 100, 8.0]];
$D = fn ($d) => sprintf('2031-01-%02d', $d);

$ops = []; $ids = []; $seq = 0;
$add = function (string $key, array $op) use (&$ops, &$seq) { $op['seq'] = ++$seq; $ops[$key] = $op; };
$sid = fn ($b) => $b['sale_id'] ?? null;

// purchases
[$c, $b, $p1] = mkPurchase($X, 200, 10, 1, ['date' => $D(2)]); check('GRN X 200@10', $c == 200, "$c $b");     $add('gX1', ['type' => 'purchase', 'date' => $D(2), 'pid' => $X, 'wh' => 1, 'base_q' => 200, 'base_cost' => 10]);
[$c, $b, $p2] = mkPurchase($X, 100, 12, 1, ['date' => $D(4)]); check('GRN X 100@12', $c == 200);              $add('gX2', ['type' => 'purchase', 'date' => $D(4), 'pid' => $X, 'wh' => 1, 'base_q' => 100, 'base_cost' => 12]);
[$c, $b] = call(CT_P, 'store', pHdr(1, ['date' => $D(2), 'GrandTotal' => 5 * 240, 'details' => [plm($Y, 5, 240, ['purchase_unit_id' => 2])]])); check('GRN Y 5 boxes @240/box', $c == 200, "$c $b");
$add('gY1', ['type' => 'purchase', 'date' => $D(2), 'pid' => $Y, 'wh' => 1, 'base_q' => 60, 'base_cost' => 20]);
[$c, $b] = mkPurchase($Y, 24, 22, 1, ['date' => $D(9)]); check('GRN Y 24 pcs @22', $c == 200);                $add('gY2', ['type' => 'purchase', 'date' => $D(9), 'pid' => $Y, 'wh' => 1, 'base_q' => 24, 'base_cost' => 22]);

// sales (each on its own date per product/warehouse, so the order is unambiguous)
$mkSale = function (string $key, $pid, $wh, $q, $price, $date, array $lineExtra = [], $baseQ = null) use (&$add, &$ids) {
    [$c, $b, $sid] = mkSale([line($pid, $q, $price, $lineExtra)], ['date' => $date, 'warehouse_id' => $wh]);
    check("sale $key created", $c == 200 && $sid, "$c $b");
    $ids[$key] = ['sale' => $sid, 'detail' => (int) DB::table('sale_details')->where('sale_id', $sid)->value('id'), 'rev' => $q * $price];
    $add($key, ['type' => 'sale', 'date' => $date, 'pid' => $pid, 'wh' => $wh, 'base_q' => $baseQ ?? $q]);
};
$mkSale('sX1', $X, 1, 50, 20, $D(3));
$mkSale('sY1', $Y, 1, 30, 30, $D(3));
// transfer X: 50 wh1 -> wh2
$tp = trPayload(1, 2, $X, 50, 'completed'); $tp['transfer']['date'] = $D(5); [$c, $b] = call(CT_T, 'store', $tp); check('transfer X 50 wh1->wh2 created (pending approval)', $c == 200, "$c $b");
[$c, $b] = call(CT_T, 'approve', [], 'POST', [(int) DB::table('transfers')->max('id')]); check('transfer approved: stock moves now', $c == 200, "$c $b");
$add('tX', ['type' => 'transfer', 'date' => $D(5), 'pid' => $X, 'from' => 1, 'to' => 2, 'q' => 50]);
$mkSale('sX2', $X, 2, 20, 20, $D(6));
$mkSale('sX3', $X, 1, 100, 20, $D(7));
$mkSale('sY2', $Y, 1, 1, 400, $D(6), ['sale_unit_id' => 2], 12);            // one whole box = 12 pieces
// sale return of 10 X from sale sX3 (wh1)
[$c, $b] = call(SR::class, 'store', ['client_id' => 2, 'warehouse_id' => 1, 'sale_id' => $ids['sX3']['sale'], 'date' => $D(8), 'statut' => 'received', 'notes' => 'r', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 200, 'details' => [line($X, 10, 20, ['imei_number' => null])]]);
check('sale return 10 X', $c == 200, "$c $b"); $ids['rX']['ret'] = (int) DB::table('sale_returns')->max('id');
$add('rX', ['type' => 'sale_return', 'date' => $D(8), 'pid' => $X, 'wh' => 1, 'q' => 10, 'orig' => 'sX3']);
// purchase return of 20 X @10 (against GRN p1)
$pr = prPayload($p1, $X, 20, 1); $pr['date'] = $D(10); $pr['GrandTotal'] = 200; $pr['details'] = [pl($X, 20, 10) + ['imei_number' => null]];
[$c, $b] = call(CT_R, 'store', $pr); check('purchase return 20 X @10', $c == 200, "$c $b");
$add('prX', ['type' => 'purchase_return', 'date' => $D(10), 'pid' => $X, 'wh' => 1, 'q' => 20, 'cost' => 10]);
// adjustments: +10 (valued at master cost 8) and -5, damage 3
$a1 = adjPayload(1, 'add', 10, $X); $a1['date'] = $D(11); [$c, $b] = call(CT_A, 'store', $a1); check('adjustment +10', $c == 200, "$c $b");
$add('aX1', ['type' => 'adjust', 'date' => $D(11), 'pid' => $X, 'wh' => 1, 'q' => 10, 'add' => true, 'cost' => 8]);
$a2 = adjPayload(1, 'sub', 5, $X); $a2['date'] = $D(12); [$c, $b] = call(CT_A, 'store', $a2); check('adjustment -5', $c == 200, "$c $b");
$add('aX2', ['type' => 'adjust', 'date' => $D(12), 'pid' => $X, 'wh' => 1, 'q' => 5, 'add' => false]);
[$c, $b] = call(App\Http\Controllers\DamageController::class, 'store', ['warehouse_id' => 1, 'date' => $D(13), 'notes' => 'd', 'details' => [['product_id' => $X, 'product_variant_id' => null, 'quantity' => 3]]]);
check('damage 3', $c == 200, "$c $b"); $add('dX', ['type' => 'damage', 'date' => $D(13), 'pid' => $X, 'wh' => 1, 'q' => 3]);
check('X stock wh1 / wh2 = 100+200+100-50-50-100+10-20+10-5-3 = 192 / 30', stk($X, 1) == 192 && stk($X, 2) == 30, stk($X, 1).' / '.stk($X, 2));

// ---------------------------------------------------------------- switch on & verify vs the oracle --------------------------------------------
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
$svc = new Svc; $svc->syncNewDocuments();
$verifyAgainstOracle = function (string $tag) use (&$ops, &$ids, $opening, $X, $Y, $near) {
    [$unit, $retCost, $o] = runOracle($ops, $opening);
    $ok = true;
    foreach ($ids as $k => $i) {
        if (! isset($i['detail'])) { continue; }
        $row = DB::table('inventory_cost_ledger')->where('source_type', 'sale')->where('source_id', $i['detail'])->first();
        $exp = $unit[$k] * $ops[$k]['base_q'];
        $good = $row && $near(-$row->value_delta, $exp, 0.01);
        check("$tag: COGS of sale $k = ".round($exp, 2), $good, $row ? (string) -$row->value_delta : 'no ledger row');
    }
    if (isset($retCost['rX'])) {
        $row = DB::table('inventory_cost_ledger')->where('source_type', 'sale_return')->where('product_id', $X)->first();
        check("$tag: sale return costed at the ORIGINAL sale cost ".round($retCost['rX'], 4), $row && $near($row->unit_cost, $retCost['rX'], 0.0001), $row ? (string) $row->unit_cost : '-');
    }
    foreach ([$X, $Y] as $pid) {
        foreach ([1, 2] as $wh) {
            $b = DB::table('inventory_cost_balances')->where('product_id', $pid)->where('warehouse_id', $wh)->first();
            $s = $o->st["$pid:$wh"] ?? null;
            if (! $s && ! $b) { continue; }
            check("$tag: balance product $pid wh $wh qty ".round($s['q'] ?? 0, 3).' avg '.round($s['avg'] ?? 0, 4), $b && $near($b->qty, $s['q'], 0.001) && $near($b->avg_cost, $s['avg'], 0.0005) && $near($b->value, $s['q'] * $s['avg'], 0.02), json_encode($b));
        }
    }
    return [$unit, $retCost, $o];
};
[$unit, $retCost, $o] = $verifyAgainstOracle('initial');
check('no unexplained stock: only ONE opening seed (X wh1 100 @ master 8), no other seed', DB::table('inventory_cost_seeds')->whereIn('product_id', [$X, $Y])->count() === 1
    && DB::table('inventory_cost_seeds')->where('product_id', $X)->where('reason', 'opening_unexplained')->where('unit_cost', 8)->exists(), json_encode(DB::table('inventory_cost_seeds')->whereIn('product_id', [$X, $Y])->get()));

// ---------------------------------------------------------------- reports: totals from the oracle ---------------------------------------------
$netCogs = function (array $ops, array $unit, array $retCost, ?int $wh = null, ?int $pid = null) {
    $t = 0.0;
    foreach ($ops as $k => $op) {
        if ($wh !== null && ($op['wh'] ?? null) !== $wh) { continue; }
        if ($pid !== null && ($op['pid'] ?? null) !== $pid) { continue; }
        if ($op['type'] === 'sale') { $t += $unit[$k] * $op['base_q']; }
        if ($op['type'] === 'sale_return') { $t -= $retCost[$k] * $op['q']; }
    }
    return $t;
};
$revenue = 0; foreach ($ids as $k => $i) { $revenue += $i['rev'] ?? 0; } $revenue -= 200;   // minus the 200 returned
$expCogs = $netCogs($ops, $unit, $retCost);
$get = function ($class, $method, array $payload = [], array $args = []) { [$c, $b] = call($class, $method, $payload, 'GET', $args); return [$c, json_decode($b, true), $b]; };

$req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => $D(1), 'to' => $D(31)]); $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
$pl = json_decode(app(RC::class)->ProfitAndLoss($req)->getContent(), true)['data'];
check("P&L: COGS (FIFO col) = oracle ".round($expCogs, 2), $near($pl['product_cost_fifo'], $expCogs), (string) $pl['product_cost_fifo']);
check('P&L: COGS (average col) = oracle, same number: one method, one profit', $near($pl['averagecost'], $expCogs) && $near($pl['product_cost_fifo'], $pl['averagecost'], 0.0001), (string) $pl['averagecost']);
check('P&L: profit(FIFO) == profit(average)', $near($pl['profit_fifo'], $pl['profit_average_cost'], 0.0001), json_encode([$pl['profit_fifo'], $pl['profit_average_cost']]));

[$c, $pr, $raw] = $get(PRC::class, 'index', ['from' => $D(1), 'to' => $D(31), 'limit' => -1], ['product']);
check('Profit report (by product) answers', $c == 200 && isset($pr['kpis']), "$c ".substr($raw, 0, 200));
check('Profit report: total COGS = oracle = P&L COGS', $near($pr['kpis']['cost'], $expCogs) && $near($pr['kpis']['cost'], $pl['product_cost_fifo']), json_encode($pr['kpis']));
check('Profit report: revenue = sales - returns', $near($pr['kpis']['revenue'], $revenue), (string) $pr['kpis']['revenue']);
foreach (['X' => $X, 'Y' => $Y] as $tag => $pid) {
    $row = collect($pr['rows'])->firstWhere('label', "CST-$tag");
    check("Profit report: product $tag cost = oracle ".round($netCogs($ops, $unit, $retCost, null, $pid), 2), $row && $near($row->cost ?? $row['cost'], $netCogs($ops, $unit, $retCost, null, $pid)), json_encode($row));
}
foreach (['warehouse' => null, 'date' => null, 'customer' => null, 'category' => null, 'unit' => null] as $dim => $_) {
    [$c, $d, $raw] = $get(PRC::class, 'index', ['from' => $D(1), 'to' => $D(31), 'limit' => -1], [$dim]);
    check("Profit report by $dim: HTTP 200 and total COGS = oracle", $c == 200 && $near($d['kpis']['cost'], $expCogs) && $near(collect($d['rows'])->sum(fn ($r) => $r['cost']), $expCogs), "$c ".substr($raw, 0, 160));
}
[$c, $d] = $get(PRC::class, 'index', ['from' => $D(1), 'to' => $D(31), 'limit' => -1], ['warehouse']);
foreach ([1, 2] as $wh) {
    $name = DB::table('warehouses')->where('id', $wh)->value('name'); $row = collect($d['rows'])->firstWhere('label', $name);
    check("Profit report: warehouse $wh COGS = oracle ".round($netCogs($ops, $unit, $retCost, $wh), 2), $row && $near($row['cost'], $netCogs($ops, $unit, $retCost, $wh)), json_encode($row));
}

// stock value across reports: oracle qty x avg
$expValue = []; foreach ([$X, $Y] as $pid) { foreach ([1, 2] as $wh) { $s = $o->st["$pid:$wh"] ?? null; if ($s) { $expValue[$pid][$wh] = $s['q'] * $s['avg']; } } }
$expTotal = array_sum(array_map('array_sum', $expValue));
[$c, $sv] = $get(RC::class, 'stock_inventory_valuation', ['limit' => -1, 'search' => 'CST-']);
$rows = collect($sv['reports'] ?? []);
check('stock_inventory_valuation answers', $c == 200 && $rows->count() >= 3, "$c rows ".$rows->count());
foreach ([$X => 'CSTX', $Y => 'CSTY'] as $pid => $code) { foreach ([1, 2] as $wh) {
    if (! isset($expValue[$pid][$wh])) { continue; }
    $wn = DB::table('warehouses')->where('id', $wh)->value('name'); $r = $rows->first(fn ($r) => $r['sku'] === $code && $r['warehouse'] === $wn);
    check("stock_inventory_valuation: $code @ wh $wh value = ".round($expValue[$pid][$wh], 2), $r && $near($r['stock_value_cost'], $expValue[$pid][$wh]), json_encode($r));
} }
[$c, $iv] = $get(RC::class, 'inventory_valuation_summary', ['limit' => -1, 'search' => 'CST-']);
check('inventory_valuation_summary: asset value = oracle total '.round($expTotal, 2), $c == 200 && $near($iv['summary']['asset_value'], $expTotal), json_encode($iv['summary'] ?? $iv));
$ivRows = collect($iv['data'] ?? $iv['products'] ?? []);

// company-wide stock value (all products): balances-based, must be the same in every place it is shown
$allValue = (float) DB::table('product_warehouse as pw')->join('inventory_cost_balances as b', fn ($j) => $j->on('b.product_id', '=', 'pw.product_id')->whereRaw('b.variant_key = IFNULL(pw.product_variant_id,0)')->on('b.warehouse_id', '=', 'pw.warehouse_id'))
    ->whereNull('pw.deleted_at')->where('pw.qte', '>', 0)->sum(DB::raw('pw.qte * b.avg_cost'));
$dash = (new DASH)->StockValue(0, DB::table('warehouses')->pluck('id')->all());
check('Dashboard stock value (by cost) = sum(qty x average) over all products', $near($dash['by_cost'], $allValue, 0.05), json_encode([$dash['by_cost'], $allValue]));
[$c, $t, $raw] = $get(TODAY::class, 'index');
check('Today summary stock at cost = Dashboard stock value (services excluded, negative rows aside)', $c == 200 && $near($t['stock']['at_cost'], $dash['by_cost'], 1.0), json_encode([$t['stock']['at_cost'] ?? null, $dash['by_cost']]));
[$c, $wc] = $get(RC::class, 'Warhouse_Count_Stock');
check('Warehouse stock report cost total = Dashboard stock value', $c == 200 && $near(array_sum($wc['value_cost']), $dash['by_cost'], 0.05), json_encode([array_sum($wc['value_cost'] ?? []), $dash['by_cost']]));

// analytics: closing - opening stock at cost across the month equals the oracle change of X+Y (all other products do not move)
[$c, $an, $raw] = $get(RC::class, 'analyticsSummary', ['from' => $D(1), 'to' => $D(31)]);
$anD = $an['data'] ?? $an;
check('analyticsSummary answers', $c == 200 && isset($anD['closing_stock_purchase_price']), "$c ".substr($raw, 0, 200));
$openXY = 100 * 8.0;   // opening seed of X at master cost 8 (dated at its first movement, 2031-01-02); before 2031-01-01 nothing
check('analytics: closing stock at cost - opening stock at cost = value of X+Y at month end - value before',
    $near($anD['closing_stock_purchase_price'] - $anD['opening_stock_purchase_price'], $expTotal - 0.0, 0.05),
    json_encode([$anD['closing_stock_purchase_price'] ?? null, $anD['opening_stock_purchase_price'] ?? null, $expTotal]));


// ---------------------------------------------------------------- audit stability: master cost cannot move history -------------------------------------
$snap = [$pl['product_cost_fifo'], $pr['kpis']['cost'], $expTotal, $dash['by_cost']];
DB::table('products')->whereIn('id', [$X, $Y])->update(['cost' => 999]);
$pl2 = json_decode(app(RC::class)->ProfitAndLoss($req)->getContent(), true)['data'];
[$c, $pr2] = $get(PRC::class, 'index', ['from' => $D(1), 'to' => $D(31), 'limit' => -1], ['product']);
$dash2 = (new DASH)->StockValue(0, DB::table('warehouses')->pluck('id')->all());
check('master cost 8/15 -> 999: P&L COGS, profit report COGS, stock value unchanged', $near($pl2['product_cost_fifo'], $snap[0], 0.0001) && $near($pr2['kpis']['cost'], $snap[1], 0.0001) && $near($dash2['by_cost'], $snap[3], 0.0001));
DB::table('products')->where('id', $X)->update(['cost' => 8]); DB::table('products')->where('id', $Y)->update(['cost' => 15]);

// ---------------------------------------------------------------- an edit re-costs history, and says so ------------------------------------------------
$beforeCorr = DB::table('inventory_cost_corrections')->count();
$d2 = DB::table('purchase_details')->where('purchase_id', $p2)->first();
[$c, $b] = call(CT_P, 'update', pHdr(1, ['date' => $D(4), 'GrandTotal' => 100 * 14, 'details' => [plm($X, 100, 14, ['id' => $d2->id])]]), 'PUT', [$p2]);
check('edit GRN X #2 cost 12 -> 14 accepted', $c == 200, "$c $b");
$ops['gX2']['base_cost'] = 14;
Svc::ensureFresh(0);                 // ttl 0 = verify now (this is what any report does at most every 30 s)
[$unit, $retCost, $o] = $verifyAgainstOracle('after GRN edit');
check('the edit wrote correction rows for the sales it re-costed', DB::table('inventory_cost_corrections')->count() > $beforeCorr, (string) DB::table('inventory_cost_corrections')->count());

// delete a sale
[$c] = destroySale($ids['sX1']['sale']); check('delete sale sX1 accepted', $c == 200);
unset($ops['sX1'], $ids['sX1']);
Svc::ensureFresh(0);
[$unit, $retCost, $o] = $verifyAgainstOracle('after sale delete');
$plAfter = json_decode(app(RC::class)->ProfitAndLoss($req)->getContent(), true)['data'];
check('P&L COGS follows the delete: '.round($netCogs($ops, $unit, $retCost), 2), $near($plAfter['product_cost_fifo'], $netCogs($ops, $unit, $retCost)), (string) $plAfter['product_cost_fifo']);
check('after all this nothing is out of sync', (new Svc)->verify(false)['dirty'] === [], json_encode((new Svc)->verify(false)));

// incremental == from scratch: wipe the derived tables (seeds/stamps are part of state, keep them) and rebuild
$hash = fn () => md5(json_encode(DB::table('inventory_cost_ledger')->orderBy('source_type')->orderBy('source_id')->get(['source_type', 'source_id', 'warehouse_id', 'qty_delta', 'unit_cost', 'value_delta', 'balance_qty', 'balance_value'])->all()));
$h1 = $hash();
DB::table('inventory_cost_ledger')->delete(); DB::table('inventory_cost_balances')->delete(); DB::table('inventory_cost_keys')->delete();
(new Svc)->syncProducts(DB::table('products')->pluck('id')->all());
check('a full rebuild from the documents gives exactly the incrementally maintained ledger', $hash() === $h1);
finish('Costing real-world run vs independent oracle and all reports');
