<?php

/**
 * Phase 0 (Purchase form quick wins) regression gate.
 *
 * Static/source checks, matching the existing build_*.php convention.
 * Functional correctness (Last Purchase/Sell Price/Profit% actual values)
 * was verified separately against live data before this overlay was
 * packaged — see the delivery notes.
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "Phase 0 regression gate FAILED:\n - Missing file: {$relative}\n");
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

$controller = $source('app/Http/Controllers/ProductsController.php');
$form = $source('resources/src/pages/purchases/PurchaseForm.vue');

// Backend: Last Purchase reference on the existing per-item endpoint.
$contains($controller, "\$item['last_purchase'] = null;", 'show_product_data must default last_purchase to null.');
$contains($controller, "PurchaseDetail::join('purchases'", 'Last Purchase lookup must join purchases for date/status.');
$contains($controller, "leftJoin('providers'", 'Last Purchase lookup must left-join providers for the supplier name.');
$contains($controller, "->where('purchases.statut', 'received')", 'Last Purchase must only consider received purchases.');
$contains($controller, "->whereNull('purchases.deleted_at')", 'Last Purchase must exclude deleted purchases.');
$contains($controller, "orderByDesc('purchases.date')", 'Last Purchase must order by most recent date.');

// Frontend: line object captures the new fields, inline cost edit, and the
// two new display-only columns.
$contains($form, 'last_purchase: d.last_purchase || null', 'Line object must carry last_purchase from the API response.');
$contains($form, 'Unit_price: d.Unit_price', 'Line object must carry Unit_price (Sell Price) from the API response.');
$contains($form, 'function setUnitCost(line, value)', 'Inline Net Unit Cost edit must exist.');
$contains($form, 'recomputeCostLine(line);', 'setUnitCost must reuse the same recompute path as the existing modal edit — not a second, divergent calculation.');
$contains($form, 'function profitPct(line)', 'Profit % must be computed client-side from already-loaded fields.');
$contains($form, "key: 'sell_price'", 'Sell Price column must be present.');
$contains($form, "key: 'profit_pct'", 'Profit % column must be present.');

// Scope containment: this phase must not touch Sales, POS, or Shipment
// quantity/pricing logic.
foreach ([
    'app/Http/Controllers/SalesController.php',
    'app/Http/Controllers/PosController.php',
] as $outOfScopeFile) {
    $contents = $source($outOfScopeFile);
    if (str_contains($contents, "'last_purchase'")) {
        $errors[] = "Phase 0's last_purchase addition must not leak into {$outOfScopeFile} — Purchase form is the only intended caller in this change.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Phase 0 regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Phase 0 Purchase Form Enhancements gate: PASS (Last Purchase hint, inline cost edit, Sell Price/Profit% columns protected).\n";
