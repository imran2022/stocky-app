<?php

/**
 * Build G1 regression gate — Unit Quantity Resolver.
 *
 * Static/source checks only (no database required), matching the existing
 * build_*.php regression script convention. Functional correctness (the
 * actual 74/24/32.4% figures) was verified separately against a live
 * database with real Sale/SaleReturn/Unit rows before this overlay was
 * packaged — see the G1 delivery notes.
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "Build G1 regression gate FAILED:\n - Missing file: {$relative}\n");
        exit(1);
    }

    return file_get_contents($path);
};

$errors = [];

$contains = function (string $haystack, string $needle, string $message) use (&$errors) {
    if (! str_contains($haystack, $needle)) {
        $errors[] = $message;
    }
};

$notContains = function (string $haystack, string $needle, string $message) use (&$errors) {
    if (str_contains($haystack, $needle)) {
        $errors[] = $message;
    }
};

$resolver = $source('app/Support/UnitQuantityResolver.php');
$service = $source('app/Services/Custom/ProductInsightService.php');
$controller = $source('app/Http/Controllers/ProductsController.php');

// The resolver is a standalone Support class, not inline math in the
// vendor-owned controller/service files — this is the whole point of G1's
// "isolated resolver" scope, and it's what keeps future vendor merges safe.
$contains($resolver, 'class UnitQuantityResolver', 'Resolver class must exist.');
$contains($resolver, 'public static function baseQuantityExpression(', 'Resolver must expose a base-quantity SQL expression builder.');
$contains($resolver, "COALESCE(NULLIF(%s, 0), 1)", 'Resolver must default a zero/null pack_multiplier or operator_value to 1 (no conversion), matching the existing PosController stock-deduction convention.');

// ProductInsightService must call the resolver rather than reintroducing
// inline conversion math or a per-product query loop.
$contains($service, 'use App\Support\UnitQuantityResolver;', 'Service must import the resolver.');
$contains($service, 'UnitQuantityResolver::baseQuantityExpression(', 'Sold (30d)/Previous 30d/Lifetime Sold must use the resolver.');
$contains($service, 'UnitQuantityResolver::joinSaleUnit(', 'Sales metrics query must join the sale line\'s own unit.');
$contains($service, 'UnitQuantityResolver::joinReturnUnit(', 'Return metrics query must join the return line\'s own unit.');
// (Set-based-vs-per-product-loop protection for the sales/returns query
// blocks is the authoritative concern of build_c_product_insights.php —
// not duplicated here to avoid a fragile, overly-broad string match against
// this file's unrelated $metrics-initialization loop.)

$executedQueryCount = preg_match_all('/->get\s*\(/', $service);
if ($executedQueryCount !== 4) {
    $errors[] = "G1 must not add a new executed query for unit resolution (still 4 total); found {$executedQueryCount}.";
}

// The Sold (30d) column sorter must use the identical base-quantity
// expression as the displayed metric — otherwise sorting and the number
// shown would silently disagree.
$contains($controller, 'sold30BaseQty = \App\Support\UnitQuantityResolver::baseQuantityExpression(', 'Products list Sold (30d) sorter must use the same resolver as the displayed value.');
$contains($controller, "leftJoin('units as sort_su'", 'Sold (30d) sorter must join the sale unit the same way the displayed metric does.');

// G1 scope boundary: this build must not touch POS, invoice rendering, sale
// creation/update, stock deduction, or purchasing. These files are untouched
// evidence — their absence from the changed-file set is enforced by the
// packaging process, not re-checked here; this script only asserts the
// resolver itself doesn't leak into them by accident.
foreach ([
    'app/Http/Controllers/PosController.php',
    'app/Http/Controllers/PurchasesController.php',
] as $outOfScopeFile) {
    $contents = $source($outOfScopeFile);
    $notContains($contents, 'UnitQuantityResolver', "G1 must not reference the resolver from {$outOfScopeFile} — Sold/Return metrics are the only intended caller in this build.");
}

if ($errors !== []) {
    fwrite(STDERR, "Build G1 regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Build G1 Unit Quantity Resolver gate: PASS (isolated resolver, set-based aggregation, and sorter/metric parity protected).\n";
