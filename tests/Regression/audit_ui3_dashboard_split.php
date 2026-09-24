<?php
// Modern Dashboard: the slow product rankings can be fetched separately; split + rest must equal the normal (Classic) response.
require __DIR__.'/_audit_lib.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$today = date('Y-m-d');
$get = function (array $q) use ($today) {
    $req = Request::create('/api/dashboard_data', 'GET', array_merge(['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today, 'warehouse_id' => 0], $q));
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\DashboardController::class)->dashboard_data($req)->getContent(), true);
};
$full = $get([]); $lite = $get(['skip' => 'products']); $only = $get(['only' => 'products']);

check('classic response still has both rankings', isset($full['product_report']['original']) && array_key_exists('products', $full['report_dashboard']['original']));
check('lite response drops only the two rankings', $lite['product_report']['original'] === [] && $lite['report_dashboard']['original']['products'] === []);
$a = $full; $b = $lite;
unset($a['product_report'], $b['product_report'], $a['report_dashboard']['original']['products'], $b['report_dashboard']['original']['products']);
check('everything else is identical between full and lite', json_encode($a) === json_encode($b));
check('only=products returns the year ranking equal to the full one', json_encode($only['product_report']['original']) === json_encode($full['product_report']['original']));
check('only=products returns the month ranking equal to the full one', json_encode($only['top_products']) === json_encode($full['report_dashboard']['original']['products']));
check('only=products response is small (no other sections)', !isset($only['sales']) && !isset($only['report_dashboard']));
finish('UI3 dashboard split');
