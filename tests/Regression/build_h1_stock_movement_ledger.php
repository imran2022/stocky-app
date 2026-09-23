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

// Only purchase_return_details has a deleted_at column. Referencing that
// column on the other detail tables makes the endpoint fail at runtime.
$deletedAtChecks = substr_count($service, "whereNull('d.deleted_at')");
if ($deletedAtChecks !== 1) {
    $errors[] = "Only purchase_return_details may be filtered by d.deleted_at (found {$deletedAtChecks}, need exactly 1).";
}

// Stock-affecting statuses must mirror the transaction controllers. Draft,
// pending and rejected documents do not belong in the movement ledger.
$contains($service, "where('h.statut', 'received')", 'Purchases and sale returns must be limited to received status.');
$contains($service, "where('h.statut', 'completed')", 'Sales, completed transfers and purchase returns must use completed status.');
$contains($service, "whereIn('h.statut', ['completed', 'sent'])", 'Transfer-out must include approved sent and completed transfers.');
$contains($service, "where('h.approval_status', 'approved')", 'Pending/rejected transfers must be excluded.');

// Unit/pack conversion is required for the running balance to reconcile with
// product_warehouse.qte when alternate units or multi-pack selling are used.
$contains($service, 'convertedQuantity(', 'Movement quantities must be converted to base units.');
$contains($service, 'pack_multiplier', 'Sale and sale-return pack multipliers must be applied.');
$contains($service, "leftJoin('clients as party'", 'Sales and sale returns must expose the customer name.');
$contains($service, "leftJoin('providers as party'", 'Purchases and purchase returns must expose the supplier name.');
$contains($service, "'party_name'", 'Movement rows must return customer/supplier information.');

// Restricted users must not be able to read movement from unassigned
// warehouses, either by selecting one explicitly or by omitting the filter.
$contains($controller, '$allowedWarehouseIds', 'Controller must resolve the user warehouse scope.');
$contains($controller, "abort(403, 'You are not authorized to view this warehouse.')", 'Out-of-scope warehouse requests must be rejected.');
$contains($service, 'applyWarehouseScope(', 'Every ledger source must apply the authorized warehouse scope.');

// Date ranges need an opening balance, and a historical closing balance must
// not be compared to today's live stock quantity.
$contains($service, "'opening_balances'", 'Date-filtered ledgers must expose opening balances.');
$contains($service, "'reconciliation_mode'", 'Historical ranges must identify their reconciliation mode.');
$contains($service, "'summary' =>", 'Movement ledger must return the in/out/current-stock summary.');
$contains($service, "abs(\$row['qty_in'])", 'Zero-quantity detail lines must be excluded from the ledger.');
$contains($service, "'product_variant_id'", 'Movement rows must identify their product variant.');
$contains($service, "'opening_stock'", 'Opening-stock adjustments must be separated from ordinary adjustments.');

// The overlay must actually be wired in — controller method + import, and
// the route registered — not just the standalone service sitting unused.
$contains($controller, 'use App\Services\Custom\ProductMovementLedgerService;', 'Controller must import the ledger service.');
$contains($controller, 'public function movement_ledger(Request $request)', 'Controller must expose movement_ledger().');
$contains($controller, 'ProductMovementLedgerService::build(', 'Controller method must call the service.');
$contains($routes, "'ProductsController@movement_ledger'", 'Route must be registered for movement_ledger.');
if (! preg_match('/function movement_ledger\(Request \$request\).*?authorizeForUser\(\$request->user\(\'api\'\), \'view\', Product::class\)/s', $controller)) {
    $errors[] = 'movement_ledger must call the ProductPolicy view ability (which maps to products_view).';
}

$literalRoute = strpos($routes, "Route::get('products/movement-ledger'");
$resourceRoute = strpos($routes, "Route::resource('products'");
if ($literalRoute === false || $resourceRoute === false || $literalRoute > $resourceRoute) {
    $errors[] = 'products/movement-ledger must be registered before products/{product} resource routing.';
}

// --- Build H2 (frontend) checks ---
$card = $source('resources/src/pages/products/MovementHistoryCard.vue');
$productDetails = $source('resources/src/pages/products/ProductDetails.vue');
$stockDetailReport = $source('resources/src/pages/reports/StockDetailReport.vue');
$movementPage = $source('resources/src/pages/products/MovementHistory.vue');
$router = $source('resources/src/router/index.js');
$menu = $source('resources/src/config/menu.js');

$contains($card, "http.get('products/movement-ledger'", 'Card must call the movement-ledger endpoint.');
$contains($card, 'errorMessage', 'Card must surface request failures visibly, not swallow them silently.');
$contains($card, 'comparison_available', 'Card must not show historical ranges as live-stock mismatches.');
$contains($card, 'record.reference', 'Card must display the document reference instead of only its numeric database id.');
$contains($card, 'Customer / Supplier', 'Card must display customer/supplier information.');
$contains($card, "title: 'Variant'", 'Card must show the variant responsible for a movement.');
$contains($card, 'Quantities In', 'Dedicated ledger summary must show quantity-in totals.');
$contains($card, 'Quantities Out', 'Dedicated ledger summary must show quantity-out totals.');
$contains($card, "exportExcel('product_movement_history'", 'Movement History must support Excel export.');
$contains($card, 'exportPdf(props.title', 'Movement History must support PDF export.');
if (str_contains($card, "\$t(")) {
    $errors[] = 'MovementHistoryCard.vue must use plain English text, not $t() translation keys (explicit request — an unadded key would render as raw key text).';
}

$contains($productDetails, "import MovementHistoryCard from './MovementHistoryCard.vue';", 'Product Details page must import the card.');
$contains($productDetails, '<MovementHistoryCard', 'Product Details page must render the card.');

$contains($stockDetailReport, "import MovementHistoryCard from '../products/MovementHistoryCard.vue';", 'Stock Detail Report page must import the card.');
$contains($stockDetailReport, 'key="movement_history"', 'Stock Detail Report page must add the Movement History tab.');

// Dedicated Products > Movement History workspace.
$contains($routes, "products/movement-history/meta", 'Movement History filter metadata route must be registered.');
$contains($movementPage, '<MovementHistoryCard', 'Dedicated Movement History page must render the shared ledger card.');
$contains($movementPage, 'selectedVariantId', 'Dedicated page must support variant filtering.');
$contains($movementPage, 'selectedWarehouseId', 'Dedicated page must support warehouse filtering.');
$contains($movementPage, ':show-summary="true"', 'Dedicated page must display the movement summary.');
$contains($movementPage, ':show-export="true"', 'Dedicated page must expose report downloads.');
$contains($router, "path: 'products/movement-history'", 'Dedicated Movement History page must be routed.');
$contains($menu, "to: '/app/products/movement_history'", 'Products menu must include Movement History.');

if (! empty($errors)) {
    fwrite(STDERR, "Build H1 regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Build H1 regression gate passed.\n";
