<?php
// Audit Batch 5 (M1): an edit cannot shrink a document below what is already paid, and a refused edit changes nothing (stock included).
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Support\Facades\DB;

const CT_PS = App\Http\Controllers\PaymentSalesController::class;
$pay = fn ($id, $amt) => call(CT_PS, 'store', ['sale_id' => $id, 'montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);

// ---- sale ----
[$c, $b, $sid] = mkSale([line(1, 2, 100)]);            // 200
$pay($sid, 150);
$st = stock(1); $d = DB::table('sale_details')->where('sale_id', $sid)->first();
[$c, $b] = updSale($sid, [line(1, 1, 100, ['id' => $d->id])]);   // 100 < 150 paid
check('sale edit below paid is refused', $c == 422, "$c $b");
check('...nothing changed: total, stock and line quantity', DB::table('sales')->where('id', $sid)->value('GrandTotal') == 200 && abs(stock(1) - $st) < 1e-6 && DB::table('sale_details')->where('id', $d->id)->value('quantity') == 2, stock(1).' vs '.$st);
[$c, $b] = updSale($sid, [line(1, 1, 100, ['id' => $d->id, 'subtotal' => 160, 'Unit_price' => 160])], ['GrandTotal' => 160]);
check('sale edit to 160 (>= 150 paid) is accepted, status partial', $c == 200 && DB::table('sales')->where('id', $sid)->value('payment_statut') == 'partial', "$c $b");

// ---- purchase ----
[$c, $b, $pid] = mkPurchase(2, 10, 10);                // 100
call(CT_PP, 'store', ['purchase_id' => $pid, 'montant' => 80, 'change' => 0, 'date' => '2026-09-24', 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
$ps = stk(2, 3); $pd = DB::table('purchase_details')->where('purchase_id', $pid)->first();
[$c, $b] = call(CT_P, 'update', pHdr(3, ['GrandTotal' => 50, 'details' => [plm(2, 5, 10, ['id' => $pd->id])]]), 'PUT', [$pid]);
check('purchase edit below paid is refused', $c == 422, "$c $b");
check('...nothing changed: total, stock and quantity', DB::table('purchases')->where('id', $pid)->value('GrandTotal') == 100 && abs(stk(2, 3) - $ps) < 1e-6 && DB::table('purchase_details')->where('id', $pd->id)->value('quantity') == 10, stk(2, 3).' vs '.$ps);
[$c, $b] = call(CT_P, 'update', pHdr(3, ['GrandTotal' => 90, 'details' => [plm(2, 9, 10, ['id' => $pd->id])]]), 'PUT', [$pid]);
check('purchase edit to 90 (>= 80 paid) is accepted', $c == 200, "$c $b");
finish('Audit B5 edit below paid');
