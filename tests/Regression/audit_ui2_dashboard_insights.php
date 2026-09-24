<?php
// Modern Dashboard step 2: every extra figure is checked against an independent read of the database and against the
// report it must agree with (Cash Flow report, Sales list due, Classic Stock Alert rule).
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\{Auth,DB};

$today = date('Y-m-d'); $old40 = date('Y-m-d', strtotime('-40 days')); $old15 = date('Y-m-d', strtotime('-15 days')); $old3 = date('Y-m-d', strtotime('-3 days'));
$ins = function (array $q = []) use ($today) {
    $req = Request::create('/api/dashboard_insights', 'GET', $q + ['from' => $today, 'to' => $today, 'warehouse_id' => 0]);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    $r = app(App\Http\Controllers\DashboardInsightsController::class)->index($req);
    return [$r->getStatusCode(), json_decode($r->getContent(), true)];
};
$near = fn ($a, $b) => abs($a - $b) < 0.005;
$classicFn = function () use ($today) { $req = Request::create('/api/dashboard_data', 'GET', ['from' => $today, 'to' => $today, 'warehouse_id' => 0]); $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req); return json_decode(app(App\Http\Controllers\DashboardController::class)->dashboard_data($req)->getContent(), true)['report_dashboard']['original']['report']; };

// --- empty day: nothing invented -----------------------------------------------------------------------------
[$c, $e] = $ins(['from' => '2001-01-01', 'to' => '2001-01-01']);
check('empty range answers 200', $c === 200);
check('empty range: collected % is null (no sales), not 0%', $e['collected']['pct'] === null && $e['collected']['invoices'] === 0);
check('empty range: no customers, no places', $e['customer_mix']['top'] === [] && $e['geo']['places'] === [] && $near($e['geo']['total'], 0));
check('previous period is the same length right before', $e['range']['prev_to'] === '2000-12-31' && $e['range']['prev_from'] === '2000-12-31');
[$c, $w] = $ins(['from' => '2026-03-01', 'to' => '2026-03-07']);
check('7-day range: previous period is the 7 days before', $w['range']['prev_from'] === '2026-02-22' && $w['range']['prev_to'] === '2026-02-28', json_encode($w['range']));

