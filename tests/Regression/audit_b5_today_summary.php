<?php
// Audit Batch 5: the topbar "Today's summary" agrees with the reports: completed sales only, net = GrandTotal - tax - shipping.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

$sum = function () { [$c, $b] = call(App\Http\Controllers\TodaySummaryController::class, 'index', [], 'GET'); return [$c, json_decode($b, true)]; };
[$c0, $t0] = $sum();
check('summary endpoint answers', $c0 === 200 && isset($t0['sales']), (string) $c0);

[$c, $b, $pend] = mkSale([line(1, 2, 100)], ['statut' => 'pending']);
[$c1, $t1] = $sum();
check('pending sale is not counted', $t1['sales']['transactions'] === $t0['sales']['transactions'] && $t1['sales']['net'] == $t0['sales']['net'], json_encode([$t0['sales']['transactions'], $t1['sales']['transactions']]));

[$c, $b, $done] = mkSale([line(1, 3, 100)], ['shipping' => 20, 'GrandTotal' => 320]);
[$c2, $t2] = $sum();
check('completed sale counted once', $t2['sales']['transactions'] === $t0['sales']['transactions'] + 1, json_encode($t2['sales']));
check('sales net = invoiced total (+320)', abs($t2['sales']['net'] - $t0['sales']['net'] - 320) < 0.005);
check('taxable excludes shipping (+300)', abs($t2['sales']['taxable'] - $t0['sales']['taxable'] - 300) < 0.005, json_encode([$t0['sales']['taxable'], $t2['sales']['taxable']]));
check('items sold counts completed only (+3)', abs($t2['sales']['items'] - $t0['sales']['items'] - 3) < 0.005, json_encode([$t0['sales']['items'], $t2['sales']['items']]));

$fig = App\Support\Reporting\SalesFigures::sales(fn ($q) => $q->whereBetween('sales.date', [date('Y-m-d'), date('Y-m-d')]));
check('taxable equals SalesFigures net', abs($t2['sales']['taxable'] - $fig['net']) < 0.005, json_encode([$t2['sales']['taxable'], $fig['net']]));
check('tax equals SalesFigures tax', abs($t2['sales']['tax'] - $fig['tax']) < 0.005);
check('profit base ties: gross profit = taxable - returns - cogs', abs($t2['profit']['gross'] - ($t2['sales']['taxable'] - App\Support\Reporting\SalesFigures::saleReturns(fn ($q) => $q->whereBetween('sale_returns.date', [date('Y-m-d'), date('Y-m-d')]))['net'] - $t2['profit']['cogs'])) < 0.02);
finish('Audit B5 today summary');
