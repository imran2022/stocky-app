<?php
// Audit Batch 4 (M2): Sales / Purchases reports count completed / received documents unless another status is chosen.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\DB;

b4_fixture();
[$c, $b] = call(CT_P, 'store', pHdr(3, ['date' => '2030-01-20', 'statut' => 'pending', 'GrandTotal' => 200, 'details' => [pl(4, 2, 100)]]));
check('pending purchase created', $c == 200, "$c $b");
$call = function ($m, $q) {
    $req = Illuminate\Http\Request::create('/api/x', 'GET', $q);
    $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\ReportController::class)->$m($req)->getContent(), true);
};
$near = fn ($a, $b) => abs($a - $b) < 0.005;
$q = ['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => 50, 'page' => 1, 'SortField' => 'id', 'SortType' => 'desc'];

$s = $call('Report_Sales', $q);
check('sales report default: 3 completed sales, total 961 (pending excluded)', $s['summary']['count'] == 3 && $near($s['summary']['total'], 961), json_encode($s['summary']));
$s = $call('Report_Sales', $q + ['statut' => 'pending']);
check('sales report with status=pending shows the pending sale (999)', $s['summary']['count'] == 1 && $near($s['summary']['total'], 999), json_encode($s['summary']));

$p = $call('Report_Purchases', $q);
check('purchases report default: 1 received purchase (pending excluded)', $p['totalRows'] == 1, json_encode(array_intersect_key($p, ['totalRows' => 1, 'summary' => 1])));
$p = $call('Report_Purchases', $q + ['statut' => 'pending']);
check('purchases report with status=pending shows the pending purchase', $p['totalRows'] == 1, $p['totalRows']);
finish('Audit B4 list reports');
