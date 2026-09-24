<?php
// Audit Batch 4: report endpoints that used to crash on ordinary requests must answer 200.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

const CT_R = App\Http\Controllers\ReportController::class;
$r = fn ($m, $q = []) => call(CT_R, $m, $q + ['from' => '2020-01-01', 'to' => date('Y-m-d'), 'limit' => 10, 'page' => 1], 'GET');
[$c, $b] = $r('warrantyGuaranteeReport');
check('warranty report without SortType answers 200', $c == 200, "$c ".substr($b, 0, 150));
[$c, $b] = $r('warrantyGuaranteeReport', ['SortType' => 'asc', 'SortField' => 'Ref']);
check('warranty report with asc answers 200', $c == 200, "$c ".substr($b, 0, 150));

// ---- stock_inventory_valuation: paging / totals / sorting on the real row grain (H7) ----
$inv = function (array $q) {
    $req = Illuminate\Http\Request::create('/api/x', 'GET', $q);
    $u = Illuminate\Support\Facades\Auth::guard('api')->user();
    $req->setUserResolver(fn () => $u);
    app()->instance('request', $req);
    return json_decode(app(CT_R)->stock_inventory_valuation($req)->getContent(), true);
};
$full = $inv(['limit' => -1, 'SortField' => 'stock_value_cost', 'SortType' => 'desc']);
$n = count($full['reports']);
check('valuation: totalRows equals the number of rows in the unpaginated list', $full['totalRows'] === $n && $n > 5, $full['totalRows'].' vs '.$n);
$paged = []; $pages = (int) ceil($n / 5);
for ($p = 1; $p <= $pages; $p++) {
    $pg = $inv(['limit' => 5, 'page' => $p, 'SortField' => 'stock_value_cost', 'SortType' => 'desc']);
    check("valuation: page $p reports the full total ($n)", $pg['totalRows'] === $n, $pg['totalRows']);
    $paged = array_merge($paged, $pg['reports']);
}
check('valuation: pages together are exactly the full list, no gaps or duplicates', $n === count($paged));
$sig = fn ($r) => $r['sku'].'|'.$r['variant'].'|'.$r['warehouse'];
check('valuation: no row appears twice across pages', count(array_unique(array_map($sig, $paged))) === $n);
$vals = array_column($paged, 'stock_value_cost');
$sorted = $vals; rsort($sorted);
check('valuation: sorting by stock value is global across pages, not just within a page', $vals === $sorted, json_encode(array_slice($vals, 0, 12)));
finish('Audit B4 report smoke');
