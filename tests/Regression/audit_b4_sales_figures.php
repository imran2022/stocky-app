<?php
// Audit Batch 4: the shared sales figures (net sales, tax, shipping, discount in money) match hand-computed numbers.
require __DIR__.'/_audit_lib.php';
use App\Support\Reporting\SalesFigures;
use Illuminate\Support\Facades\DB;

require __DIR__.'/_audit_b4_fixture.php';
b4_fixture();
$scope = fn ($q) => $q->whereBetween('date', ['2030-01-01', '2030-01-31']);

$f = SalesFigures::sales($scope);
$near = fn ($a, $b) => abs($a - $b) < 0.005;
check('count = 3 completed sales', $f['count'] === 3, $f['count']);
check('gross = 241 + 270 + 450 = 961', $near($f['gross'], 961), $f['gross']);
check('order tax = 11', $near($f['order_tax'], 11), $f['order_tax']);
check('line tax = 20 (exclusive) + 20 (inclusive) = 40', $near($f['line_tax'], 40), $f['line_tax']);
check('shipping = 10', $near($f['shipping'], 10), $f['shipping']);
check('net sales = 961 - 51 tax - 10 shipping = 900', $near($f['net'], 900), $f['net']);
check('header discount in money = 30 (10% of 300) + 50 = 80, never "10 + 50"', $near($f['discount'], 80), $f['discount']);

$r = SalesFigures::saleReturns($scope);
check('only the received return counts (1 return, 100)', $r['count'] === 1 && $near($r['gross'], 100) && $near($r['net'], 100), json_encode($r));
finish('Audit B4 sales figures');
