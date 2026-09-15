<?php

/**
 * PO View Page + Human-Readable Labels regression gate.
 *
 * Real bug found via a user report and screenshots: (1) the list's "View"
 * action routed to the edit form — there was no dedicated read-only page —
 * and (2) many new labels used `$t('SomeKey') || 'Fallback text'`, which
 * does NOT work the way it looks: when a translation key is missing,
 * vue-i18n returns the key itself as a non-empty string, so `||` never
 * reaches the fallback — the raw key (e.g. "PoStatusAutoNote") rendered
 * on screen instead of readable text. Confirmed against the actual
 * `translations` table which keys exist before deciding which `t()` calls
 * were safe to keep and which needed to become plain text.
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "PO view/labels gate FAILED:\n - Missing file: {$relative}\n");
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

// ----- View page exists and is properly routed -----
$source('resources/src/pages/purchase_orders/PurchaseOrderDetails.vue'); // exits if missing

$router = $source('resources/src/router/index.js');
$contains($router, "path: 'purchase-orders/:id(\\\\d+)/view'", 'Router must register a dedicated PO view route.');
$contains($router, 'PurchaseOrderDetails', 'Router must lazy-import the PurchaseOrderDetails component.');

$poList = $source('resources/src/pages/purchase_orders/PurchaseOrders.vue');
$contains($poList, "router.push(`/purchase-orders/\${record.id}/view`)", "The list's View action (both the Ref link and the action-menu item) must route to the dedicated view page, not the edit form.");
// The bug this replaces: view and edit sharing one route.
$notContains($poList, "if (key === 'detail' || key === 'edit')", "View and Edit must be handled as separate cases, not merged into one route push.");

// ----- Always-visible columns (previously incorrectly defaultHidden) -----
$contains($poList, "{ title: 'Created By', dataIndex: 'created_by_name', key: 'created_by_name' }", 'Created By column must be always-visible (no defaultHidden).');
$contains($poList, "{ title: 'Last GRN Date', dataIndex: 'last_grn_date', key: 'last_grn_date',", 'Last GRN Date column must be always-visible (no defaultHidden).');

// ----- Human-readable labels: the specific keys confirmed NOT to exist in
// the translations table must no longer be wrapped in a t()||fallback call
// that silently never reaches its fallback. -----
$filesToCheck = [
    'resources/src/pages/purchase_orders/PurchaseOrders.vue',
    'resources/src/pages/purchase_orders/PurchaseOrderForm.vue',
    'resources/src/pages/purchases/PurchaseForm.vue',
    'resources/src/pages/reports/PriceVarianceReport.vue',
];
$confirmedMissingKeys = [
    'PurchaseOrders', 'AddPurchaseOrder', 'OpenPOs', 'OpenValue', 'OverdueValue',
    'SearchByReferenceSupplier', 'SomethingWentWrong', 'ExpectedDelivery',
    'DeletedSuccessfully', 'PurchaseOrder', 'SelectPoOptional', 'PoItemsAvailable',
    'LoadAllItems', 'ItemsLoaded', 'PriceVarianceReport', 'DateFrom', 'DateTo',
    'MinVariancePercent', 'TotalLines', 'NetVariance', 'OverchargedLines', 'GRN',
    'PoAgreedCost', 'GrnActualCost', 'VariancePercent', 'EditPurchaseOrder',
    'PoNotEditable', 'AlreadyReceived', 'MinQuantityNote', 'AFewWords',
    'FieldIsRequired', 'AddAtLeastOneProduct', 'UpdatedSuccessfully',
    'SavedSuccessfully', 'PoStatusAutoNote',
];
foreach ($filesToCheck as $file) {
    $content = $source($file);
    foreach ($confirmedMissingKeys as $key) {
        $notContains(
            $content,
            "t('{$key}') ||",
            "{$file} must not use t('{$key}') || 'fallback' — that key is confirmed absent from the translations table, and vue-i18n returns the raw key text (not empty), so the || fallback never triggers. Use plain text instead."
        );
    }
}

if ($errors !== []) {
    fwrite(STDERR, "PO view/labels gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "PO View Page + Human-Readable Labels gate: PASS.\n";
