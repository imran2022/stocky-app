<?php
// Moving Average costing STRESS test (derived from the Audit B5 stock stress): a seeded random sequence of purchases at random costs,
// sales, POS sales, box/piece units, status flips, edits, deletes, returns, adjustments, approved transfers and damages is run through the
// real controllers. Costing is ON the whole time and is refreshed at random moments exactly like a report would. After every refresh:
//   C1 nothing is out of sync           C2 costed quantity == product_warehouse.qte for every product/warehouse
//   C3 no unexplained stock appeared     C4 value conservation: sum(value_delta) == closing value (keys that never went negative)
//   C5 every sale line has a cost >= 0 and is priced inside the min..max cost the product ever had
// At the end: an incremental ledger == a from-scratch rebuild, and every report's COGS / stock value == the ledger.
// usage: php build_costing_stress.php [seed=1] [steps=60]
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SR;
use App\Services\Custom\ProductMovementLedgerService as Ledger;
use App\Services\Costing\CostingReader;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

$seed = (int) ($argv[1] ?? 1); $steps = (int) ($argv[2] ?? 60);
mt_srand($seed);
try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('units')->insertOrIgnore(['id' => 2, 'name' => 'Box', 'ShortName' => 'bx', 'base_unit' => 1, 'operator' => '*', 'operator_value' => 12, 'is_active' => 1]);

$PRODUCTS = [1, 2, 3, 4]; $WH = [1, 3];
$sales = [];      // id => [pid, wh, qty]
$purchases = [];  // id => [pid, wh, qty]
$adjust = [];     // id => wh
$transfers = [];  // id => true
$srets = []; $prets = []; $damages = [];
$log = [];
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$keyOf = fn (array $a) => $a ? array_keys($a)[mt_rand(0, count($a) - 1)] : null;

$GLOBALS['baseDetailMax'] = (int) DB::table('sale_details')->max('id');
$checkpoints = 0;
$tie = function ($force = false) use ($PRODUCTS) {
    global $checkpoints;
    if (! $force && mt_rand(0, 2) !== 0) { return null; }        // refresh at random moments, like a report being opened
    $checkpoints++;
    Svc::ensureFresh(0);
    $svc = new Svc;
    $dirty = $svc->verify(false)['dirty'];
    if ($dirty) { return 'C1 out of sync after refresh: products '.implode(',', $dirty); }
    foreach (DB::table('product_warehouse')->whereNull('deleted_at')->where('manage_stock', 1)->whereIn('product_id', $PRODUCTS)->get() as $pw) {
        $b = DB::table('inventory_cost_balances')->where('product_id', $pw->product_id)->where('variant_key', (int) $pw->product_variant_id)->where('warehouse_id', $pw->warehouse_id)->first();
        if (abs((float) $pw->qte - (float) ($b->qty ?? 0)) > 0.0005) { return "C2 product {$pw->product_id} wh {$pw->warehouse_id}: on-hand {$pw->qte} vs costed ".($b->qty ?? 'none'); }
    }
    $bad = DB::table('inventory_cost_seeds')->whereIn('product_id', $PRODUCTS)->where('reason', '!=', 'opening_unexplained')->first();
    if ($bad) { return "C3 unexplained stock appeared: product {$bad->product_id} wh {$bad->warehouse_id} qty {$bad->qty_delta} ({$bad->reason})"; }
    foreach (DB::table('inventory_cost_balances')->whereIn('product_id', $PRODUCTS)->get() as $b) {
        $rows = DB::table('inventory_cost_ledger')->where('product_id', $b->product_id)->where('variant_key', $b->variant_key)->where('warehouse_id', $b->warehouse_id)->get();
        if ($rows->min('balance_qty') < -0.00005) { continue; }
        $net = (float) $rows->sum('value_delta');
        if (abs($net - (float) $b->value) > 0.05 + 0.0001 * $rows->count()) { return "C4 conservation broken: product {$b->product_id} wh {$b->warehouse_id}: sum(value) $net vs closing {$b->value}"; }
    }
    foreach (DB::table('inventory_cost_ledger')->whereIn('product_id', $PRODUCTS)->where('source_type', 'sale')->where('source_id', '>', $GLOBALS['baseDetailMax'])->get() as $l) {
        if ($l->unit_cost < 0 || $l->unit_cost > 501) { return "C5 sale line {$l->source_id} has an impossible unit cost {$l->unit_cost}"; }
    }
    return null;
};

