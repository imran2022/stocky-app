<?php
// Audit Batch 3 (S4/S5/P4): paid_amount / payment_statut always equal the payment rows; overpayment refused;
// editing a payment never touches another document or (for credit-card payments) the account.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Support\Facades\DB;

const CT_PS = App\Http\Controllers\PaymentSalesController::class;
$sp = fn ($sid, $amt, $o = []) => call(CT_PS, 'store', array_merge(['sale_id' => $sid, 'montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'audit'], $o));
$spu = fn ($id, $amt, $o = []) => call(CT_PS, 'update', array_merge(['montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'audit'], $o), 'PUT', [$id]);
$sd = fn ($id) => call(CT_PS, 'destroy', [], 'DELETE', [$id]);
$rows = fn ($sid) => (float) DB::table('payment_sales')->where('sale_id', $sid)->whereNull('deleted_at')->sum('montant');
$paid = fn ($sid) => (float) DB::table('sales')->where('id', $sid)->value('paid_amount');
$st = fn ($sid) => DB::table('sales')->where('id', $sid)->value('payment_statut');
$lastPay = fn ($sid) => (int) DB::table('payment_sales')->where('sale_id', $sid)->whereNull('deleted_at')->max('id');

echo "== Sale payments ==\n";
[$c, $b, $sid] = mkSale([line(1, 1, 100)]);
[$c, $b] = $sp($sid, 60);
check('pay 60 of 100 -> partial', $c == 200 && $paid($sid) == 60 && $st($sid) == 'partial', "$c $b");
[$c, $b] = $sp($sid, 80);
check('pay 80 more (140 of 100) rejected', $c == 422, "$c $b");
check('rejected payment left no row and no change', $rows($sid) == 60 && $paid($sid) == 60);
[$c, $b] = $sp($sid, 40);
check('pay the last 40 -> paid', $c == 200 && $paid($sid) == 100 && $st($sid) == 'paid', "$c $b");
$p40 = $lastPay($sid);
[$c, $b] = $sd($p40);
check('delete the 40 -> back to 60 / partial', $c == 200 && $paid($sid) == 60 && $st($sid) == 'partial', "$c $b paid=".$paid($sid));
$p60 = (int) DB::table('payment_sales')->where('sale_id', $sid)->whereNull('deleted_at')->value('id');
[$c, $b] = $spu($p60, 100);
check('edit 60 -> 100 -> paid', $c == 200 && $paid($sid) == 100 && $st($sid) == 'paid', "$c $b");
[$c, $b] = $spu($p60, 150);
check('edit 60 -> 150 rejected', $c == 422, "$c $b");
[$c, $b] = $spu($p60, 30);
check('edit to 30 -> partial 30', $c == 200 && $paid($sid) == 30 && $st($sid) == 'partial', "$c $b");
[$c] = $sd($p60);
check('delete last payment -> unpaid 0', $c == 200 && $paid($sid) == 0 && $st($sid) == 'unpaid');

echo "== drift is repaired from the rows ==\n";
[$c, $b, $sd2] = mkSale([line(1, 1, 200)]);
$sp($sd2, 50);
DB::table('sales')->where('id', $sd2)->update(['paid_amount' => 190, 'payment_statut' => 'partial']);   // corrupt on purpose
$sp($sd2, 25);
check('next payment recomputes paid from rows (75, not 215)', $paid($sd2) == 75, $paid($sd2));

echo "== credit-card payment edit does not drain the account ==\n";
$acc = DB::table('accounts')->insertGetId(['account_num' => 'AUD-B3', 'account_name' => 'Audit B3 account', 'balance' => 500, 'created_at' => now(), 'updated_at' => now()]);
[$c, $b, $sc] = mkSale([line(1, 1, 300)]);
$sp($sc, 100, ['payment_method_id' => 1, 'account_id' => $acc]);
$after = (float) DB::table('accounts')->where('id', $acc)->value('balance');
$pc = $lastPay($sc);
$spu($pc, 100, ['payment_method_id' => 1, 'account_id' => $acc, 'notes' => 'changed']);
$spu($pc, 100, ['payment_method_id' => 1, 'account_id' => $acc, 'notes' => 'again']);
check('card payment credited the account once (500 -> 600)', $after == 600, $after);
check('two edits of a card payment leave the account balance alone', (float) DB::table('accounts')->where('id', $acc)->value('balance') == $after, DB::table('accounts')->where('id', $acc)->value('balance').' vs '.$after);

echo "== Purchase payments ==\n";
[$c, $b, $pid] = mkPurchase(4, 100, 100);           // 10000
$pp = fn ($id, $amt) => call(CT_PP, 'store', ['purchase_id' => $id, 'montant' => $amt, 'change' => 0, 'date' => '2026-09-24', 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
$ppu = fn ($pay, $purchaseId, $amt) => call(CT_PP, 'update', ['purchase_id' => $purchaseId, 'montant' => $amt, 'change' => 0, 'date' => '2026-09-24', 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x'], 'PUT', [$pay]);
$pPaid = fn ($id) => (float) DB::table('purchases')->where('id', $id)->value('paid_amount');
$pRows = fn ($id) => (float) DB::table('payment_purchases')->where('purchase_id', $id)->whereNull('deleted_at')->sum('montant');
[$c, $b] = $pp($pid, 7000);
check('purchase: pay 7000 -> partial', $c == 200 && $pPaid($pid) == 7000, "$c $b");
[$c, $b] = $pp($pid, 5000);
check('purchase: pay 5000 more (12000 of 10000) rejected', $c == 422, "$c $b");
[$c, $b] = $pp($pid, 3000);
check('purchase: pay 3000 -> paid', $c == 200 && $pPaid($pid) == 10000 && DB::table('purchases')->where('id', $pid)->value('payment_statut') == 'paid', "$c $b");
$last = (int) DB::table('payment_purchases')->where('purchase_id', $pid)->max('id');
call(CT_PP, 'destroy', [], 'DELETE', [$last]);
check('purchase: delete 3000 -> 7000 (equals rows)', $pPaid($pid) == 7000 && $pPaid($pid) == $pRows($pid), $pPaid($pid));
[$c, $b, $other] = mkPurchase(2, 10, 100);           // 1000, unrelated
$first = (int) DB::table('payment_purchases')->where('purchase_id', $pid)->whereNull('deleted_at')->min('id');
$ppu($first, $other, 500);
check('purchase: editing a payment with a foreign purchase_id leaves the other purchase untouched', $pPaid($other) == 0 && DB::table('payment_purchases')->where('purchase_id', $other)->count() == 0, $pPaid($other));
check('purchase: payment stayed on its own purchase, paid == rows', $pPaid($pid) == $pRows($pid) && $pRows($pid) == 500, $pPaid($pid).' / '.$pRows($pid));
finish('Audit B3 payments');
