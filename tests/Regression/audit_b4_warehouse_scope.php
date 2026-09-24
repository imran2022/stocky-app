<?php
// Audit Batch 4 (H5): a user restricted to some warehouses cannot read another warehouse's figures by passing warehouse_id.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\{Auth, DB};

b4_fixture();   // sales in warehouse 1 (961 completed); purchase P of 105 in warehouse 3
$near = fn ($a, $b) => abs($a - $b) < 0.005;
$run = function (string $method, array $q) {
    $req = Illuminate\Http\Request::create('/api/x', 'GET', $q + ['from' => '2030-01-01', 'to' => '2030-01-31']);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\ReportController::class)->$method($req)->getContent(), true);
};

$all = $run('ProfitAndLoss', ['warehouse_id' => 3])['data'];
check('unrestricted user can filter to warehouse 3 (purchases 105, sales 0)', $near($all['purchases_sum'], 105) && $near($all['sales_sum'], 0), json_encode([$all['purchases_sum'], $all['sales_sum']]));

// restrict the user to warehouse 1 only
DB::table('users')->where('id', 2)->update(['is_all_warehouses' => 0]);
DB::table('user_warehouse')->where('user_id', 2)->delete();
DB::table('user_warehouse')->insert(['user_id' => 2, 'warehouse_id' => 1]);
Auth::guard('api')->setUser(App\Models\User::find(2));

$d = $run('ProfitAndLoss', ['warehouse_id' => 3])['data'];
check('restricted user asking for warehouse 3 sees none of its purchases', $near($d['purchases_sum'], 0), $d['purchases_sum']);
check('...and falls back to their own warehouse (sales 961)', $near($d['sales_sum'], 961), $d['sales_sum']);
$d = $run('ProfitAndLoss', ['warehouse_id' => 1])['data'];
check('restricted user can still filter to their own warehouse', $near($d['sales_sum'], 961), $d['sales_sum']);
finish('Audit B4 warehouse scope');