$ops = [
    'purchase' => function () use (&$purchases, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 20)];
        [$c, $b, $id] = mkPurchase($pid, $q, mt_rand(60, 160) / 10, $wh); if ($c == 200) { $purchases[$id] = [$pid, $wh, $q]; }
        return "purchase p$pid wh$wh q$q -> $c";
    },
    'sale' => function () use (&$sales, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 8)];
        [$c, $b, $id] = mkSale([line($pid, $q, 100)], ['warehouse_id' => $wh]); if ($c == 200 && $id) { $sales[$id] = [$pid, $wh, $q]; }
        return "sale p$pid wh$wh q$q -> $c";
    },
    'pos' => function () use (&$sales, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 6)];
        [$c, $b] = call(App\Http\Controllers\PosController::class, 'CreatePOS', ['client_id' => 1, 'warehouse_id' => $wh, 'date' => date('Y-m-d'), 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'discount_Method' => '2', 'shipping' => 0, 'GrandTotal' => $q * 100, 'notes' => 'b5',
            'payments' => [['amount' => $q * 100, 'payment_method_id' => 2, 'account_id' => null, 'change' => 0]], 'details' => [line($pid, $q, 100)]]);
        if ($c == 200) { $sales[(int) DB::table('sales')->max('id')] = [$pid, $wh, $q]; }
        return "pos p$pid wh$wh q$q -> $c";
    },
    'sale_box' => function () use (&$sales, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 2)];
        [$c, $b, $id] = mkSale([line($pid, $q, 1200, ['sale_unit_id' => 2])], ['warehouse_id' => $wh]); if ($c == 200 && $id) { $sales[$id] = [$pid, $wh, $q]; }
        return "sale(box x12) p$pid wh$wh q$q -> $c";
    },
    'purchase_box' => function () use (&$purchases, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 2)];
        [$c, $b] = call(CT_P, 'store', pHdr($wh, ['GrandTotal' => $q * 132, 'details' => [plm($pid, $q, 132, ['purchase_unit_id' => 2])]]));
        if ($c == 200) { $purchases[(int) DB::table('purchases')->max('id')] = [$pid, $wh, $q]; }
        return "purchase(box x12) p$pid wh$wh q$q -> $c";
    },
    'sale_status' => function () use (&$sales, $keyOf, $pick) {
        $id = $keyOf($sales); if (! $id) { return 'sale_status skipped'; }
        $r = DB::table('sales')->where('id', $id)->first(); $d = DB::table('sale_details')->where('sale_id', $id)->first();
        if ($d->sale_unit_id != 1) { return 'sale_status skipped (box)'; } $st = $pick(['completed', 'pending', 'ordered']);
        [$c] = updSale($id, [line($d->product_id, (int) $d->quantity, 100, ['id' => $d->id])], ['warehouse_id' => $r->warehouse_id, 'statut' => $st]);
        return "sale_status #$id {$r->statut}->$st -> $c";
    },
    'purchase_status' => function () use (&$purchases, $keyOf, $pick) {
        $id = $keyOf($purchases); if (! $id) { return 'purchase_status skipped'; }
        $r = DB::table('purchases')->where('id', $id)->first(); $d = DB::table('purchase_details')->where('purchase_id', $id)->first();
        if ($d->purchase_unit_id != 1) { return 'purchase_status skipped (box)'; } $st = $pick(['received', 'pending', 'ordered']);
        [$c] = call(CT_P, 'update', pHdr($r->warehouse_id, ['statut' => $st, 'GrandTotal' => $d->quantity * 10, 'details' => [plm($d->product_id, (int) $d->quantity, 10, ['id' => $d->id])]]), 'PUT', [$id]);
        return "purchase_status #$id {$r->statut}->$st -> $c";
    },
    'adjust_edit' => function () use (&$adjust, $keyOf, $pick, $PRODUCTS) {
        $id = $keyOf($adjust); if (! $id) { return 'adjust_edit skipped'; }
        [$pid, $q, $type] = [$pick($PRODUCTS), mt_rand(1, 6), $pick(['add', 'sub'])];
        [$c] = call(CT_A, 'update', adjPayload($adjust[$id], $type, $q, $pid), 'PUT', [$id]);
        return "adjust_edit #$id $type p$pid q$q -> $c";
    },
    'transfer_edit' => function () use (&$transfers, $keyOf, $pick, $PRODUCTS) {
        $id = $keyOf($transfers); if (! $id) { return 'transfer_edit skipped'; }
        [$pid, $q, $st] = [$pick($PRODUCTS), mt_rand(1, 5), $pick(['completed', 'sent', 'pending'])]; $t = DB::table('transfers')->where('id', $id)->first();
        [$c] = call(CT_T, 'update', trPayload((int) $t->from_warehouse_id, (int) $t->to_warehouse_id, $pid, $q, $st), 'PUT', [$id]);
        return "transfer_edit #$id p$pid q$q ->$st -> $c";
    },
    'damage' => function () use ($pick, $PRODUCTS, $WH, &$damages) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 3)];
        [$c] = call(App\Http\Controllers\DamageController::class, 'store', ['warehouse_id' => $wh, 'date' => date('Y-m-d'), 'notes' => 'b5', 'details' => [['product_id' => $pid, 'product_variant_id' => null, 'quantity' => $q]]]);
        if ($c == 200) { $damages[(int) DB::table('damages')->max('id')] = 1; }
        return "damage p$pid wh$wh q$q -> $c";
    },
    'damage_delete' => function () use (&$damages, $keyOf) {
        $id = $keyOf($damages); if (! $id) { return 'damage_delete skipped'; }
        [$c] = call(App\Http\Controllers\DamageController::class, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($damages[$id]); }
        return "damage_delete #$id -> $c";
    },
    'sale_pending' => function () use (&$sales, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 8)];
        [$c, $b, $id] = mkSale([line($pid, $q, 100)], ['warehouse_id' => $wh, 'statut' => 'pending']); if ($c == 200 && $id) { $sales[$id] = [$pid, $wh, $q]; }
        return "sale(pending) p$pid wh$wh q$q -> $c";
    },
    'sale_edit' => function () use (&$sales, $keyOf) {
        $id = $keyOf($sales); if (! $id) { return 'sale_edit skipped'; }
        if (DB::table('sale_details')->where('sale_id', $id)->value('sale_unit_id') != 1) { return 'sale_edit skipped (box)'; }
        [$pid, $wh, $q] = $sales[$id]; $nq = mt_rand(1, 8);
        $st = DB::table('sales')->where('id', $id)->value('statut');
        [$c, $b] = updSale($id, [line($pid, $nq, 100, ['id' => detIds($id)[0] ?? 0])], ['warehouse_id' => $wh, 'statut' => $st === 'pending' && mt_rand(0, 1) ? 'completed' : $st]);
        if ($c == 200) { $sales[$id][2] = $nq; }
        return "sale_edit #$id q$q->$nq -> $c";
    },
    'sale_delete' => function () use (&$sales, $keyOf) {
        $id = $keyOf($sales); if (! $id) { return 'sale_delete skipped'; }
        [$c] = destroySale($id); if ($c == 200) { unset($sales[$id]); }
        return "sale_delete #$id -> $c";
    },
    'sale_return' => function () use (&$sales, $keyOf, &$srets) {
        $id = $keyOf($sales); if (! $id) { return 'sale_return skipped'; }
        if (DB::table('sale_details')->where('sale_id', $id)->value('sale_unit_id') != 1) { return 'sale_return skipped (box)'; }
        [$pid, $wh, $q] = $sales[$id]; $rq = mt_rand(1, max(1, $q));
        $lines = [array_merge(line($pid, $rq, 100, ['imei_number' => null]))];
        [$c, $b] = call(SR::class, 'store', ['client_id' => 2, 'warehouse_id' => $wh, 'sale_id' => $id, 'date' => date('Y-m-d'), 'statut' => 'received', 'notes' => 's', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $rq * 100, 'details' => $lines]);
        if ($c == 200) { $srets[] = (int) DB::table('sale_returns')->max('id'); }
        return "sale_return of #$id p$pid q$rq -> $c";
    },
    'sale_return_delete' => function () use (&$srets) {
        if (! $srets) { return 'sr_delete skipped'; }
        $i = mt_rand(0, count($srets) - 1); $id = $srets[$i];
        [$c] = call(SR::class, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { array_splice($srets, $i, 1); }
        return "sale_return_delete #$id -> $c";
    },
    'purchase_edit' => function () use (&$purchases, $keyOf) {
        $id = $keyOf($purchases); if (! $id) { return 'purchase_edit skipped'; }
        if (DB::table('purchase_details')->where('purchase_id', $id)->value('purchase_unit_id') != 1) { return 'purchase_edit skipped (box)'; }
        [$pid, $wh, $q] = $purchases[$id]; $nq = mt_rand(1, 20);
        $did = DB::table('purchase_details')->where('purchase_id', $id)->value('id');
        [$c] = call(CT_P, 'update', pHdr($wh, ['GrandTotal' => $nq * 10, 'details' => [plm($pid, $nq, 10, ['id' => $did])]]), 'PUT', [$id]);
        if ($c == 200) { $purchases[$id][2] = $nq; }
        return "purchase_edit #$id q$q->$nq -> $c";
    },
    'purchase_delete' => function () use (&$purchases, $keyOf) {
        $id = $keyOf($purchases); if (! $id) { return 'purchase_delete skipped'; }
        [$c] = call(CT_P, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($purchases[$id]); }
        return "purchase_delete #$id -> $c";
    },
    'purchase_return' => function () use (&$purchases, $keyOf, &$prets) {
        $id = $keyOf($purchases); if (! $id) { return 'purchase_return skipped'; }
        if (DB::table('purchase_details')->where('purchase_id', $id)->value('purchase_unit_id') != 1) { return 'purchase_return skipped (box)'; }
        [$pid, $wh, $q] = $purchases[$id]; $rq = mt_rand(1, max(1, $q));
        [$c] = call(CT_R, 'store', prPayload($id, $pid, $rq, $wh));
        if ($c == 200) { $prets[] = (int) DB::table('purchase_returns')->max('id'); }
        return "purchase_return of #$id p$pid q$rq -> $c";
    },
    'purchase_return_delete' => function () use (&$prets) {
        if (! $prets) { return 'pr_delete skipped'; }
        $i = mt_rand(0, count($prets) - 1); $id = $prets[$i];
        [$c] = call(CT_R, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { array_splice($prets, $i, 1); }
        return "purchase_return_delete #$id -> $c";
    },
    'adjust' => function () use (&$adjust, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 6)]; $type = $pick(['add', 'sub']);
        [$c] = call(CT_A, 'store', adjPayload($wh, $type, $q, $pid)); if ($c == 200) { $adjust[(int) DB::table('adjustments')->max('id')] = $wh; }
        return "adjust $type p$pid wh$wh q$q -> $c";
    },
    'adjust_delete' => function () use (&$adjust, $keyOf) {
        $id = $keyOf($adjust); if (! $id) { return 'adjust_delete skipped'; }
        [$c] = call(CT_A, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($adjust[$id]); }
        return "adjust_delete #$id -> $c";
    },
    'transfer' => function () use (&$transfers, $pick, $PRODUCTS, $WH) {
        [$pid, $q] = [$pick($PRODUCTS), mt_rand(1, 5)]; [$f, $t] = mt_rand(0, 1) ? [1, 3] : [3, 1];
        [$c] = call(CT_T, 'store', trPayload($f, $t, $pid, $q)); if ($c == 200) { $tid = (int) DB::table('transfers')->max('id'); $transfers[$tid] = true; if (mt_rand(0, 3) > 0) { call(CT_T, 'approve', [], 'POST', [$tid]); } }
        return "transfer p$pid $f->$t q$q -> $c";
    },
    'transfer_delete' => function () use (&$transfers, $keyOf) {
        $id = $keyOf($transfers); if (! $id) { return 'transfer_delete skipped'; }
        [$c] = call(CT_T, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($transfers[$id]); }
        return "transfer_delete #$id -> $c";
    },
];
$weights = ['sale_status' => 2, 'purchase_status' => 2, 'adjust_edit' => 1, 'transfer_edit' => 2, 'damage' => 1, 'damage_delete' => 1, 'pos' => 3, 'sale_box' => 2, 'purchase_box' => 2, 'purchase' => 6, 'sale' => 6, 'sale_pending' => 1, 'sale_edit' => 3, 'sale_delete' => 2, 'sale_return' => 2, 'sale_return_delete' => 1,
    'purchase_edit' => 2, 'purchase_delete' => 1, 'purchase_return' => 2, 'purchase_return_delete' => 1, 'adjust' => 2, 'adjust_delete' => 1, 'transfer' => 2, 'transfer_delete' => 1];
