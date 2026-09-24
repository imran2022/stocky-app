<?php
// Audit Batch 4 (H1/H2): Analytics summary - no double-discounting, tax/shipping excluded, discounts in money.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';

b4_fixture();
$req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => '2030-01-01', 'to' => '2030-01-31']);
$u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
$d = json_decode(app(App\Http\Controllers\ReportController::class)->analyticsSummary($req)->getContent(), true);
$near = fn ($a, $b) => abs($a - $b) < 0.005;

check('total sales excl. tax = net sales 900 (was 890: discount taken twice, line tax ignored)', $near($d['total_sales_excl_tax'], 900), $d['total_sales_excl_tax']);
check('sell shipping charge 10', $near($d['total_sell_shipping_charge'], 10), $d['total_sell_shipping_charge']);
check('sell discount in money 80 (was 10 + 50 = 60: percent added as taka)', $near($d['total_sell_discount'], 80), $d['total_sell_discount']);
check('customer reward 0', $near($d['total_customer_reward'], 0), $d['total_customer_reward']);
check('purchase excl. tax = 90 (goods 100 - discount 10; no tax, no shipping)', $near($d['total_purchase_excl_tax'], 90), $d['total_purchase_excl_tax']);
check('purchase shipping 5', $near($d['total_purchase_shipping_charge'], 5), $d['total_purchase_shipping_charge']);
check('purchase discount 10', $near($d['total_purchase_discount'], 10), $d['total_purchase_discount']);
finish('Audit B4 analytics');
