<?php
// Audit Batch 5 (P1): the product report looked a unit up once per sale line (24,000 queries for 100k sales). The number of
// `units` lookups must not grow with the number of sale lines.
require __DIR__.'/_audit_lib.php';
require __DIR__.'/_audit_b4_fixture.php';
use Illuminate\Support\Facades\{Auth, DB};

b4_fixture();
$countUnitQueries = function () {
    $n = 0; DB::listen(function ($e) use (&$n) { if (str_contains($e->sql, 'from `units`')) { $n++; } });
    $req = Illuminate\Http\Request::create('/api/x', 'GET', ['from' => '2030-01-01', 'to' => '2030-01-31', 'limit' => 25, 'page' => 1, 'SortField' => 'id', 'SortType' => 'desc']);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    app(App\Http\Controllers\ReportController::class)->product_report($req);
    return $n;
};
$few = $countUnitQueries();
// 60 more completed sale lines of the same products
$sid = (int) DB::table('sales')->where('statut', 'completed')->where('date', '2030-01-15')->whereNull('deleted_at')->value('id');
for ($i = 0; $i < 60; $i++) {
    DB::table('sale_details')->insert(['sale_id' => $sid, 'date' => '2030-01-15', 'product_id' => 1 + $i % 2, 'quantity' => 1, 'price' => 1, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2', 'total' => 1, 'sale_unit_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
}
$many = $countUnitQueries();
check("unit lookups do not grow with sale lines ($few -> $many)", $many <= $few, "$few vs $many");
finish('Audit B5 report query count');
