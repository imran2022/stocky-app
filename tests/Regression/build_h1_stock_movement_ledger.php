<?php

/**
 * Build H1 regression gate — Product Movement Ledger (backend/API only).
 *
 * Static/source checks only (no database required), matching the existing
 * build_*.php regression script convention. This does NOT verify the actual
 * numbers against a live database — do that manually against a real product
 * with known purchase/sale/transfer/adjustment/return/damage history before
 * treating this as verified in production (see README.md for the manual GET
 * request to run).
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "Build H1 regression gate FAILED:\n - Missing file: {$relative}\n");
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

$service = $source('app/Services/Custom/ProductMovementLedgerService.php');
$controller = $source('app/Http/Controllers/ProductsController.php');
$routes = $source('routes/api.php');

// Isolated Service class, not inline in the vendor-owned ProductsController —
// same convention as ProductInsightService / PurchaseOrderReceiptService, to
// keep future vendor merges safe.
$contains($service, 'class ProductMovementLedgerService', 'Service class must exist.');
$contains($service, 'public static function build(', 'Service must expose a build() entry point.');

// All seven movement sources must be present — this is the whole point of
// the feature (a gap here silently drops a whole movement type from every
// product's history, not just an edge case).
$contains($service, "table('purchase_details", 'Must include Purchases.');
$contains($service, "table('sale_details", 'Must include Sales.');
$contains($service, "table('transfer_details", 'Must include Transfers.');
$contains($service, "table('adjustment_details", 'Must include Adjustments.');
$contains($service, "table('sale_return_details", 'Must include Sale Returns.');
$contains($service, "table('purchase_return_details", 'Must include Purchase Returns.');
$contains($service, "table('damage_details", 'Must include Damages.');

// A Transfer must contribute BOTH an out-row (source warehouse) and an
// in-row (destination warehouse) — a single-direction read would make the
// source warehouse's ledger look like stock vanished with no explanation.
$contains($service, 'from_warehouse_id as warehouse_id', 'Transfer-out must read from_warehouse_id.');
$contains($service, 'to_warehouse_id as warehouse_id', 'Transfer-in must read to_warehouse_id.');

// The reconciliation check is the feature's actual payoff (surfacing the
// stock-integrity bug's real impact on live data) — must distinguish a
// missing product_warehouse row from a numeric mismatch, not conflate them.
$contains($service, 'function reconcile(', 'Service must reconcile against product_warehouse.qte.');
$contains($service, "'row_missing'", 'Reconciliation must flag a missing product_warehouse row explicitly.');
$contains($service, "'reconciled'", 'Reconciliation must report a pass/fail per warehouse.');

// Soft-delete correctness — every query must exclude deleted detail AND
// header rows (same class of bug build_d1 fixed for Product Insights).
$deletedAtChecks = substr_count($service, "whereNull('d.deleted_at')");
if ($deletedAtChecks < 7) {
    $errors[] = "All 7 detail-table queries must exclude soft-deleted rows (found {$deletedAtChecks}, need 7).";
}

// The overlay must actually be wired in — controller method + import, and
// the route registered — not just the standalone service sitting unused.
$contains($controller, 'use App\Services\Custom\ProductMovementLedgerService;', 'Controller must import the ledger service.');
$contains($controller, 'public function movement_ledger(Request $request)', 'Controller must expose movement_ledger().');
$contains($controller, 'ProductMovementLedgerService::build(', 'Controller method must call the service.');
$contains($routes, "'ProductsController@movement_ledger'", 'Route must be registered for movement_ledger.');

// --- Build H2 (frontend) checks ---
$card = $source('resources/src/pages/products/MovementHistoryCard.vue');
$productDetails = $source('resources/src/pages/products/ProductDetails.vue');
$stockDetailReport = $source('resources/src/pages/reports/StockDetailReport.vue');

$contains($card, "http.get('products/movement-ledger'", 'Card must call the movement-ledger endpoint.');
$contains($card, 'errorMessage', 'Card must surface request failures visibly, not swallow them silently.');
if (str_contains($card, "\$t(")) {
    $errors[] = 'MovementHistoryCard.vue must use plain English text, not $t() translation keys (explicit request — an unadded key would render as raw key text).';
}

$contains($productDetails, "import MovementHistoryCard from './MovementHistoryCard.vue';", 'Product Details page must import the card.');
$contains($productDetails, '<MovementHistoryCard', 'Product Details page must render the card.');

$contains($stockDetailReport, "import MovementHistoryCard from '../products/MovementHistoryCard.vue';", 'Stock Detail Report page must import the card.');
$contains($stockDetailReport, 'key="movement_history"', 'Stock Detail Report page must add the Movement History tab.');

if (! empty($errors)) {
    fwrite(STDERR, "Build H1 regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Build H1 regression gate passed.\n";
