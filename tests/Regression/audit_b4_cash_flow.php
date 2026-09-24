<?php
// Audit Batch 4 (C6): Cash Flow includes refunds on returns, honours every filter in the chart as well as the table,
// and the table totals equal the chart totals.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\{Auth, DB};

DB::statement('SET FOREIGN_KEY_CHECKS=0');
$now = now(); $D = '2030-03-05'; $D2 = '2030-03-06';
$doc = fn ($t, $wh, $extra = []) => DB::table($t)->insertGetId(array_merge(['Ref' => 'CF-'.$t, 'date' => $D, 'warehouse_id' => $wh, 'user_id' => 2, 'statut' => 'completed',
    'GrandTotal' => 1, 'TaxNet' => 0, 'shipping' => 0, 'discount' => 0, 'tax_rate' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'created_at' => $now, 'updated_at' => $now], $extra));
$sale = $doc('sales', 1, ['client_id' => 2]);
$sret = $doc('sale_returns', 1, ['client_id' => 2]);
$purch = $doc('purchases', 3, ['provider_id' => 1]);
$pret = $doc('purchase_returns', 3, ['provider_id' => 1]);
$pay = fn ($t, $fk, $id, $amt, $method, $date = null) => DB::table($t)->insert([$fk => $id, 'user_id' => 2, 'date' => $date ?? $D, 'Ref' => 'CF', 'montant' => $amt, 'change' => 0,
    'payment_method_id' => $method, 'account_id' => null, 'created_at' => $now, 'updated_at' => $now]);
$pay('payment_sales', 'sale_id', $sale, 100, 2);                  // in  100 (cash)
$pay('payment_sale_returns', 'sale_return_id', $sret, 30, 2);     // out  30 refund to customer
$pay('payment_purchases', 'purchase_id', $purch, 40, 6, $D2);     // out  40 (bank)
$pay('payment_purchase_returns', 'purchase_return_id', $pret, 15, 6, $D2); // in 15 refund from supplier
DB::table('expenses')->insert(['date' => $D, 'Ref' => 'EX-CF', 'user_id' => 2, 'expense_category_id' => 1, 'warehouse_id' => 1, 'account_id' => null, 'details' => 'x', 'amount' => 20, 'payment_method_id' => 2, 'created_at' => $now, 'updated_at' => $now]);
DB::table('deposits')->insert(['user_id' => 2, 'date' => $D, 'deposit_ref' => 'DP-CF', 'account_id' => null, 'deposit_category_id' => 1, 'amount' => 50, 'created_at' => $now, 'updated_at' => $now]);
DB::table('client_opening_balance_payments')->insert(['client_id' => 2, 'user_id' => 2, 'date' => $D, 'Ref' => 'CO', 'montant' => 25, 'change' => 0, 'payment_method_id' => 2, 'account_id' => null, 'created_at' => $now, 'updated_at' => $now]);
DB::table('provider_opening_balance_payments')->insert(['provider_id' => 1, 'user_id' => 2, 'date' => $D, 'Ref' => 'PO', 'montant' => 10, 'change' => 0, 'payment_method_id' => 2, 'account_id' => null, 'created_at' => $now, 'updated_at' => $now]);

$run = function (array $q) {
    $req = Illuminate\Http\Request::create('/api/x', 'GET', $q + ['from' => '2030-03-01', 'to' => '2030-03-31']);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\ReportController::class)->cash_flow_report($req)->getContent(), true);
};
$near = fn ($a, $b) => abs($a - $b) < 0.005;

$r = $run(['group_by' => 'method']);
check('inflow = 100 + 15 refund + 50 deposit + 25 opening = 190', $near($r['total_inflow'], 190), $r['total_inflow']);
check('outflow = 30 refund + 40 + 20 expense + 10 opening = 100', $near($r['total_outflow'], 100), $r['total_outflow']);
check('net 90', $near($r['net_cash_flow'], 90), $r['net_cash_flow']);
$cash = collect($r['rows'])->firstWhere('group', 'Cash');
check('cash group: in 100+25 = 125, out 30+20+10 = 60', $cash && $near($cash['inflow'], 125) && $near($cash['outflow'], 60), json_encode($cash));
$ts = collect($r['timeseries']);
check('chart totals equal table totals', $near($ts->sum('inflow'), 190) && $near($ts->sum('outflow'), 100), json_encode($ts));
check('chart splits by day (05th and 06th)', $ts->pluck('d')->all() == [$D, $D2], json_encode($ts->pluck('d')));

$a = $run(['group_by' => 'account']);
check('grouping by account gives the same totals', $near($a['total_inflow'], 190) && $near($a['total_outflow'], 100));

$w = $run(['group_by' => 'method', 'warehouse_id' => 1]);
check('warehouse 1: in 100, out 30 refund + 20 expense (wh3 documents, deposits and opening balances excluded)', $near($w['total_inflow'], 100) && $near($w['total_outflow'], 50), json_encode([$w['total_inflow'], $w['total_outflow']]));
check('warehouse filter also applies to the chart', $near(collect($w['timeseries'])->sum('inflow'), 100) && $near(collect($w['timeseries'])->sum('outflow'), 50));

$m = $run(['group_by' => 'method', 'payment_method_id' => 6]);
check('method filter bank: in 15, out 40 (no deposits, chart obeys too)', $near($m['total_inflow'], 15) && $near($m['total_outflow'], 40) && $near(collect($m['timeseries'])->sum('inflow'), 15), json_encode($m['timeseries']));
finish('Audit B4 cash flow');
