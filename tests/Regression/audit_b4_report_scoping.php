<?php
// Audit Batch 4 (H4/M1-M14): product/seller/customer reports ignore pending sales and no longer crash on orphan sale lines.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\DB;

b4_fixture();   // completed sales A,B,C on 2030-01-15 (product 1 sold 2+1+1 = 4 units; product 2 once); pending sale D sells product 1 (999)
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('sale_details')->insert(['sale_id' => 987654, 'product_id' => 1, 'quantity' => 1, 'price' => 5, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => 5, 'date' => '2030-01-15', 'created_at' => now(), 'updated_at' => now()]);
$call = function ($m, $q, $verb = 'GET') {
    $req = Illuminate\Http\Request::create('/api/x', $verb, $q);
    $u = Illuminate\Support\Facades\Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    try { $r = app(App\Http\Controllers\ReportController::class)->$m($req); return [$r->getStatusCode(), json_decode($r->getContent(), true)]; }
    catch (Throwable $e) { return ['EXC', get_class($e).': '.substr($e->getMessage(), 0, 160)]; }
};
$near = fn ($a, $b) => abs($a - $b) < 0.005;
$q = ['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => 50, 'page' => 1, 'SortField' => 'id', 'SortType' => 'desc'];

[$c, $d] = $call('sale_products_details', $q + ['id' => 1]);
check('product sales detail answers 200 despite an orphan sale line', $c == 200, is_string($d) ? $d : $c);
$rows = $d['sales'] ?? $d['report'] ?? [];
check('...and lists only the 3 completed sales of product 1 (pending and orphan excluded)', is_array($rows) && count($rows) == 3, is_array($rows) ? count($rows) : json_encode($d));

[$c, $d] = $call('get_sales_by_product', $q + ['id' => 1]);
check('sales-by-product answers 200 despite an orphan line', $c == 200, is_string($d) ? $d : $c);

[$c, $d] = $call('product_sales_report', $q);
check('product sales report answers 200 despite an orphan line', $c == 200, is_string($d) ? $d : $c);

[$c, $d] = $call('report_top_products', $q);
check('top products answers 200', $c == 200, is_string($d) ? $d : $c);
$p1 = collect($d['products'] ?? $d['report'] ?? $d['data'] ?? [])->firstWhere('code', DB::table('products')->where('id', 1)->value('code'));
check('top products: product 1 revenue = 200+... completed lines only (pending 999 excluded)', $p1 && $near($p1['total'] ?? 0, 220 + 200 + 500), json_encode($p1));

// customer report: counts completed sales only, returns received only
DB::table('sales')->where('statut', 'pending')->where('date', '2030-01-15')->update(['client_id' => 2]);
[$c, $d] = $call('Client_Report', ['limit' => 200, 'page' => 1, 'search' => DB::table('clients')->where('id', 2)->value('name')]);
check('customer report answers 200', $c == 200, is_string($d) ? $d : $c);
finish('Audit B4 report scoping');