$bag = []; foreach ($weights as $k => $w) { for ($i = 0; $i < $w; $i++) { $bag[] = $k; } }

// opening balance so early sales/returns have stock to move
foreach ($PRODUCTS as $p) { foreach ($WH as $w) { mkPurchase($p, 30, 10, $w); } }
Svc::setMethod('moving_average'); Svc::forgetMethodCache();
$broken = $tie(true); check("seed $seed: opening state is costed and ties", $broken === null, (string) $broken);

for ($i = 1; $i <= $steps && $broken === null; $i++) {
    try { $desc = $ops[$pick($bag)](); } catch (Throwable $e) { $desc = 'EXC '.get_class($e).': '.substr($e->getMessage(), 0, 120); }
    $log[] = "$i. $desc";
    $broken = $tie();
}
check("seed $seed: costing invariants C1-C5 held at every one of $checkpoints refreshes over $steps steps", $broken === null, $broken ? "$broken\n    last ops: ".implode(' | ', array_slice($log, -4)) : '');
$broken2 = $tie(true);
check("seed $seed: final refresh clean", $broken2 === null, (string) $broken2);
$ok = count(array_filter($log, fn ($l) => str_ends_with($l, '-> 200')));
check("seed $seed: the run really exercised the controllers ($ok of ".count($log)." steps succeeded)", $ok >= count($log) / 3, "$ok/".count($log));

