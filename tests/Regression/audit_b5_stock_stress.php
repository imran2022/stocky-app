<?php
// Audit Batch 5 (S1): stress test. A seeded random sequence of purchases, sales, edits, deletes, returns, adjustments and
// transfers is run through the real controllers; after EVERY step each product's live stock (product_warehouse.qte) must equal
// the balance the Movement Ledger derives from the documents. The first step that breaks the tie is reported with the op log.
// usage: php audit_b5_stock_stress.php [seed=1] [steps=60]
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SR;
use App\Services\Custom\ProductMovementLedgerService as Ledger;
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

$tie = function () use ($PRODUCTS) {
    foreach ($PRODUCTS as $pid) {
        $r = Ledger::build($pid);
        foreach ($r['reconciliation'] as $row) {
            if (! $row['reconciled'] && ! ($row['row_missing'] && abs($row['ledger_balance']) < 1e-6)) {
                return "product $pid warehouse {$row['warehouse_id']}: stock {$row['actual_qte']} vs ledger {$row['ledger_balance']}";
            }
        }
    }
    return null;
};

$ops = [
    'purchase' => function () use (&$purchases, $pick, $PRODUCTS, $WH) {
        [$pid, $wh, $q] = [$pick($PRODUCTS), $pick($WH), mt_rand(1, 20)];
        [$c, $b, $id] = mkPurchase($pid, $q, 10, $wh); if ($c == 200) { $purchases[$id] = [$pid, $wh, $q]; }
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
        [$c, $b] = call(CT_P, 'store', pHdr($wh, ['GrandTotal' => $q * 120, 'details' => [plm($pid, $q, 120, ['purchase_unit_id' => 2])]]));
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
        [$c] = call(CT_T, 'store', trPayload($f, $t, $pid, $q)); if ($c == 200) { $transfers[(int) DB::table('transfers')->max('id')] = true; }
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
$broken = $tie(); check("seed $seed: opening state ties to ledger", $broken === null, (string) $broken);

for ($i = 1; $i <= $steps && $broken === null; $i++) {
    try { $desc = $ops[$pick($bag)](); } catch (Throwable $e) { $desc = 'EXC '.get_class($e).': '.substr($e->getMessage(), 0, 120); }
    $log[] = "$i. $desc";
    $broken = $tie();
}
check("seed $seed: stock ties to ledger after every one of $steps steps", $broken === null, $broken ? "$broken\n    last ops: ".implode(' | ', array_slice($log, -4)) : '');
$ok = count(array_filter($log, fn ($l) => str_ends_with($l, '-> 200')));
if (getenv('B5_VERBOSE')) { echo implode("\n", $log), "\n"; }
check("seed $seed: the run really exercised the controllers ($ok of ".count($log)." steps succeeded)", $ok >= count($log) / 3, "$ok/".count($log));
finish("Audit B5 stock stress (seed $seed)");
