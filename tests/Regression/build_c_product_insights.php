<?php

/**
 * Build C no-dependency Product Insights performance regression gate.
 *
 * Run with: php tests/Regression/build_c_product_insights.php
 * This protects the set-based query extraction without changing metric semantics.
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};
$notContains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(! str_contains($haystack, $needle), $message);
};

$root = dirname(__DIR__, 2);
$controllerPath = $root.'/app/Http/Controllers/ProductsController.php';
$servicePath = $root.'/app/Services/Custom/ProductInsightService.php';

$controller = file_get_contents($controllerPath);
$service = file_get_contents($servicePath);

$assert($controller !== false, 'ProductsController.php must be readable.');
$assert($service !== false, 'ProductInsightService.php must be readable.');

if ($controller !== false) {
    $contains($controller, 'use App\\Services\\Custom\\ProductInsightService;', 'ProductsController must use the custom insight service.');
    $contains($controller, '$productInsights = $productInsightService->forProducts(', 'ProductsController must prefetch insights once for the result set through the shared insight service.');

    $marker = '// ----- Extra business-insight fields (same for every product type) -----';
    $start = strpos($controller, $marker);
    $end = $start === false ? false : strpos($controller, '$data[] = $item;', $start);
    $assert($start !== false && $end !== false, 'Product insight mapping block must remain identifiable.');

    if ($start !== false && $end !== false) {
        $insightBlock = substr($controller, $start, $end - $start);
        $notContains($insightBlock, 'SaleDetail::join(', 'Insight mapping must not query sales per product.');
        $notContains($insightBlock, 'PurchaseDetail::join(', 'Insight mapping must not query purchases per product.');
        $notContains($insightBlock, 'SaleReturnDetails::join(', 'Insight mapping must not query returns per product.');
        $notContains($insightBlock, 'warehouseCountQuery', 'Insight mapping must not count warehouses per product.');
    }
}

if ($service !== false) {
    $contains($service, "->where('s.statut', 'completed')", 'Completed-sale semantics must remain unchanged.');
    $contains($service, "->whereNull('sr.deleted_at')", 'Existing Sale Return soft-delete rule must be preserved.');
    $contains($service, "->where('latest_p.statut', 'received')", 'Received-purchase semantics must remain unchanged.');
    $contains($service, "selectRaw('MAX(latest_p.date) as last_purchase_date')", 'Latest Purchase must first select the maximum received purchase date per Product.');
    $contains($service, "selectRaw('MAX(candidate_pd.id) as last_purchase_detail_id')", 'Latest Purchase must preserve the legacy detail-id tie-break on the latest date.');
    $contains($service, 'COUNT(DISTINCT pw.warehouse_id)', 'Warehouse count must remain distinct by warehouse.');

    // Four executed set-based queries replace the former seven queries per Product.
    // The latest-purchase query contains two nested query-builder subqueries but
    // is still sent to the database as one SQL statement.
    $executedQueryCount = preg_match_all('/->get\s*\(/', $service);
    $assert($executedQueryCount === 4, "Build C insight service must execute four set-based metric queries; found {$executedQueryCount}.");

    // G1 (a later, explicitly-scoped build) intentionally introduces
    // Unit/Multi-Pack normalization via UnitQuantityResolver — see
    // build_g1_unit_quantity_resolver.php for that contract. This block now
    // only guards that the set-based (non-per-product-query) shape survives.
    $salesStart = strpos($service, "DB::table('sale_details as sd')");
    $salesEnd = $salesStart === false ? false : strpos($service, "DB::table('sale_return_details as srd')", $salesStart);
    if ($salesStart !== false && $salesEnd !== false) {
        $salesBlock = substr($service, $salesStart, $salesEnd - $salesStart);
        $notContains($salesBlock, 'foreach ($productIds as $productId) {', 'Build C must not regress into a per-product query loop for sales metrics.');
    }
}

if ($failures) {
    fwrite(STDERR, "Build C Product Insights gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build C Product Insights gate: PASS (set-based metric query contract preserved).\n";
