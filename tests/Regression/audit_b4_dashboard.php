<?php
// Audit Batch 4: dashboard numbers follow the same rules as P&L (completed only, same profit) - tie-out between reports.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\DB;

b4_fixture();
$req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => '2030-01-01', 'to' => '2030-01-31']);
$u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
$wh = App\Models\Warehouse::whereNull('deleted_at')->pluck('id')->toArray();
$dash = json_decode(app(App\Http\Controllers\DashboardController::class)->report_dashboard($req, 0, $wh)->getContent(), true)['report'];
$pl = json_decode(app(App\Http\Controllers\ReportController::class)->ProfitAndLoss($req)->getContent(), true)['data'];
$near = fn ($a, $b) => abs($a - $b) < 0.005;

check('dashboard sales total = 961 (pending 999 excluded)', $near($dash['today_sales'], 961), $dash['today_sales']);
check('dashboard invoice count = 3', $dash['today_invoices'] == 3, $dash['today_invoices']);
check('dashboard sale returns = 100 (pending 77 excluded)', $near($dash['return_sales'], 100), $dash['return_sales']);
check('dashboard profit equals Profit & Loss profit for the same range', $near($dash['today_profit'], $pl['profit_fifo']), $dash['today_profit'].' vs '.$pl['profit_fifo']);
check('dashboard sales equal P&L gross sales', $near($dash['today_sales'], $pl['sales_sum']));

// chart panels count completed sales only
$d = App\Http\Controllers\DashboardController::class;
$sc = app($d)->SalesChart(0, $wh, '2030-01-01', '2030-01-31');
$sum = array_sum(array_map('floatval', json_decode(json_encode($sc), true)['original']['data'] ?? []));
check('sales chart total = 961 (pending excluded)', $near($sum, 961), json_encode($sc));
finish('Audit B4 dashboard');
