<?php
// Audit Batch 5 (S4): variant products. Seeded random purchases, sales, POS sales, returns, adjustments, transfers and deletes on
// a product with 3 variants; after every step each VARIANT's live stock must equal what the documents say (Movement Ledger).
// usage: php audit_b5_variant_stress.php [seed=1] [steps=70]
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SR;
use App\Services\Custom\ProductMovementLedgerService as Ledger;
use Illuminate\Support\Facades\DB;

$seed = (int) ($argv[1] ?? 1); $steps = (int) ($argv[2] ?? 70);
mt_srand($seed);
DB::table('settings')->update(['allow_overselling' => 1]);
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$P = 5; $V = [1, 2, 3]; $WH = [1, 3];
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$sales = []; $purchases = []; $adj = []; $tr = []; $srets = []; $log = [];
$vline = fn ($v, $q, $price = 100, $x = []) => array_merge(line($P, $q, $price, ['product_variant_id' => $v]), $x);
$vpl = fn ($v, $q, $c) => plm($P, $q, $c, ['product_variant_id' => $v]);

$tie = function () use ($P, $V) {
    foreach ($V as $v) {
        foreach (Ledger::build($P, $v)['reconciliation'] as $row) {
            if (! $row['reconciled'] && ! ($row['row_missing'] && abs($row['ledger_balance']) < 1e-6)) { return "variant $v warehouse {$row['warehouse_id']}: stock {$row['actual_qte']} vs documents {$row['ledger_balance']}"; }
        }
    }
    return null;
};
$ops = [
    'purchase' => function () use (&$purchases, $pick, $V, $WH, $vpl) { [$v, $wh, $q] = [$pick($V), $pick($WH), mt_rand(1, 15)];
        [$c] = call(CT_P, 'store', pHdr($wh, ['GrandTotal' => $q * 10, 'details' => [$vpl($v, $q, 10)]])); if ($c == 200) { $purchases[(int) DB::table('purchases')->max('id')] = [$v, $wh, $q]; } return "purchase v$v wh$wh q$q -> $c"; },
    'sale' => function () use (&$sales, $pick, $V, $WH, $vline) { [$v, $wh, $q] = [$pick($V), $pick($WH), mt_rand(1, 6)];
        [$c, $b, $id] = mkSale([$vline($v, $q)], ['warehouse_id' => $wh]); if ($c == 200 && $id) { $sales[$id] = [$v, $wh, $q]; } return "sale v$v wh$wh q$q -> $c"; },
    'pos' => function () use (&$sales, $pick, $V, $WH, $vline) { [$v, $wh, $q] = [$pick($V), $pick($WH), mt_rand(1, 5)];
        [$c] = call(App\Http\Controllers\PosController::class, 'CreatePOS', ['client_id' => 1, 'warehouse_id' => $wh, 'date' => date('Y-m-d'), 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2', 'shipping' => 0,
            'GrandTotal' => $q * 100, 'notes' => 'b5', 'payments' => [['amount' => $q * 100, 'payment_method_id' => 2, 'account_id' => null, 'change' => 0]], 'details' => [$vline($v, $q)]]);
        if ($c == 200) { $sales[(int) DB::table('sales')->max('id')] = [$v, $wh, $q]; } return "pos v$v wh$wh q$q -> $c"; },
    'sale_edit' => function () use (&$sales, $pick, $vline) { if (! $sales) { return 'skip'; } $id = array_keys($sales)[mt_rand(0, count($sales) - 1)]; [$v, $wh] = $sales[$id]; $nv = $pick([1, 2, 3]); $nq = mt_rand(1, 6);
        $r = DB::table('sales')->where('id', $id)->first(); $d = DB::table('sale_details')->where('sale_id', $id)->first();
        [$c] = updSale($id, [$vline($nv, $nq, 100, ['id' => $d->id])], ['warehouse_id' => $wh, 'statut' => $r->statut]); if ($c == 200) { $sales[$id][0] = $nv; $sales[$id][2] = $nq; } return "sale_edit #$id v$v->v$nv q$nq -> $c"; },
    'sale_delete' => function () use (&$sales) { if (! $sales) { return 'skip'; } $id = array_keys($sales)[mt_rand(0, count($sales) - 1)]; [$c] = destroySale($id); if ($c == 200) { unset($sales[$id]); } return "sale_delete #$id -> $c"; },
    'sale_return' => function () use (&$sales, &$srets, $vline) { if (! $sales) { return 'skip'; } $id = array_keys($sales)[mt_rand(0, count($sales) - 1)]; [$v, $wh, $q] = $sales[$id]; $rq = mt_rand(1, max(1, $q));
        [$c] = call(SR::class, 'store', ['client_id' => 2, 'warehouse_id' => $wh, 'sale_id' => $id, 'date' => date('Y-m-d'), 'statut' => 'received', 'notes' => 's', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $rq * 100, 'details' => [$vline($v, $rq, 100, ['imei_number' => null])]]);
        if ($c == 200) { $srets[] = (int) DB::table('sale_returns')->max('id'); } return "sale_return of #$id v$v q$rq -> $c"; },
    'sale_return_delete' => function () use (&$srets) { if (! $srets) { return 'skip'; } $i = mt_rand(0, count($srets) - 1); [$c] = call(SR::class, 'destroy', [], 'DELETE', [$srets[$i]]); if ($c == 200) { array_splice($srets, $i, 1); } return "sale_return_delete -> $c"; },
    'purchase_edit' => function () use (&$purchases, $vpl) { if (! $purchases) { return 'skip'; } $id = array_keys($purchases)[mt_rand(0, count($purchases) - 1)]; [$v, $wh] = $purchases[$id]; $nq = mt_rand(1, 15);
        $d = DB::table('purchase_details')->where('purchase_id', $id)->first(); $l = $vpl($v, $nq, 10); $l['id'] = $d->id;
        [$c] = call(CT_P, 'update', pHdr($wh, ['GrandTotal' => $nq * 10, 'details' => [$l]]), 'PUT', [$id]); if ($c == 200) { $purchases[$id][2] = $nq; } return "purchase_edit #$id q$nq -> $c"; },
    'purchase_delete' => function () use (&$purchases) { if (! $purchases) { return 'skip'; } $id = array_keys($purchases)[mt_rand(0, count($purchases) - 1)]; [$c] = call(CT_P, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($purchases[$id]); } return "purchase_delete #$id -> $c"; },
    'adjust' => function () use (&$adj, $pick, $V, $WH) { [$v, $wh, $q, $t] = [$pick($V), $pick($WH), mt_rand(1, 5), $pick(['add', 'sub'])];
        $pl = adjPayload($wh, $t, $q, 5); $pl['details'][0]['product_variant_id'] = $v; [$c] = call(CT_A, 'store', $pl); if ($c == 200) { $adj[(int) DB::table('adjustments')->max('id')] = 1; } return "adjust $t v$v wh$wh q$q -> $c"; },
    'adjust_delete' => function () use (&$adj) { if (! $adj) { return 'skip'; } $id = array_keys($adj)[mt_rand(0, count($adj) - 1)]; [$c] = call(CT_A, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($adj[$id]); } return "adjust_delete #$id -> $c"; },
    'transfer' => function () use (&$tr, $pick, $V) { [$v, $q] = [$pick($V), mt_rand(1, 4)]; [$f, $t] = mt_rand(0, 1) ? [1, 3] : [3, 1];
        $pl = trPayload($f, $t, 5, $q); $pl['details'][0]['product_variant_id'] = $v; [$c] = call(CT_T, 'store', $pl); if ($c == 200) { $tr[(int) DB::table('transfers')->max('id')] = 1; } return "transfer v$v $f->$t q$q -> $c"; },
    'transfer_delete' => function () use (&$tr) { if (! $tr) { return 'skip'; } $id = array_keys($tr)[mt_rand(0, count($tr) - 1)]; [$c] = call(CT_T, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { unset($tr[$id]); } return "transfer_delete #$id -> $c"; },
];
$bag = ['purchase', 'purchase', 'purchase', 'sale', 'sale', 'sale', 'pos', 'pos', 'sale_edit', 'sale_edit', 'sale_delete', 'sale_return', 'sale_return', 'sale_return_delete', 'purchase_edit', 'purchase_edit', 'purchase_delete',
    'adjust', 'adjust', 'adjust_delete', 'transfer', 'transfer', 'transfer_delete'];

foreach ($V as $v) { foreach ($WH as $w) { call(CT_P, 'store', pHdr($w, ['GrandTotal' => 200, 'details' => [$vpl($v, 20, 10)]])); } }
$broken = $tie();
check("seed $seed: opening state ties", $broken === null, (string) $broken);
for ($i = 1; $i <= $steps && $broken === null; $i++) {
    try { $desc = $ops[$pick($bag)](); } catch (Throwable $e) { $desc = 'EXC '.get_class($e).': '.substr($e->getMessage(), 0, 120); }
    $log[] = "$i. $desc"; $broken = $tie();
}
if (getenv('B5_VERBOSE')) { echo implode("\n", $log), "\n"; }
check("seed $seed: every variant's stock ties to its documents after every one of $steps steps", $broken === null, $broken ? "$broken\n    last ops: ".implode(' | ', array_slice($log, -4)) : '');
$ok = count(array_filter($log, fn ($l) => str_ends_with($l, '-> 200')));
check("seed $seed: exercised the controllers ($ok of ".count($log)." steps succeeded)", $ok >= count($log) / 3, "$ok/".count($log));
finish("Audit B5 variant stress (seed $seed)");
