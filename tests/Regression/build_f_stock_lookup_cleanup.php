<?php

/**
 * Build F no-dependency Stock Lookup regression gate.
 *
 * Run with: php tests/Regression/build_f_stock_lookup_cleanup.php
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

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root.'/app/Http/Controllers/ProductsController.php');
$page = file_get_contents($root.'/resources/src/pages/products/StockLookup.vue');
$compiled = file_get_contents($root.'/public/js/chunks/StockLookup.D-UahGfI.js');
$manifest = file_get_contents($root.'/public/js/.vite/manifest.json');
$serviceWorker = file_get_contents($root.'/public/sw.js');

foreach ([
    'ProductsController.php' => $controller,
    'StockLookup.vue' => $page,
    'compiled Stock Lookup chunk' => $compiled,
    'Vite manifest' => $manifest,
    'service worker' => $serviceWorker,
] as $name => $contents) {
    $assert($contents !== false, "$name must be readable.");
}

if ($controller !== false) {
    $contains($controller, "'unit:id,name,ShortName'", 'Detail API must load the product stock unit.');
    $contains($controller, "'unit_label' => \$unitLabel", 'Detail API must return the actual stock-unit label.');
    $contains($controller, "\$query->whereNull('deleted_at')->orderBy('id');", 'Variant eager loads must exclude soft-deleted rows.');
    $contains($controller, "\$vq->whereNull('deleted_at')", 'Variant-code search must exclude soft-deleted rows.');
    $contains($controller, "->whereIn('product_variant_id', \$activeVariantIds)", 'Variant stock aggregation must include active variants only.');
    $contains($controller, "'price_min'", 'Variant price range minimum must be returned.');
    $contains($controller, "'price_max'", 'Variant price range maximum must be returned.');
}

if ($page !== false) {
    $contains($page, 'function priceLabel(product)', 'Stock Lookup must render simple prices and variant ranges through one helper.');
    $contains($page, 'detail.product.unit_label', 'Stock quantities must render the API-provided stock unit.');
    $assert(! str_contains($page, 'Pcs'), 'Stock Lookup source must not hardcode Pcs.');
}

if ($compiled !== false) {
    $contains($compiled, 'function C0(', 'Deployment chunk must include variant-aware price rendering.');
    $contains($compiled, 'unit_label', 'Deployment chunk must include dynamic unit rendering.');
    $contains($compiled, 'price_min', 'Deployment chunk must include variant price-range rendering.');
    $assert(! str_contains($compiled, 'Pcs'), 'Deployment chunk must not hardcode Pcs.');
}

if ($manifest !== false) {
    $contains($manifest, 'chunks/StockLookup.D-UahGfI.js', 'Vite manifest must still point to the synchronized Stock Lookup chunk.');
}

if ($serviceWorker !== false) {
    $matched = preg_match("/const VERSION = 'stocky-pwa-v(\d+)';/", $serviceWorker, $versionMatch);
    $assert($matched === 1 && (int) ($versionMatch[1] ?? 0) >= 12, 'PWA cache version must be v12 or later for Build F.');
}

if ($failures) {
    fwrite(STDERR, "Build F Stock Lookup cleanup gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build F Stock Lookup cleanup gate: PASS (unit, active variants, and variant price display protected).\n";
