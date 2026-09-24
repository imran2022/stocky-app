<?php
// Audit Batch 1 (S1): sale returns must fit the original sale.
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\SalesReturnController as SR;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;

function retLine($pid, $qty, $price, $extra = []) {
    return array_merge(line($pid, $qty, $price, ['imei_number' => null]), $extra);
}
function retPayload($saleId, $lines, $o = []) {
    $sum = array_sum(array_map(fn ($l) => $l['subtotal'], $lines));
    return array_merge(['client_id' => 2, 'warehouse_id' => 1, 'sale_id' => $saleId, 'date' => date('Y-m-d'), 'statut' => 'received',
        'notes' => 'audit', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => $sum, 'details' => $lines], $o);
}
$ret = fn ($sid, $lines, $o = []) => call(SR::class, 'store', retPayload($sid, $lines, $o));

[$c, $b, $sid] = mkSale([line(1, 3, 100)]);           // sold 3 x 100
check('setup: sale created', $c == 200 && $sid, "$c $b");
$st0 = stock(1);

[$c, $b] = $ret($sid, [retLine(1, 1, 100)]);
check('return 1 of 3 accepted', $c == 200, "$c $b");
check('stock +1', abs(stock(1) - ($st0 + 1)) < 1e-6, stock(1));
[$c, $b] = $ret($sid, [retLine(1, 3, 100)]);
check('return 3 more (total 4 of 3) rejected', $c == 422, "$c $b");
check('rejected return left stock alone', abs(stock(1) - ($st0 + 1)) < 1e-6);
[$c, $b] = $ret($sid, [retLine(1, 2, 100)]);
check('return remaining 2 accepted', $c == 200, "$c $b");
[$c, $b] = $ret($sid, [retLine(1, 1, 100)]);
check('one more after fully returned rejected', $c == 422, "$c $b");
[$c, $b] = $ret($sid, [retLine(1, 100, 100)]);
check('qty 100 rejected', $c == 422, "$c $b");

[$c, $b, $sid2] = mkSale([line(1, 1, 999)]);
[$c, $b] = $ret($sid2, [retLine(2, 1, 5000)]);
check('product not on the sale rejected', $c == 422, "$c $b");
[$c, $b] = $ret($sid2, [retLine(1, 1, 5000)]);
check('refund price above sale price rejected', $c == 422, "$c $b");
[$c, $b] = $ret($sid2, [retLine(1, 1, 999)], ['GrandTotal' => 5000]);
check('tampered GrandTotal rejected', $c == 422, "$c $b");
[$c, $b] = $ret(null, [retLine(1, 1, 999)]);
check('return without a sale rejected', $c == 422, "$c $b");
[$c, $b] = $ret($sid2, [retLine(1, 1, 999)], ['client_id' => 4]);
check('wrong customer rejected', $c == 422, "$c $b");
[$c, $b] = $ret($sid2, [retLine(1, 1, 999)]);
check('honest full return accepted', $c == 200, "$c $b");

// pending / deleted sales cannot be returned
[$c, $b, $sp] = mkSale([line(1, 1, 100)], ['statut' => 'pending']);
[$c, $b] = $ret($sp, [retLine(1, 1, 100)]);
check('pending sale cannot be returned', $c == 422, "$c $b");
[$c, $b, $sd] = mkSale([line(1, 1, 100)]);
destroySale($sd);
[$c, $b] = $ret($sd, [retLine(1, 1, 100)]);
check('deleted sale cannot be returned', $c == 422, "$c $b");

// edit: may use its own quantity again, not more than the sale
[$c, $b, $se] = mkSale([line(1, 4, 50)]);
$ret($se, [retLine(1, 1, 50)]);
$r2 = (int) DB::table('sale_returns')->where('sale_id', $se)->max('id');
[$c, $b] = $ret($se, [retLine(1, 2, 50)]);
$r3 = (int) DB::table('sale_returns')->where('sale_id', $se)->max('id');
$upd = fn ($rid, $q) => call(SR::class, 'update', retPayload($se, [retLine(1, $q, 50, ['no_unit' => 1, 'id' => DB::table('sale_return_details')->where('sale_return_id', $rid)->value('id')])]), 'PUT', [$rid]);
[$c, $b] = $upd($r3, 3);
check('edit return 2 -> 3 (1+3=4 of 4) accepted', $c == 200, "$c $b");
[$c, $b] = $upd($r3, 4);
check('edit return 3 -> 4 (1+4=5 of 4) rejected', $c == 422, "$c $b");
DB::table('sale_returns')->where('id', $r3)->update(['deleted_at' => now()]);
[$c, $b] = $upd($r3, 1);
check('edit of a deleted return rejected', $c == 422, "$c $b");

// sale with an exclusive-tax line: refund limited to what was charged (tax included)
[$c, $b, $st] = mkSale([line(1, 2, 100, ['tax_percent' => 10, 'subtotal' => 220])], ['GrandTotal' => 220]);
[$c, $b] = $ret($st, [retLine(1, 1, 100, ['tax_percent' => 10, 'subtotal' => 110])], ['GrandTotal' => 110]);
check('taxed line: prorated refund 110 accepted', $c == 200, "$c $b");
[$c, $b] = $ret($st, [retLine(1, 1, 100, ['tax_percent' => 10, 'subtotal' => 150])], ['GrandTotal' => 150]);
check('taxed line: refund 150 for a 110 charge rejected', $c == 422, "$c $b");
// refunds are capped at the return's GrandTotal
$rid = (int) DB::table('sale_returns')->where('sale_id', $st)->min('id');   // the 110 return
$pay = fn ($amt) => call(App\Http\Controllers\PaymentSaleReturnsController::class, 'store',
    ['sale_return_id' => $rid, 'montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'audit']);
[$c, $b] = $pay(999);
check('refund 999 on a 110 return rejected', $c == 422, "$c $b");
[$c, $b] = $pay(60);
check('refund 60 of 110 accepted', $c == 200, "$c $b");
[$c, $b] = $pay(60);
check('second refund 60 (total 120 of 110) rejected', $c == 422, "$c $b");
[$c, $b] = $pay(50);
check('refund the remaining 50 accepted -> paid', $c == 200 && DB::table('sale_returns')->where('id', $rid)->value('payment_statut') == 'paid', "$c $b");
finish('Audit B1 sale return limits');