[$c, $e0] = $ins();   // the seeded database already has sales today: every check below is against an independent read or a delta
// --- sales, dues, aging -----------------------------------------------------------------------------------
[$c1, $b1, $s1] = mkSale([line(1, 2, 100)]);                       // 200 today, unpaid
[$c2, $b2, $s2] = mkSale([line(1, 3, 100)], ['client_id' => 4]);   // 300 today, will be paid 300
call(App\Http\Controllers\PaymentSalesController::class, 'store', ['sale_id' => $s2, 'montant' => 300, 'change' => 0, 'date' => $today, 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
[$c3, $b3, $s3] = mkSale([line(1, 1, 100)], ['date' => $old40]);  // 100, 40 days old, unpaid
[$c4, $b4, $s4] = mkSale([line(1, 1, 100)], ['date' => $old15]);  // 100, 15 days old, unpaid
[$c5, $b5, $s5] = mkSale([line(1, 1, 100)], ['date' => $old3]);   // 100, 3 days old, unpaid
[$c6, $b6, $pend] = mkSale([line(1, 5, 100)], ['statut' => 'pending']); // pending: never a receivable
[$c, $e] = $ins();

$agg = DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('date', $today)->selectRaw('SUM(GrandTotal) t, SUM(paid_amount) p, COUNT(*) n')->first();
check('collected: today +500 invoiced, +300 paid', $near($e['collected']['total'] - $e0['collected']['total'], 500) && $near($e['collected']['paid'] - $e0['collected']['paid'], 300), json_encode([$e0['collected'], $e['collected']]));
check('collected total/paid/% equal an independent read of the database', $near($e['collected']['total'], (float) $agg->t) && $near($e['collected']['paid'], (float) $agg->p) && $e['collected']['invoices'] === (int) $agg->n && $near($e['collected']['pct'], round($agg->p / $agg->t * 100, 1)), json_encode([$e['collected'], $agg]));
$due = fn ($from, $to) => (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->whereBetween('date', [$from, $to])->whereRaw('GrandTotal - paid_amount > 0.005')->sum(DB::raw('GrandTotal - paid_amount'));
$r = $e['receivables']['buckets'];
$r0 = $e0['receivables']['buckets'];
check('aging 0-7 days = independent sum', $near($r['d0_7']['amount'], $due($old3 = date('Y-m-d', strtotime('-7 days')), $today)), json_encode($r));
check('aging 0-7 days grew by the 200 (today) + 100 (3 days old) invoices', $near($r['d0_7']['amount'] - $r0['d0_7']['amount'], 300) && $r['d0_7']['count'] - $r0['d0_7']['count'] === 2);
check('aging 8-30 days = independent sum and grew by the 15-day-old invoice', $near($r['d8_30']['amount'], $due(date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-8 days')))) && $near($r['d8_30']['amount'] - $r0['d8_30']['amount'], 100) && $r['d8_30']['count'] - $r0['d8_30']['count'] === 1);
check('aging 31+ days = independent sum and grew by the 40-day-old invoice', $near($r['d31_plus']['amount'], $due('2000-01-01', date('Y-m-d', strtotime('-31 days')))) && $near($r['d31_plus']['amount'] - $r0['d31_plus']['amount'], 100) && $r['d31_plus']['count'] - $r0['d31_plus']['count'] === 1);
check('aging total = every unpaid completed invoice, all dates', $near($e['receivables']['total'], $due('2000-01-01', $today)), $e['receivables']['total']);
check('pending sale is not money to collect and shows as pending', $e['attention']['pending_sales']['count'] - $e0['attention']['pending_sales']['count'] === 1 && $near($e['attention']['pending_sales']['amount'] - $e0['attention']['pending_sales']['amount'], 500));
check('overdue 30d card = the 31+ bucket', $e['attention']['overdue_30'] === $r['d31_plus']);
// the period KPI (today's sales due) equals what Classic shows for the same range
$dash = json_decode((function () use ($today) { $req = Request::create('/api/dashboard_data', 'GET', ['from' => $today, 'to' => $today, 'warehouse_id' => 0]); $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req); return app(App\Http\Controllers\DashboardController::class)->dashboard_data($req)->getContent(); })(), true)['report_dashboard']['original']['report'];
check('collected due matches Classic "Sales due" for the range', $near($e['collected']['due'], (float) $dash['sales_due']) && $near($e['collected']['total'], (float) $dash['today_sales']), json_encode([$e['collected']['due'], $dash['sales_due']]));
check('Classic exposes the profit parts as plain numbers', isset($dash['today_net_revenue'], $dash['today_cogs'], $dash['today_expenses'], $dash['return_purchases_amount']) && $near($dash['today_profit'], $dash['today_net_revenue'] - $dash['today_cogs'] - $dash['today_expenses'] + $dash['today_service_profit']), json_encode(array_intersect_key($dash, array_flip(['today_profit', 'today_net_revenue', 'today_cogs', 'today_expenses']))));

// previous period headline figures = independent sums for yesterday
$y = date('Y-m-d', strtotime('-1 day'));
$pv = DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('date', $y)->selectRaw('COALESCE(SUM(GrandTotal),0) t, COUNT(*) n')->first();
$pp = (float) DB::table('purchases')->whereNull('deleted_at')->where('statut', 'received')->where('date', $y)->sum('GrandTotal');
check('previous period sales/invoices/purchases = independent read of yesterday', $near($e['previous']['sales'], (float) $pv->t) && $e['previous']['invoices'] === (int) $pv->n && $near($e['previous']['purchases'], $pp) && $e['previous']['from'] === $y, json_encode([$e['previous'], $pv, $pp]));

// --- expenses, cash flow -------------------------------------------------------------------------------------
$cat = DB::table('expense_categories')->value('id') ?: DB::table('expense_categories')->insertGetId(['name' => 'T', 'user_id' => 2, 'description' => 'x', 'created_at' => now(), 'updated_at' => now()]);
DB::table('expenses')->insert(['date' => $today, 'Ref' => 'EXP_T1', 'user_id' => 2, 'expense_category_id' => $cat, 'warehouse_id' => 1, 'details' => 'x', 'amount' => 40, 'created_at' => now(), 'updated_at' => now()]);
DB::table('expenses')->insert(['date' => $today, 'Ref' => 'EXP_T2', 'user_id' => 2, 'expense_category_id' => $cat, 'warehouse_id' => 1, 'details' => 'deleted', 'amount' => 999, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
DB::table('expenses')->insert(['date' => date('Y-m-d', strtotime('-1 day')), 'Ref' => 'EXP_T3', 'user_id' => 2, 'expense_category_id' => $cat, 'warehouse_id' => 1, 'details' => 'yesterday', 'amount' => 25, 'created_at' => now(), 'updated_at' => now()]);
[$c, $e] = $ins();
$dash2x = $classicFn();
check('expenses today = 40 (deleted expense ignored)', $near($e['expenses']['value'], 40) && $near($e['expenses']['value'], (float) $dash2x['today_expenses']), json_encode($e['expenses']));
check('expenses previous period = yesterday 25', $near($e['expenses']['prev'], 25));
check('cash flow: net = money in - money out, in grew by the 300 payment, out is the 40 expense', $near($e['cash_flow']['net'], $e['cash_flow']['inflow'] - $e['cash_flow']['outflow']) && $near($e['cash_flow']['inflow'] - $e0['cash_flow']['inflow'], 300) && $near($e['cash_flow']['outflow'] - $e0['cash_flow']['outflow'], 40), json_encode([$e0['cash_flow'], $e['cash_flow']]));
$rep = json_decode(call(App\Http\Controllers\ReportController::class, 'cash_flow_report' , ['from' => $today, 'to' => $today], 'GET')[1] ?? '{}', true);
if (isset($rep['net_cash_flow'])) check('cash flow equals the Cash Flow report exactly', $near($rep['net_cash_flow'], $e['cash_flow']['net']), json_encode([$rep['net_cash_flow'], $e['cash_flow']['net']]));
check('cash flow previous period is the day before', $near($e['cash_flow']['prev_net'], $e0['cash_flow']['prev_net'] - 25), json_encode([$e0['cash_flow']['prev_net'], $e['cash_flow']['prev_net']]));
// KPI mini-charts: one value per day, equal to independent reads; not offered for a single day
check('single day has no daily series', $ins()[1]['daily'] === null);
$from7 = date('Y-m-d', strtotime('-6 days'));
$w = $ins(['from' => $from7, 'to' => $today])[1];
$dl = $w['daily'];
check('daily series covers every day of the range', is_array($dl) && count($dl['days']) === 7 && $dl['days'][0] === $from7 && $dl['days'][6] === $today && count($dl['expenses']) === 7 && count($dl['net_cash']) === 7);
$expToday = (float) DB::table('expenses')->whereNull('deleted_at')->where('date', $today)->sum('amount');
check('daily expenses: today equals an independent read and the days add up to the KPI', $near($dl['expenses'][6], $expToday) && $near(array_sum($dl['expenses']), $w['expenses']['value']), json_encode($dl['expenses']).' '.$w['expenses']['value']);
check('daily net cash adds up to the net cash flow figure', $near(array_sum($dl['net_cash']), $w['cash_flow']['net']), json_encode($dl['net_cash']).' '.$w['cash_flow']['net']);
check('no internal keys leak into cash_flow', !isset($w['cash_flow']['_net_by_day']));

// --- returns: only received sale returns / completed purchase returns --------------------------------------
$cols = array_flip(DB::getSchemaBuilder()->getColumnListing('sale_returns'));
$mkRet = function (string $statut, float $amt) use ($cols, $today) {
    $row = ['client_id' => 2, 'warehouse_id' => 1, 'user_id' => 2, 'date' => $today, 'Ref' => 'UI2-'.uniqid(), 'statut' => $statut, 'GrandTotal' => $amt, 'paid_amount' => 0,
            'payment_statut' => 'unpaid', 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'created_at' => now(), 'updated_at' => now()];
    return DB::table('sale_returns')->insertGetId(array_intersect_key($row, $cols));
};
$mkRet('received', 70); $mkRet('pending', 5000);
$pc = array_flip(DB::getSchemaBuilder()->getColumnListing('purchase_returns'));
$mkPR = fn ($ref, $st, $amt) => DB::table('purchase_returns')->insert(array_intersect_key(['user_id' => 2, 'provider_id' => 1, 'supplier_id' => 1, 'warehouse_id' => 1, 'date' => $today, 'Ref' => $ref, 'statut' => $st, 'GrandTotal' => $amt, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'created_at' => now(), 'updated_at' => now()], $pc));
$mkPR('UI2P-1', 'completed', 33); $mkPR('UI2P-2', 'pending', 9000);
[$c, $e] = $ins();
$dash2 = $classicFn();
check('returns: received sale return 70 only (pending ignored)', $near($e['returns']['sales'], 70), json_encode($e['returns']));
check('returns: completed purchase return 33 only (pending ignored)', $near($e['returns']['purchases'], 33));
check('returns equal what Classic shows (sales + purchases)', $near($e['returns']['sales'], (float) $dash2['return_sales']) && $near($e['returns']['purchases'], (float) $dash2['return_purchases_amount']), json_encode([$dash2['return_sales'], $dash2['return_purchases_amount']]));

// --- stock health: same rule as the Classic Stock Alert list ---------------------------------------------------
DB::table('products')->where('id', 1)->update(['stock_alert' => 10]);
setStock(1, 1, 3);   // low
DB::table('products')->where('id', 2)->update(['stock_alert' => 5]);
DB::table('product_warehouse')->where('product_id', 2)->where('warehouse_id', 1)->update(['qte' => 0, 'manage_stock' => 1]);   // out
[$c, $e] = $ins();
$expLow = (int) DB::table('product_warehouse')->join('products', 'products.id', '=', 'product_warehouse.product_id')->whereNull('product_warehouse.deleted_at')->whereNull('products.deleted_at')->where('product_warehouse.manage_stock', true)->whereRaw('qte <= stock_alert')->whereIn('product_warehouse.warehouse_id', DB::table('warehouses')->whereNull('deleted_at')->pluck('id'))->count();
check('low/out stock count = rows meeting the Classic alert rule', $e['stock_health']['low_count'] === $expLow && $e['stock_health']['low_count'] >= 2, json_encode([$e['stock_health'], $expLow]));
check('out of stock is the subset with quantity 0 or less', $e['stock_health']['out_count'] >= 1 && $e['stock_health']['out_count'] <= $e['stock_health']['low_count']);
check('attention shows the same low-stock count', $e['attention']['low_stock']['count'] === $e['stock_health']['low_count']);

// dead stock: old product, stock on hand, no sale in 60 days -> counted at cost; sold or brand-new -> not counted
$pcols = array_flip(DB::getSchemaBuilder()->getColumnListing('products'));
$mkProd = function (string $code, string $created) use ($pcols) {
    $row = (array) DB::table('products')->where('id', 1)->first(); unset($row['id']);
    $row['code'] = $code; $row['name'] = 'UI2 '.$code; $row['cost'] = 50; $row['created_at'] = $created; $row['updated_at'] = $created;
    $id = DB::table('products')->insertGetId(array_intersect_key($row, $pcols));
    $pw = (array) DB::table('product_warehouse')->where('product_id', 1)->where('warehouse_id', 1)->first(); unset($pw['id']);
    $pw['product_id'] = $id; $pw['qte'] = 10; $pw['manage_stock'] = 1; DB::table('product_warehouse')->insert($pw);
    return $id;
};
$deadBefore = $ins()[1]['stock_health']['dead_value'];
$oldNoSale = $mkProd('UI2OLD', date('Y-m-d H:i:s', strtotime('-100 days')));
$brandNew = $mkProd('UI2NEW', date('Y-m-d H:i:s'));
$e = $ins()[1];
check('dead stock adds the old unsold product (10 x 50 = 500) and not the brand-new one', $near($e['stock_health']['dead_value'] - $deadBefore, 500), json_encode([$deadBefore, $e['stock_health']['dead_value']]));
[$cx, $bx, $sx] = mkSale([line($oldNoSale, 1, 100)]);
$e = $ins()[1];
check('once it sells, it is no longer dead stock', $near($e['stock_health']['dead_value'], $deadBefore), json_encode([$deadBefore, $e['stock_health']['dead_value']]));

// --- purchases to receive --------------------------------------------------------------------------------------
[$c, $b, $pid1] = mkPurchase(1, 2, 100, 1, ['statut' => 'ordered']);
[$c, $b, $pid2] = mkPurchase(1, 3, 100, 1, ['statut' => 'received']);
$e = $ins()[1];
check('purchases to receive counts ordered/pending purchases only (300 received is not included)', $e['attention']['purchases_to_receive']['count'] >= 1 && $near($e['attention']['purchases_to_receive']['amount'], (float) DB::table('purchases')->whereNull('deleted_at')->whereIn('statut', ['pending', 'ordered'])->sum('GrandTotal')), json_encode($e['attention']['purchases_to_receive']));

// --- customers ---------------------------------------------------------------------------------------------------
$mix = $ins()[1]['customer_mix'];
$sumBy = fn ($cid) => (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('date', $today)->where('client_id', $cid)->sum('GrandTotal');
check('customer mix total = completed sales of the day', $near($mix['total'], (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('date', $today)->sum('GrandTotal')));
check('top customer is the biggest buyer with the right share', $mix['top'][0]['amount'] >= $mix['top'][1]['amount'] && $near($mix['top'][0]['share_pct'], round($mix['top'][0]['amount'] / $mix['total'] * 100, 1)), json_encode($mix['top']));
check('shares of the listed customers never exceed 100%', array_sum(array_column($mix['top'], 'share_pct')) <= 100.05);

// --- geography: case-insensitive city, blank city is reported as such -----------------------------------------
[$cx, $bx, $sg] = mkSale([line(1, 1, 100)], ['client_id' => 1]);   // client 1 city 'dhaka' (lower case), client 2 'Dhaka'
$g = $ins()[1]['geo'];
$dhaka = array_values(array_filter($g['places'], fn ($p) => strtolower($p['city']) === 'dhaka' && $p['source'] === 'city'));
check('Dhaka / dhaka are one place', count($dhaka) === 1, json_encode($g['places']));
$noZone = fn ($c) => (float) DB::table('sales')->whereNull('deleted_at')->where('statut', 'completed')->where('date', $today)->where('client_id', $c)->whereNull('zone_id')->sum('GrandTotal');
check('Dhaka (city) amount = both customers completed zone-less sales today', $near($dhaka[0]['amount'], $noZone(1) + $noZone(2)), json_encode([$dhaka, $sumBy(1), $sumBy(2)]));
check('a customer with no city is "unlocated", not guessed', $near($g['unlocated']['amount'], $sumBy(4) + $sumBy(3)) && $g['unlocated']['invoices'] >= 1, json_encode($g['unlocated']));
check('places + unlocated = the whole period total', $near($g['total'], array_sum(array_column($g['places'], 'amount')) + $g['unlocated']['amount']) && $near($g['total'], $near2 = $ins()[1]['customer_mix']['total']), json_encode([$g['total'], $near2]));

// a sale's own Zone wins over the customer's city; zone-less sales still use the city
if (Schema::hasTable('sale_zones')) {
    $zid = DB::table('sale_zones')->where('name', 'Khulna')->value('id') ?: DB::table('sale_zones')->insertGetId(['name' => 'Khulna', 'created_at' => now(), 'updated_at' => now()]);
    $before = $ins()[1]['geo'];
    [$cz, $bz, $sz] = mkSale([line(1, 1, 100)], ['client_id' => 1]);   // client 1 has city 'dhaka'
    DB::table('sales')->where('id', $sz)->update(['zone_id' => $zid]);
    $after = $ins()[1]['geo'];
    $by = fn ($geo, $n) => array_sum(array_map(fn ($p) => strtolower($p['city']) === $n ? $p['amount'] : 0, $geo['places']));
    check('a zoned sale is placed in its zone, not the customer city', $near($by($after, 'khulna') - $by($before, 'khulna'), 100) && $near($by($after, 'dhaka') - $by($before, 'dhaka'), 0), json_encode([$before['places'], $after['places']]));
    check('zone rows carry source=zone, city rows source=city', collect($after['places'])->where('city', 'Khulna')->first()['source'] === 'zone' && collect($after['places'])->contains(fn ($p) => strtolower($p['city']) === 'dhaka' && $p['source'] === 'city'));
    check('the total is unchanged by where a sale is placed', $near($after['total'] - $before['total'], 100) && $near($after['total'], array_sum(array_column($after['places'], 'amount')) + $after['unlocated']['amount']));
}

// --- activity feed + permission ---------------------------------------------------------------------------------
[$c, $e] = $ins();
check('owner sees the activity feed (or it is off with no rows) as an array', $e['activity_allowed'] === true && is_array($e['activity']));
if ($e['activity']) check('activity row is shaped for the page', isset($e['activity'][0]['description'], $e['activity'][0]['at'], $e['activity'][0]['module']));

// --- who may see this ---------------------------------------------------------------------------------------------
$roleId = DB::table('roles')->insertGetId(['name' => 'Cashier', 'label' => 'Cashier', 'description' => 'x', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
$row = (array) DB::table('users')->where('id', 2)->first(); unset($row['id']);
$row['role_id'] = $roleId; $row['email'] = 'cashier@example.com'; $row['username'] = 'cashier'; $row['is_all_warehouses'] = 0; $row['record_view'] = 0;
$uid = DB::table('users')->insertGetId($row);
DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
Auth::guard('api')->setUser(App\Models\User::find($uid)); Auth::setUser(App\Models\User::find($uid));
[$c, $x] = $ins();
check('a role without the dashboard permission gets 403', $c === 403, $c);
$dashPerm = DB::table('permissions')->where('name', 'dashboard')->value('id');
DB::table('permission_role')->insert(['permission_id' => $dashPerm, 'role_id' => $roleId]);
DB::table('user_warehouse')->insert(['user_id' => $uid, 'warehouse_id' => 1]);
[$c, $x] = $ins();
check('with the permission: only their own records (none yet) and no activity feed', $c === 200 && $x['collected']['invoices'] === 0 && $x['customer_mix']['top'] === [] && $x['activity_allowed'] === false && $x['activity'] === null, $c.' '.json_encode([$x['collected'], $x['activity_allowed']]));
check('their aging shows none of the owner\'s invoices', $near($x['receivables']['total'], 0));

[$c, $x] = $ins(['warehouse_id' => 2]);
check('a warehouse outside their scope is ignored (falls back to their own scope)', $c === 200 && $x['collected']['invoices'] === 0);

finish('UI2 dashboard insights');
