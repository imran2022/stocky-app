<?php
// Audit Batch 2 (C3): purchases / adjustments / transfers / purchase returns validate quantities, types and stock.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Support\Facades\DB;

DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 0]);

echo "== Purchase ==\n";
$s = stk(4, 3);
[$c, $b] = call(CT_P, 'store', pHdr(3, ['GrandTotal' => -5000, 'details' => [pl(4, -50, 100)]]));
check('purchase of qty -50 rejected (422)', $c == 422, "$c $b");
check('stock untouched', stk(4, 3) == $s);
[$c, $b, $pid] = mkPurchase(4, 10, 100);
check('normal purchase accepted', $c == 200, "$c $b");

echo "== Adjustment ==\n";
$s = stk(1, 1);
foreach (['ADD', 'increase', '', 'Add'] as $t) {
    [$c, $b] = call(CT_A, 'store', adjPayload(1, $t, 3));
    check("adjustment type '$t' rejected", $c == 422, "$c $b");
}
check('stock untouched by bad types', stk(1, 1) == $s);
[$c, $b] = call(CT_A, 'store', adjPayload(1, 'add', -4));
check('adjustment qty -4 rejected', $c == 422, "$c $b");
[$c, $b] = call(CT_A, 'store', adjPayload(1, 'add', 3));
check("adjustment 'add' 3 accepted", $c == 200 && stk(1, 1) == $s + 3, "$c $b");
[$c, $b] = call(CT_A, 'store', adjPayload(1, 'sub', 2));
check("adjustment 'sub' 2 accepted", $c == 200 && stk(1, 1) == $s + 1, "$c $b");
[$c, $b] = call(CT_A, 'store', adjPayload(1, 'sub', stk(1, 1) + 5));
check("adjustment 'sub' more than on hand rejected", $c == 422, "$c $b");
$aid = (int) DB::table('adjustments')->max('id');           // the 'sub' 2 document
[$c, $b] = call(CT_A, 'update', adjPayload(1, 'sub', 2 + stk(1, 1) + 1), 'PUT', [$aid]);
check("adjustment edit 'sub' beyond on-hand (+own) rejected", $c == 422, "$c $b");

echo "== Transfer ==\n";
setStock(3, 2, 0);
[$c, $b] = call(CT_T, 'store', trPayload(2, 2, 3, 1));
check('transfer from == to rejected', $c == 422, "$c $b");
[$c, $b] = call(CT_T, 'store', trPayload(2, 3, 3, -5));
check('transfer qty -5 rejected', $c == 422, "$c $b");
call(CT_T, 'store', trPayload(2, 3, 3, 5)); $tid = (int) DB::table('transfers')->max('id');
$s2 = stk(3, 2); $s3 = stk(3, 3);
[$c, $b] = call(CT_T, 'approve', [], 'POST', [$tid]);
check('approve 5 from empty warehouse rejected', $c == 422, "$c $b");
check('stock untouched, transfer still pending', stk(3, 2) == $s2 && stk(3, 3) == $s3 && DB::table('transfers')->where('id', $tid)->value('approval_status') == 'pending');
mkPurchase(3, 20, 100, 2);
[$c, $b] = call(CT_T, 'approve', [], 'POST', [$tid]);
check('approve after restocking accepted', $c == 200 && stk(3, 2) == 15 && stk(3, 3) == $s3 + 5, "$c $b");

echo "== Purchase return ==\n";
[$c, $b, $pr] = mkPurchase(2, 10, 100);
$st = stk(2, 3);
[$c, $b] = call(CT_R, 'store', prPayload($pr, 2, 25));
check('return 25 of 10 rejected', $c == 422, "$c $b");
[$c, $b] = call(CT_R, 'store', prPayload($pr, 2, 6));
check('return 6 of 10 accepted', $c == 200 && stk(2, 3) == $st - 6, "$c $b");
[$c, $b] = call(CT_R, 'store', prPayload($pr, 2, 6));
check('second return of 6 (total 12 of 10) rejected', $c == 422, "$c $b");
[$c, $b] = call(CT_R, 'store', prPayload($pr, 4, 1));
check('product not on the purchase rejected', $c == 422, "$c $b");
[$c, $b] = call(CT_R, 'store', prPayload(null, 2, 1));
check('return without a purchase rejected', $c == 422, "$c $b");
// stock-insufficient: buy 5, sell them, then try to return them
[$c, $b, $p5] = mkPurchase(3, 5, 100, 1);
setStock(3, 1, 0);
[$c, $b] = call(CT_R, 'store', prPayload($p5, 3, 5, 1));
check('return of goods no longer in stock rejected', $c == 422, "$c $b");

echo "== edit/delete of a document that has a return (H7: no false success) ==\n";
[$c, $b, $ph] = mkPurchase(2, 10, 100);
call(CT_R, 'store', prPayload($ph, 2, 2));
$sh = stk(2, 3);
[$c, $b] = call(CT_P, 'update', pHdr(3, ['GrandTotal' => 500, 'details' => [pl(2, 5, 100, ['id' => DB::table('purchase_details')->where('purchase_id', $ph)->value('id')])]]), 'PUT', [$ph]);
check('purchase edit with a return answers 403, not success', $c == 403, "$c $b");
[$c, $b] = call(CT_P, 'destroy', [], 'DELETE', [$ph]);
check('purchase delete with a return answers 403, not success', $c == 403, "$c $b");
check('purchase still live, stock unchanged', DB::table('purchases')->where('id', $ph)->value('deleted_at') === null && stk(2, 3) == $sh);
[$c, $b, $sr] = mkSale([line(1, 2, 100)]);
call(App\Http\Controllers\SalesReturnController::class, 'store', ['client_id' => 2, 'warehouse_id' => 1, 'sale_id' => $sr, 'date' => date('Y-m-d'), 'statut' => 'received', 'notes' => 'x', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 100, 'details' => [line(1, 1, 100, ['imei_number' => null])]]);
[$c, $b] = updSale($sr, [line(1, 2, 100, ['id' => detIds($sr)[0]])], ['GrandTotal' => 200]);
check('sale edit with a return answers 403, not success/500', $c == 403, "$c $b");
finish('Audit B2 stock documents');
