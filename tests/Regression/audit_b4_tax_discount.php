<?php
// Audit Batch 4 (M15/M16): Tax summary carries line + order tax and ties to the P&L; Discount summary includes promotions.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\DB;

b4_fixture();
$call = function ($m, $q) {
    $req = Illuminate\Http\Request::create('/api/x', 'GET', $q);
    $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    return json_decode(app(App\Http\Controllers\ReportController::class)->$m($req)->getContent(), true);
};
$near = fn ($a, $b) => abs($a - $b) < 0.005;
$range = ['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => 50];

$t = $call('taxSummary', $range);
check('tax total = 51 (line 40 + order 11)', $near($t['totals']['tax'], 51), json_encode($t['totals']));
check('taxable base total = net sales 900', $near($t['totals']['base'], 900), json_encode($t['totals']));
check('3 sales in the table (pending excluded)', $t['totalRows'] == 3 && count($t['report']) == 3, $t['totalRows']);
$byTax = collect($t['report'])->pluck('tax_collected')->map(fn ($v) => round($v, 2))->sort()->values()->all();
check('per-sale tax = 0, 20, 31', $byTax === [0.0, 20.0, 31.0], json_encode($byTax));
$pl = $call('ProfitAndLoss', $range)['data'];
check('tax summary total equals the Profit & Loss "Taxes collected"', $near($t['totals']['tax'], $pl['sales_tax_sum']));
check('daily timeseries adds up to the total', $near(collect($t['timeseries'])->sum('tax_collected'), 51));
check('net tax after returns present (returns carry no tax here)', $near($t['totals']['net_tax'], 51), json_encode($t['totals']));

// discount summary: a promotion discount is a discount too
$D2 = '2030-02-10';
[$c, $b, $sid] = mkSale([line(1, 1, 500, ['subtotal' => 500])], ['date' => $D2, 'discount' => 50, 'discount_Method' => '2', 'GrandTotal' => 450]);
DB::table('sales')->where('id', $sid)->update(['promotion_discount' => 20]);
$s = $call('discountSummary', ['from' => '2030-02-01', 'to' => '2030-02-28', 'limit' => 50]);
check('discount summary = fixed 50 + promotion 20 = 70', $near($s['overall_total'], 70), $s['overall_total']);
$s1 = $call('discountSummary', $range);
check('January discount total = 80 (10% converted to 30, plus 50)', $near($s1['overall_total'], 80), $s1['overall_total']);
finish('Audit B4 tax and discount summaries');
