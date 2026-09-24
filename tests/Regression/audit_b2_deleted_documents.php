<?php
// Audit Batch 2 (C4/S6): deleted documents cannot be edited, re-deleted, approved or paid.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Support\Facades\DB;

echo "== Sale ==\n";
[$c, $b, $sid] = mkSale([line(1, 2, 100)]);
$s0 = stock(1); $pts0 = cl(2);
[$c] = destroySale($sid);
check('first delete ok', $c == 200);
$sAfter = stock(1); $ptsAfter = cl(2);
[$c, $b] = destroySale($sid);
check('second delete rejected (404)', $c == 404, "$c $b");
check('second delete changed nothing (stock, points)', stock(1) == $sAfter && cl(2) == $ptsAfter);
$d = DB::table('sale_details')->where('sale_id', $sid)->count();
[$c, $b] = updSale($sid, [line(1, 5, 100)], ['GrandTotal' => 500]);
check('update of deleted sale rejected (404)', $c == 404, "$c $b");
check('update of deleted sale changed nothing', stock(1) == $sAfter && DB::table('sale_details')->where('sale_id', $sid)->count() == $d);
[$c, $b] = call(App\Http\Controllers\PaymentSalesController::class, 'store', ['sale_id' => $sid, 'montant' => 70, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
check('payment on deleted sale rejected (404)', $c == 404, "$c $b");

echo "== Purchase ==\n";
[$c, $b, $pid] = mkPurchase(4, 10, 100);
call(CT_P, 'destroy', [], 'DELETE', [$pid]);
$s = stk(4, 3);
[$c, $b] = call(CT_P, 'update', pHdr(3, ['GrandTotal' => 700, 'details' => [pl(4, 7, 100)]]), 'PUT', [$pid]);
check('update of deleted purchase rejected (404)', $c == 404, "$c $b");
check('stock unchanged', stk(4, 3) == $s);
[$c, $b] = call(CT_P, 'destroy', [], 'DELETE', [$pid]);
check('second purchase delete rejected (404)', $c == 404, "$c $b");
[$c, $b] = call(CT_PP, 'store', ['purchase_id' => $pid, 'montant' => 50, 'change' => 0, 'date' => '2026-09-24', 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
check('payment on deleted purchase rejected (404)', $c == 404, "$c $b");

echo "== Adjustment ==\n";
call(CT_A, 'store', adjPayload(1, 'add', 5)); $aid = (int) DB::table('adjustments')->max('id');
call(CT_A, 'destroy', [], 'DELETE', [$aid]); $s = stk(1, 1);
[$c, $b] = call(CT_A, 'update', adjPayload(1, 'add', 8), 'PUT', [$aid]);
check('update of deleted adjustment rejected (404)', $c == 404, "$c $b");
check('stock unchanged', stk(1, 1) == $s);

echo "== Transfer ==\n";
mkPurchase(3, 20, 100, 2);
call(CT_T, 'store', trPayload(2, 3, 3, 5)); $tid = (int) DB::table('transfers')->max('id');
call(CT_T, 'approve', [], 'POST', [$tid]);
call(CT_T, 'destroy', [], 'DELETE', [$tid]); $s2 = stk(3, 2); $s3 = stk(3, 3);
[$c, $b] = call(CT_T, 'update', trPayload(2, 3, 3, 5), 'PUT', [$tid]);
check('update of deleted transfer rejected (404)', $c == 404, "$c $b");
[$c, $b] = call(CT_T, 'approve', [], 'POST', [$tid]);
check('approve of deleted transfer rejected (404)', $c == 404, "$c $b");
check('stock unchanged', stk(3, 2) == $s2 && stk(3, 3) == $s3);

echo "== Purchase return ==\n";
[$c, $b, $pid2] = mkPurchase(2, 10, 100);
call(CT_R, 'store', prPayload($pid2, 2, 2)); $rid = (int) DB::table('purchase_returns')->max('id');
call(CT_R, 'destroy', [], 'DELETE', [$rid]); $s = stk(2, 3);
[$c, $b] = call(CT_R, 'update', prPayload($pid2, 2, 6), 'PUT', [$rid]);
check('update of deleted purchase return rejected (404)', $c == 404, "$c $b");
check('stock unchanged', stk(2, 3) == $s);

echo "== live documents still work ==\n";
[$c, $b, $ok] = mkSale([line(1, 1, 100)]);
[$c, $b] = updSale($ok, [line(1, 2, 100, ['id' => detIds($ok)[0]])], ['GrandTotal' => 200]);
check('edit of a live sale still works', $c == 200, "$c $b");
[$c] = destroySale($ok);
check('delete of a live sale still works', $c == 200);
finish('Audit B2 deleted documents');