// incremental ledger == from-scratch rebuild
$hash = fn () => md5(json_encode(DB::table('inventory_cost_ledger')->orderBy('source_type')->orderBy('source_id')->get(['source_type', 'source_id', 'warehouse_id', 'qty_delta', 'unit_cost', 'value_delta', 'balance_qty', 'balance_value'])->all()));
$balHash = fn () => md5(json_encode(DB::table('inventory_cost_balances')->orderBy('product_id')->orderBy('variant_key')->orderBy('warehouse_id')->get(['product_id', 'variant_key', 'warehouse_id', 'qty', 'value', 'avg_cost'])->all()));
$h1 = $hash(); $b1 = $balHash(); $b1rows = DB::table('inventory_cost_balances')->orderBy('product_id')->orderBy('variant_key')->orderBy('warehouse_id')->get(['product_id', 'variant_key', 'warehouse_id', 'qty', 'value', 'avg_cost'])->map(fn ($r) => (array) $r)->all();
DB::table('inventory_cost_ledger')->delete(); DB::table('inventory_cost_balances')->delete(); DB::table('inventory_cost_keys')->delete();
(new Svc)->syncProducts(DB::table('products')->pluck('id')->all());
check("seed $seed: incremental ledger == full rebuild", $hash() === $h1);
$b2rows = DB::table('inventory_cost_balances')->orderBy('product_id')->orderBy('variant_key')->orderBy('warehouse_id')->get(['product_id', 'variant_key', 'warehouse_id', 'qty', 'value', 'avg_cost'])->map(fn ($r) => (array) $r)->all();
$diff = ''; foreach ($b1rows as $i => $r) { if ($r != ($b2rows[$i] ?? null)) { $diff = 'incremental '.json_encode($r).' vs rebuilt '.json_encode($b2rows[$i] ?? null); break; } }
check("seed $seed: incremental balances == full rebuild", $balHash() === $b1, $diff.' (rows '.count($b1rows).' vs '.count($b2rows).')');

