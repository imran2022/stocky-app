<?php

/**
 * Build D1 no-dependency Product Insights correctness regression gate.
 *
 * Run with: php tests/Regression/build_d1_product_insight_scope.php
 * Protects warehouse visibility and parent soft-delete filtering while keeping
 * Build C's set-based query architecture and existing metric formulas.
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

if ($service !== false) {
    // Sales metrics: completed + active parent + authorized warehouse scope.
    $contains($service, "->where('s.statut', 'completed')", 'Sales insights must still use completed Sales only.');
    $contains($service, "->whereNull('s.deleted_at')", 'Sales insights must exclude soft-deleted Sales.');
    $contains($service, "'s.warehouse_id'", 'Sales insights must use Sale warehouse scope.');

    // Returns: preserve active return rule and apply the same warehouse boundary.
    $contains($service, "->whereNull('sr.deleted_at')", 'Return insights must exclude soft-deleted Sale Returns.');
    $contains($service, "'sr.warehouse_id'", 'Return insights must use Sale Return warehouse scope.');

    // Purchases: both latest-date and detail-id tie-break candidates must be
    // active, received and inside the authorized warehouse population.
    $contains($service, "->where('latest_p.statut', 'received')", 'Latest Purchase must still use received Purchases.');
    $contains($service, "->whereNull('latest_p.deleted_at')", 'Latest Purchase date query must exclude deleted Purchases.');
    $contains($service, "'latest_p.warehouse_id'", 'Latest Purchase date query must use Purchase warehouse scope.');
    $contains($service, "->whereNull('candidate_p.deleted_at')", 'Latest Purchase tie-break query must exclude deleted Purchases.');
    $contains($service, "'candidate_p.warehouse_id'", 'Latest Purchase tie-break query must use Purchase warehouse scope.');
    $contains($service, "->whereNull('p.deleted_at')", 'Final Latest Purchase row must remain protected from deleted parents.');

    // Shared scope contract: selected warehouse wins; restricted users receive
    // assigned warehouses only; empty assignment returns no transaction rows.
    $contains($service, 'private function applyWarehouseScope(', 'A single warehouse-scope helper must own insight scoping.');
    $contains($service, 'if ($warehouseId)', 'Selected warehouse must take precedence.');
    $contains($service, 'if ($isAllWarehouses)', 'All-warehouse users must retain all-warehouse behavior when no warehouse is selected.');
    $contains($service, "->whereRaw('1 = 0')", 'Restricted users with no assigned warehouses must receive no insight rows.');
    $contains($service, 'whereIntegerInRaw($warehouseColumn, $allowedWarehouseIds)', 'Restricted users must be constrained to assigned warehouses.');

    // G1 intentionally introduces Unit/Multi-Pack normalization via
    // UnitQuantityResolver — see build_g1_unit_quantity_resolver.php. D1's
    // warehouse-boundary contract is unaffected by that and is re-asserted
    // here on its own terms.
    $contains($service, 'UnitQuantityResolver::baseQuantityExpression(', 'G1 base-unit conversion must be present via the isolated resolver, not inline math.');

    // Performance architecture stays set-based.
    $executedQueryCount = preg_match_all('/->get\s*\(/', $service);
    $assert($executedQueryCount === 4, "D1 must retain four executed set-based metric queries; found {$executedQueryCount}.");
}

if ($controller !== false) {
    // Server-side sorting must match the now-scoped displayed metrics.
    $contains($controller, "->whereNull('sales.deleted_at')", 'Product insight sort subqueries must exclude deleted Sales.');
    $contains($controller, "->where('sales.warehouse_id', \$warehouseId)", 'Product insight sorting must honor an explicitly selected warehouse.');
    $contains($controller, "whereIntegerInRaw('sales.warehouse_id', \$allowedWarehouseIds)", 'Product insight sorting must honor restricted warehouse assignments.');
    $contains($controller, "->whereRaw('1 = 0')", 'Product insight sorting must return no restricted Sale rows when no warehouse is assigned.');
}

if ($failures) {
    fwrite(STDERR, "Build D1 Product Insight scope gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build D1 Product Insight scope gate: PASS (warehouse visibility + soft-delete correctness protected).\n";
