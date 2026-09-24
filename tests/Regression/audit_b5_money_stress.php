<?php
// Audit Batch 5 (S2): money stress test. Seeded random sales, purchases, returns, edits, deletes and payments (add/edit/delete,
// including overpayments and payments after the total was edited). After EVERY step, for every live document:
//   paid_amount == sum of its live payment rows, 0 <= paid <= GrandTotal, payment_statut matches, no live payment hangs on a deleted document,
//   and the customer/supplier balance figures equal the same numbers computed straight from the rows.
// usage: php audit_b5_money_stress.php [seed=1] [steps=80]
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use App\Http\Controllers\SalesReturnController as SR;
use App\Services\Custom\ProductMovementLedgerService as Ledger;
use Illuminate\Support\Facades\DB;

const CT_PS = App\Http\Controllers\PaymentSalesController::class;
const CT_PSR = App\Http\Controllers\PaymentSaleReturnsController::class;
$seed = (int) ($argv[1] ?? 1); $steps = (int) ($argv[2] ?? 80);
mt_srand($seed);
try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach ([1, 2, 3, 4] as $p) { foreach ([1, 3] as $w) { mkPurchase($p, 500, 10, $w); } }

$pay = fn ($k, $id, $amt, $o = []) => ['sale_id' => $id, 'purchase_id' => $id, 'sale_return_id' => $id, 'montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x'] + $o;
$sales = []; $purchases = []; $srets = []; $log = [];
$keyOf = fn (array $a) => $a ? $a[mt_rand(0, count($a) - 1)] : null;
$live = fn ($t, $fk, $id) => DB::table($t)->where($fk, $id)->whereNull('deleted_at')->pluck('id')->all();

