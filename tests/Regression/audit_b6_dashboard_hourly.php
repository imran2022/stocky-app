<?php
// Audit Batch 6: Dashboard one-day range returns 24 hourly points whose sums equal the day totals of the daily charts.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_purch.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};

$dash = function (string $from, string $to) {
    $req = Request::create('/api/dashboard_data', 'GET', ['from' => $from, 'to' => $to, 'warehouse_id' => 0]);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\DashboardController::class)->dashboard_data($req)->getContent(), true);
};
$today = date('Y-m-d');

[$c, $b, $pend] = mkSale([line(1, 2, 100)], ['statut' => 'pending']);
[$c, $b, $done] = mkSale([line(1, 3, 100)]);
DB::table('sales')->where('id', $done)->update(['time' => '14:30:00']);
[$c, $b, $done2] = mkSale([line(1, 1, 100)]);
DB::table('sales')->where('id', $done2)->update(['time' => '09:05:00']);
call(App\Http\Controllers\PaymentSalesController::class, 'store', ['sale_id' => $done, 'montant' => 120, 'change' => 0, 'date' => $today, 'payment_method_id' => 2, 'account_id' => null, 'notes' => 'x']);
DB::table('payment_sales')->where('sale_id', $done)->update(['created_at' => $today.' 14:10:00']);

$d = $dash($today, $today);
$h = $d['hourly'] ?? null;
check('one-day range returns hourly block', is_array($h) && count($h['hours']) === 24 && count($h['sales']) === 24);
check('hour 14 holds the 14:30 sale (300)', abs($h['sales'][14] - 300) < 0.005, json_encode($h['sales']));
check('hour 9 holds the 09:05 sale (100)', abs($h['sales'][9] - 100) < 0.005);
check('pending sale is not in any hour', abs(array_sum($h['sales']) - array_sum($d['sales']['original']['data'])) < 0.005, json_encode([array_sum($h['sales']), $d['sales']['original']['data']]));
check('hourly purchases add up to the day value', abs(array_sum($h['purchases']) - array_sum($d['purchases']['original']['data'])) < 0.005);
check('hourly received adds up to the daily chart', abs(array_sum($h['received']) - array_sum($d['payments']['original']['payment_received'])) < 0.005, json_encode([array_sum($h['received']), $d['payments']['original']['payment_received']]));
check('hourly sent adds up to the daily chart', abs(array_sum($h['sent']) - array_sum($d['payments']['original']['payment_sent'])) < 0.005);
check('payment lands in its hour (14)', $h['received'][14] >= 120 - 0.005, json_encode($h['received']));

$w = $dash(date('Y-m-d', strtotime('-6 days')), $today);
check('7-day range has no hourly block (charts unchanged)', array_key_exists('hourly', $w) && $w['hourly'] === null && count($w['sales']['original']['days']) === 7);

// a deleted payment leaves the hourly chart, exactly as it leaves the daily one
DB::table('payment_sales')->where('sale_id', $done)->update(['deleted_at' => now()]);
$d2 = $dash($today, $today);
check('deleted payment disappears from the hour', abs($d2['hourly']['received'][14] - ($h['received'][14] - 120)) < 0.005);
finish('Audit B6 dashboard hourly');