// reports vs ledger over the whole run
$req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]); $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
$pl = json_decode(app(App\Http\Controllers\ReportController::class)->ProfitAndLoss($req)->getContent(), true)['data'];
$ledgerCogs = -(float) DB::table('inventory_cost_ledger')->whereIn('source_type', ['sale', 'sale_return'])->where('occurred_at', '>=', date('Y-m-d').' 00:00:00')->where('occurred_at', '<=', date('Y-m-d').' 23:59:59')->sum('value_delta');
check("seed $seed: P&L COGS ({$pl['product_cost_fifo']}) == ledger sales - returns ($ledgerCogs)", abs($pl['product_cost_fifo'] - $ledgerCogs) < 0.02 && abs($pl['averagecost'] - $ledgerCogs) < 0.02);
[$c, $b] = call(App\Http\Controllers\ProfitReportController::class, 'index', ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'limit' => -1], 'GET', ['product']); $pr = json_decode($b, true);
check("seed $seed: Profit report COGS == P&L COGS", $c == 200 && abs($pr['kpis']['cost'] - $pl['product_cost_fifo']) < 0.02, json_encode($pr['kpis'] ?? $b));
$sv = CostingReader::stockValue(null, DB::table('warehouses')->pluck('id')->all());
$dash = (new App\Http\Controllers\DashboardController)->StockValue(0, DB::table('warehouses')->pluck('id')->all());
check("seed $seed: Dashboard stock value == costed stock value", abs($dash['by_cost'] - $sv['value']) < 0.05, json_encode([$dash['by_cost'], $sv['value']]));
$rq = Illuminate\Http\Request::create('/api/x', 'GET', ['limit' => -1]); $rq->setUserResolver(fn () => $u); app()->instance('request', $rq);   // (call() truncates big bodies)
$resp = app(App\Http\Controllers\ReportController::class)->stock_inventory_valuation($rq); $c = $resp->getStatusCode(); $iv = json_decode($resp->getContent(), true);
$rowsSum = collect($iv['reports'] ?? [])->sum(fn ($r) => $r['current_quantity'] > 0 ? $r['stock_value_cost'] : 0);
check("seed $seed: stock_inventory_valuation total (positive rows) == costed stock value", $c == 200 && abs($rowsSum - $sv['value']) < 0.1, json_encode([$rowsSum, $sv['value']]));
if (getenv('B5_VERBOSE')) { echo implode("\n", $log), "\n"; }
finish("Costing stress (seed $seed)");