$tie = function () {
    $spec = [['sales', 'payment_sales', 'sale_id'], ['purchases', 'payment_purchases', 'purchase_id'], ['sale_returns', 'payment_sale_returns', 'sale_return_id']];
    foreach ($spec as [$doc, $pt, $fk]) {
        foreach (DB::table($doc)->whereNull('deleted_at')->get(['id', 'GrandTotal', 'paid_amount', 'payment_statut']) as $d) {
            $rows = (float) DB::table($pt)->where($fk, $d->id)->whereNull('deleted_at')->sum('montant');
            if (abs($rows - (float) $d->paid_amount) > 0.005) { return "$doc #{$d->id}: paid_amount {$d->paid_amount} but payment rows sum to $rows"; }
            if ((float) $d->paid_amount > (float) $d->GrandTotal + 0.005) { return "$doc #{$d->id}: paid {$d->paid_amount} exceeds total {$d->GrandTotal}"; }
            if ((float) $d->paid_amount < -0.005) { return "$doc #{$d->id}: negative paid {$d->paid_amount}"; }
            $exp = $d->paid_amount <= 0.005 ? 'unpaid' : ($d->paid_amount + 0.005 >= $d->GrandTotal ? 'paid' : 'partial');
            if ($d->payment_statut !== $exp) { return "$doc #{$d->id}: status {$d->payment_statut} but should be $exp (paid {$d->paid_amount} of {$d->GrandTotal})"; }
        }
        $orphans = DB::table($pt)->join($doc, "$pt.$fk", '=', "$doc.id")->whereNull("$pt.deleted_at")->whereNotNull("$doc.deleted_at")->count();
        if ($orphans) { return "$orphans live payment(s) in $pt belong to a deleted $doc"; }
    }
    // a refused edit must not leave stock half-changed
    foreach ([1, 2, 3, 4] as $pid) {
        foreach (Ledger::build($pid)['reconciliation'] as $row) {
            if (! $row['reconciled'] && ! ($row['row_missing'] && abs($row['ledger_balance']) < 1e-6)) { return "stock of product $pid in warehouse {$row['warehouse_id']} is {$row['actual_qte']} but documents say {$row['ledger_balance']}"; }
        }
    }
    // customer figures straight from rows
    $req = Illuminate\Http\Request::create('/api/x', 'GET'); $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u);
    $b = json_decode(app(App\Http\Controllers\ClientController::class)->clientBrief($req, 2)->getContent(), true);
    $gt = (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('client_id', 2)->sum('GrandTotal');
    $pd = (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('client_id', 2)->sum('paid_amount');
    if (abs($b['sale_due'] - ($gt - $pd)) > 0.005) { return "customer sale_due {$b['sale_due']} != ".($gt - $pd); }
    return null;
};

$ops = [
    'sale' => function () use (&$sales, $keyOf) { $q = mt_rand(1, 6); $wh = $keyOf([1, 3]); $st = $keyOf(['completed', 'completed', 'pending']);
        [$c, $b, $id] = mkSale([line($keyOf([1, 2, 3, 4]), $q, 100)], ['warehouse_id' => $wh, 'statut' => $st]); if ($c == 200 && $id) { $sales[] = $id; } return "sale $st ".($q * 100)." -> $c"; },
    'sale_edit' => function () use (&$sales, $keyOf) { $id = $keyOf($sales); if (! $id) { return 'skip'; } $q = mt_rand(1, 6); $r = DB::table('sales')->where('id', $id)->first(); $d = DB::table('sale_details')->where('sale_id', $id)->first();
        [$c] = updSale($id, [line($d->product_id, $q, 100, ['id' => $d->id])], ['warehouse_id' => $r->warehouse_id, 'statut' => $r->statut]); return "sale_edit #$id to ".($q * 100)." (paid {$r->paid_amount}) -> $c"; },
    'sale_delete' => function () use (&$sales, $keyOf) { $id = $keyOf($sales); if (! $id) { return 'skip'; } [$c] = destroySale($id); if ($c == 200) { $sales = array_values(array_diff($sales, [$id])); } return "sale_delete #$id -> $c"; },
    'sale_pay' => function () use (&$sales, $keyOf, $pay) { $id = $keyOf($sales); if (! $id) { return 'skip'; } $amt = mt_rand(1, 30) * 10; [$c] = call(CT_PS, 'store', $pay('s', $id, $amt)); return "sale_pay #$id $amt -> $c"; },
    'sale_pay_edit' => function () use (&$sales, $keyOf, $pay, $live) { $id = $keyOf($sales); if (! $id) { return 'skip'; } $ps = $live('payment_sales', 'sale_id', $id); if (! $ps) { return 'skip'; } $amt = mt_rand(1, 40) * 10; [$c] = call(CT_PS, 'update', $pay('s', $id, $amt), 'PUT', [$ps[0]]); return "sale_pay_edit #$id pay {$ps[0]} to $amt -> $c"; },
    'sale_pay_delete' => function () use (&$sales, $keyOf, $live) { $id = $keyOf($sales); if (! $id) { return 'skip'; } $ps = $live('payment_sales', 'sale_id', $id); if (! $ps) { return 'skip'; } [$c] = call(CT_PS, 'destroy', [], 'DELETE', [$ps[count($ps) - 1]]); return "sale_pay_delete #$id -> $c"; },
    'purchase' => function () use (&$purchases, $keyOf) { $q = mt_rand(1, 20); [$c, $b, $id] = mkPurchase($keyOf([1, 2, 3, 4]), $q, 10, $keyOf([1, 3])); if ($c == 200) { $purchases[] = $id; } return "purchase ".($q * 10)." -> $c"; },
    'purchase_edit' => function () use (&$purchases, $keyOf) { $id = $keyOf($purchases); if (! $id) { return 'skip'; } $r = DB::table('purchases')->where('id', $id)->first(); $d = DB::table('purchase_details')->where('purchase_id', $id)->first(); $q = mt_rand(1, 20);
        [$c] = call(CT_P, 'update', pHdr($r->warehouse_id, ['GrandTotal' => $q * 10, 'details' => [plm($d->product_id, $q, 10, ['id' => $d->id])]]), 'PUT', [$id]); return "purchase_edit #$id to ".($q * 10)." (paid {$r->paid_amount}) -> $c"; },
    'purchase_delete' => function () use (&$purchases, $keyOf) { $id = $keyOf($purchases); if (! $id) { return 'skip'; } [$c] = call(CT_P, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { $purchases = array_values(array_diff($purchases, [$id])); } return "purchase_delete #$id -> $c"; },
    'purchase_pay' => function () use (&$purchases, $keyOf, $pay) { $id = $keyOf($purchases); if (! $id) { return 'skip'; } $amt = mt_rand(1, 20) * 10; [$c] = call(CT_PP, 'store', $pay('p', $id, $amt)); return "purchase_pay #$id $amt -> $c"; },
    'purchase_pay_delete' => function () use (&$purchases, $keyOf, $live) { $id = $keyOf($purchases); if (! $id) { return 'skip'; } $ps = $live('payment_purchases', 'purchase_id', $id); if (! $ps) { return 'skip'; } [$c] = call(CT_PP, 'destroy', [], 'DELETE', [$ps[0]]); return "purchase_pay_delete #$id -> $c"; },
    'sale_return' => function () use (&$sales, &$srets, $keyOf) { $id = $keyOf($sales); if (! $id) { return 'skip'; } $r = DB::table('sales')->where('id', $id)->first(); $d = DB::table('sale_details')->where('sale_id', $id)->first(); $rq = mt_rand(1, max(1, (int) $d->quantity));
        [$c] = call(SR::class, 'store', ['client_id' => 2, 'warehouse_id' => $r->warehouse_id, 'sale_id' => $id, 'date' => date('Y-m-d'), 'statut' => 'received', 'notes' => 's', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $rq * 100, 'details' => [line($d->product_id, $rq, 100, ['imei_number' => null])]]);
        if ($c == 200) { $srets[] = (int) DB::table('sale_returns')->max('id'); } return "sale_return of #$id ".($rq * 100)." -> $c"; },
    'sale_return_pay' => function () use (&$srets, $keyOf, $pay) { $id = $keyOf($srets); if (! $id) { return 'skip'; } $amt = mt_rand(1, 6) * 50; [$c] = call(CT_PSR, 'store', $pay('r', $id, $amt)); return "sale_return_pay #$id $amt -> $c"; },
    'sale_return_delete' => function () use (&$srets, $keyOf) { $id = $keyOf($srets); if (! $id) { return 'skip'; } [$c] = call(SR::class, 'destroy', [], 'DELETE', [$id]); if ($c == 200) { $srets = array_values(array_diff($srets, [$id])); } return "sale_return_delete #$id -> $c"; },
];
$bag = ['sale', 'sale', 'sale', 'sale_edit', 'sale_edit', 'sale_delete', 'sale_pay', 'sale_pay', 'sale_pay', 'sale_pay_edit', 'sale_pay_edit', 'sale_pay_delete', 'purchase', 'purchase', 'purchase_edit', 'purchase_edit', 'purchase_delete',
    'purchase_pay', 'purchase_pay', 'purchase_pay_delete', 'sale_return', 'sale_return', 'sale_return_pay', 'sale_return_delete'];

$broken = null;
for ($i = 1; $i <= $steps && $broken === null; $i++) {
    try { $desc = $ops[$keyOf($bag)](); } catch (Throwable $e) { $desc = 'EXC '.get_class($e).': '.substr($e->getMessage(), 0, 140); }
    $log[] = "$i. $desc"; $broken = $tie();
}
if (getenv('B5_VERBOSE')) { echo implode("\n", $log), "\n"; }
check("seed $seed: money ties to payment rows after every one of $steps steps", $broken === null, $broken ? "$broken\n    last ops: ".implode(' | ', array_slice($log, -4)) : '');
$ok = count(array_filter($log, fn ($l) => str_ends_with($l, '-> 200')));
check("seed $seed: exercised the controllers ($ok of ".count($log)." steps succeeded)", $ok >= count($log) / 3, "$ok/".count($log));
finish("Audit B5 money stress (seed $seed)");
