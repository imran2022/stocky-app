<?php
// Audit Batch 3 (R2): register close expects only the cash that was really taken in the shift.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

const CT_CR = App\Http\Controllers\CashRegisterController::class;
const CT_PSX = App\Http\Controllers\PaymentSalesController::class;
try { DB::table('settings')->update(['allow_overselling' => 1]); } catch (Throwable $e) {}
DB::table('cash_registers')->delete();

[$c, $b] = call(CT_CR, 'openRegister', ['warehouse_id' => 1, 'opening_balance' => 100]);
$rid = (int) DB::table('cash_registers')->max('id');
check('register opened', $c == 200 && $rid, "$c $b");
[$c] = call(CT_CR, 'cashInOut', ['register_id' => $rid, 'type' => 'in', 'amount' => 50]);
[$c] = call(CT_CR, 'cashInOut', ['register_id' => $rid, 'type' => 'out', 'amount' => 20]);

// three sales of 200: one paid in cash, one paid by card, one left unpaid
[$c, $b, $s1] = mkSale([line(1, 1, 200)]);
[$c, $b, $s2] = mkSale([line(1, 1, 200)]);
[$c, $b, $s3] = mkSale([line(1, 1, 200)]);
DB::table('sales')->whereIn('id', [$s1, $s2, $s3])->update(['is_pos' => 1, 'user_id' => 2]);
$pay = fn ($sid, $amt, $m) => call(CT_PSX, 'store', ['sale_id' => $sid, 'montant' => $amt, 'change' => 0, 'date' => date('Y-m-d'), 'payment_method_id' => $m, 'account_id' => null, 'notes' => 'x']);
$pay($s1, 200, 2);
$pay($s2, 200, 4);
DB::table('payment_sales')->whereIn('sale_id', [$s1, $s2])->update(['user_id' => 2, 'created_at' => now()]);
DB::table('cash_registers')->where('id', $rid)->update(['opened_at' => now()->subMinute(), 'user_id' => 2]);

// expected = 100 + 50 - 20 + 200 cash = 330 (card 200 and unpaid 200 are NOT in the drawer)
[$c, $b] = call(CT_CR, 'closeRegister', ['register_id' => $rid, 'counted_cash' => 330]);
$j = json_decode($b, true);
check('close succeeds', $c == 200, "$c $b");
check('expected cash = opening + in - out + cash payments only (330)', ($j['summary']['expected_cash'] ?? null) == 330, $b);
check('difference is 0 when the drawer matches', ($j['summary']['difference'] ?? null) == 0, $b);
finish('Audit B3 cash register');
