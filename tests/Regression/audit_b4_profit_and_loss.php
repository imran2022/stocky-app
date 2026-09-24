<?php
// Audit Batch 4 (C4/H2): Profit & Loss uses the shared definitions: net sales excl. tax and delivery charge, returns netted.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\DB;

b4_fixture();   // A 241, B 270, C 450 completed (+999 pending); returns 100 received (+77 pending); 2030-01-15
$req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => '2030-01-01', 'to' => '2030-01-31']);
$u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
$d = json_decode(app(App\Http\Controllers\ReportController::class)->ProfitAndLoss($req)->getContent(), true)['data'];
$near = fn ($a, $b) => abs($a - $b) < 0.005;

check('gross sales 961 (pending 999 excluded)', $near($d['sales_sum'], 961), $d['sales_sum']);
check('sales count 3', $d['sales_count'] == 3, $d['sales_count']);
check('taxes collected = 11 order + 40 line = 51', $near($d['sales_tax_sum'], 51), $d['sales_tax_sum']);
check('discounts given in money = 80', $near($d['sales_discount_sum'], 80), $d['sales_discount_sum']);
check('shipping charged 10', $near($d['sales_shipping_sum'], 10), $d['sales_shipping_sum']);
check('net sales 900', $near($d['sales_net_sum'], 900), $d['sales_net_sum']);
check('sale returns 100 (pending 77 excluded)', $near($d['returns_sales_sum'], 100) && $d['returns_sales_count'] == 1, $d['returns_sales_sum']);
check('revenue = 900 - 100 = 800', $near($d['total_revenue'], 800), $d['total_revenue']);
check('profit = revenue - COGS - expenses + service profit (FIFO)', $near($d['profit_fifo'], 800 - $d['product_cost_fifo'] - $d['expenses_sum'] + $d['service_profit']), json_encode([$d['profit_fifo'], $d['product_cost_fifo']]));
check('profit = revenue - COGS - expenses + service profit (average cost)', $near($d['profit_average_cost'], 800 - $d['averagecost'] - $d['expenses_sum'] + $d['service_profit']));
check('UI components reconcile: gross - returns - tax - shipping (net of returns) = revenue',
    $near($d['sales_sum'] - $d['returns_sales_sum'] - $d['tax_net_of_returns'] - $d['shipping_net_of_returns'], $d['total_revenue']));
finish('Audit B4 profit and loss');
