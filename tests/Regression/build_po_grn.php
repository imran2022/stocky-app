<?php

/**
 * Purchase Order (PO) + GRN linkage regression gate.
 *
 * Static/source checks, matching the existing build_*.php convention.
 * Full functional correctness (the actual status-transition and
 * received_quantity math) was verified separately against a live database
 * with real PurchaseOrder/Purchase rows before this feature was packaged —
 * see the delivery notes for the exact scenario (100 ordered -> 60 received
 * -> partially_received -> +40 received -> received, remaining_quantity 0)
 * and its live API responses.
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "PO+GRN regression gate FAILED:\n - Missing file: {$relative}\n");
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

// ----- Schema -----
foreach ([
    'database/migrations/2026_09_13_000001_create_purchase_orders_table.php',
    'database/migrations/2026_09_13_000002_create_purchase_order_details_table.php',
    'database/migrations/2026_09_13_000003_add_purchase_order_link_to_purchases_table.php',
    'database/migrations/2026_09_13_000004_create_purchase_order_documents_table.php',
    'database/migrations/2026_09_13_000005_add_po_prefix_to_settings_table.php',
    'database/migrations/2026_09_13_000006_add_purchase_orders_permission.php',
] as $migration) {
    $source($migration); // exits if missing
}

// ----- Models -----
$model = $source('app/Models/PurchaseOrder.php');
$contains($model, "'draft', 'ordered', 'cancelled'", 'PurchaseOrder::MANUAL_STATUSES must list exactly the three user-settable statuses.');
$contains($model, 'function receipts()', 'PurchaseOrder must expose its GRN receipts relation.');

$detailModel = $source('app/Models/PurchaseOrderDetail.php');
$contains($detailModel, 'getRemainingQuantityAttribute', 'PurchaseOrderDetail must expose remaining_quantity.');
$contains($detailModel, 'getIsFullyReceivedAttribute', 'PurchaseOrderDetail must expose is_fully_received.');

// ----- Receipt service: the core status/quantity logic -----
$service = $source('app/Services/Custom/PurchaseOrderReceiptService.php');
$contains($service, "\$grn->statut !== 'received'", 'A GRN not saved as received must not affect PO received_quantity (mirrors stock-deduction rule).');
$contains($service, 'increment(\'received_quantity\', $baseQty)', 'Receiving must increment received_quantity in base units.');
$contains($service, "\$unit->operator === '/'", 'Base-unit conversion must use the same operator convention as PosController stock deduction.');
$contains($service, 'function refreshStatus(PurchaseOrder $po)', 'Service must expose the status-recompute method.');
$contains($service, "\$po->status = 'received';", 'All lines fully received must set status to received.');
$contains($service, "\$po->status = 'partially_received';", 'Any line partially received must set status to partially_received.');

// ----- Deep-review fix: reverting a deleted GRN's contribution back out -----
$contains($service, 'function revertReceipt(Purchase $grn)', 'Service must expose a receipt-reversal method for GRN deletion.');
$contains($service, "in_array(\$po->status, ['draft', 'cancelled'], true)", 'refreshStatus() must treat draft/cancelled as sticky manual states even when quantities change (e.g. after a revert).');
$contains($service, "\$po->status = 'ordered';", 'refreshStatus() must be able to move status back down to ordered when every receipt against a PO has been reverted (bidirectional, not one-way).');
$contains($service, 'max(0.0,', 'revertReceipt() must never let received_quantity go negative.');

// ----- Controller: authorization, scope, sort safety, edit lock -----
$controller = $source('app/Http/Controllers/PurchaseOrderController.php');
$contains($controller, "PurchaseOrder::class);\n\n        \$user = Auth::user();\n        \$viewRecords = \$user->hasRecordView();", 'index() must apply record_view scope.');
$contains($controller, '$allowedSortColumns', 'index() sort must be whitelisted (not raw SortField), matching the Products/Shipments list fixes.');
$contains($controller, "! in_array(\$po->status, PurchaseOrder::MANUAL_STATUSES, true) || \$po->status === 'cancelled'", 'update() must block edits once a PO has moved past draft/ordered or is cancelled.');
$contains($controller, '$po->receipts()->whereNull(\'deleted_at\')->exists()', 'destroy() must refuse to delete a PO that already has GRN receipts.');
$contains($controller, 'function abortIfWarehouseDenied', 'Controller must enforce warehouse scoping on single-record actions.');
$contains($controller, "! \$d->is_fully_received", 'linesForGrn() must omit lines that are already fully received.');

// ----- Deep-review fixes: warehouse authorization on every single-record
// action, not just the ones checked during initial development -----
$contains($controller, "\$this->abortIfWarehouseDenied((int) \$request->warehouse_id);", "store()/update() must validate the submitted warehouse_id against the user's allowed set, not just that it's a real warehouse.");
$storeMatches = substr_count($controller, "\$this->abortIfWarehouseDenied((int) \$request->warehouse_id);");
if ($storeMatches < 2) {
    $errors[] = 'Both store() and update() must independently re-check warehouse_id (2 occurrences expected, found '.$storeMatches.').';
}
$pdfStart = strpos($controller, 'function pdf(');
$pdfSection = $pdfStart !== false ? substr($controller, $pdfStart, 400) : '';
$contains($pdfSection, 'abortIfWarehouseDenied', 'pdf() must enforce warehouse scoping.');
$emailStart = strpos($controller, 'function sendEmail(');
$emailSection = $emailStart !== false ? substr($controller, $emailStart, 400) : '';
$contains($emailSection, 'abortIfWarehouseDenied', 'sendEmail() must enforce warehouse scoping.');
$docCallCount = substr_count($controller, 'abortIfWarehouseDenied');
if ($docCallCount < 8) {
    // update, destroy, show, linesForGrn, store, update(2nd), pdf,
    // sendEmail, getDocuments, uploadDocuments, downloadDocument,
    // deleteDocument — at least 8 as a floor, not an exact contract, so
    // this doesn't need editing every time a call is refactored.
    $errors[] = "Expected at least 8 abortIfWarehouseDenied call sites across the controller's single-record actions; found {$docCallCount}. A newly added action may be missing this check.";
}

// ----- PurchasesController hook: minimal footprint, no PO logic inlined -----
$purchasesController = $source('app/Http/Controllers/PurchasesController.php');
$contains($purchasesController, 'use App\Services\Custom\PurchaseOrderReceiptService;', 'PurchasesController must import the isolated service, not reimplement PO logic inline.');
$contains($purchasesController, 'app(PurchaseOrderReceiptService::class)->applyReceipt(', 'store() must call the receipt service exactly once.');
$contains($purchasesController, "\$order->purchase_order_id = \$request->purchase_order_id ?: null;", 'store() must accept an optional purchase_order_id, defaulting to null for the original direct-purchase flow.');

// ----- Deep-review fix: deleting a PO-linked GRN must reverse its
// contribution, in BOTH delete paths, and BEFORE the hard-delete of its
// details (PurchaseDetail has no SoftDeletes — calling revertReceipt()
// after details()->delete() would find nothing to revert). -----
$revertCallCount = substr_count($purchasesController, 'PurchaseOrderReceiptService::class)->revertReceipt(');
if ($revertCallCount < 2) {
    $errors[] = "revertReceipt() must be called from both destroy() and delete_by_selection() (found {$revertCallCount} call site(s)).";
}
// Order check: for each revertReceipt() call, the nearest subsequent
// "details()->delete()" must come AFTER it, not before.
$offset = 0;
while (($revertPos = strpos($purchasesController, 'revertReceipt($current_Purchase)', $offset)) !== false) {
    $deletePos = strpos($purchasesController, 'details()->delete()', $revertPos);
    if ($deletePos === false || $deletePos < $revertPos) {
        $errors[] = 'revertReceipt() must be called before details()->delete() in every delete path (PurchaseDetail is hard-deleted, not soft-deleted).';
        break;
    }
    $offset = $revertPos + 1;
}

// ----- Routes -----
$routes = $source('routes/api.php');
foreach ([
    "Route::resource('purchase_orders', 'PurchaseOrderController');",
    "Route::get('purchase_orders/by_provider/{providerId}', 'PurchaseOrderController@openForProvider');",
    "Route::get('purchase_orders/{id}/lines_for_grn', 'PurchaseOrderController@linesForGrn');",
    "Route::get('purchase_orders/{id}/documents', 'PurchaseOrderController@getDocuments');",
    "Route::post('purchase_orders/{id}/documents', 'PurchaseOrderController@uploadDocuments');",
    "Route::get('purchase_orders/documents/{id}/download', 'PurchaseOrderController@downloadDocument');",
    "Route::delete('purchase_orders/documents/{id}', 'PurchaseOrderController@deleteDocument');",
    "Route::get('purchase_orders/{id}/pdf', 'PurchaseOrderController@pdf');",
    "Route::post('purchase_orders/{id}/send_email', 'PurchaseOrderController@sendEmail');",
] as $routeLine) {
    $contains($routes, $routeLine, "Missing route: {$routeLine}");
}

// ----- Frontend: PO pages + GRN-form integration exist and are wired -----
$poList = $source('resources/src/pages/purchase_orders/PurchaseOrders.vue');
$contains($poList, "router.push(`/purchases/create?po_id=\${record.id}`)", 'PO list\'s "Receive" action must hand off to the GRN form with po_id.');
$contains($poList, 'title="Purchase Orders"', 'PO list heading must use the approved human-readable "Purchase Orders" label.');
$contains($poList, 'Add Purchase Order', 'PO list action must use the approved "Add Purchase Order" label.');
$contains($poList, "{ title: 'Expected Delivery Date'", 'PO list must label the date column "Expected Delivery Date".');
$contains($poList, 'onError: (error) =>', 'PO list must surface an actionable API failure instead of the shared generic toast.');
$contains($poList, 'Confirm the PO migrations are applied.', 'HTTP 500 list failures must point the operator to the PO migration check.');
$contains($poList, 'e?.data?.message', 'PO actions must read errors from the custom fetch wrapper\'s data property.');
$contains($poList, 'params: () => filterParams.value', 'PO list must pass a callable params provider; a computed ref makes useCrudTable throw before the API request.');

$poForm = $source('resources/src/pages/purchase_orders/PurchaseOrderForm.vue');
$contains($poForm, "record.received_quantity || 0", 'PO edit form must not allow shrinking a line below its already-received quantity.');
$contains($poForm, "viewOnly.value ? 'Purchase Order Details' : (isEdit.value ? 'Edit Purchase Order' : 'Add Purchase Order')", 'PO form title must expose a distinct human-readable read-only title.');
$contains($poForm, 'label="Expected Delivery Date"', 'PO form must use the approved expected-delivery label.');

$grnForm = $source('resources/src/pages/purchases/PurchaseForm.vue');
$contains($grnForm, 'const selectedPoId = ref(', 'GRN form must have PO-selection state.');
$contains($grnForm, 'async function loadAllPoItems()', 'GRN form must support auto-loading a PO\'s remaining lines.');
$contains($grnForm, 'purchase_order_id: !isEdit.value ? (selectedPoId.value || null) : undefined', 'GRN form must only send purchase_order_id on create, matching the documented create-only scope of this feature.');
$contains($grnForm, 'purchase_order_detail_id: l.purchase_order_detail_id ?? null', 'GRN form must send each line\'s PO-detail linkage.');
$contains($grnForm, '<a-form-item label="Select Purchase Order">', 'GRN form must label the optional PO selector "Select Purchase Order".');
$contains($grnForm, '<a-col v-if="!isEdit" :xs="24" :md="6">', 'GRN form PO selector must occupy one quarter of the approved four-field desktop row.');

// The active production assets are shipped in this overlay because the
// deployment server does not need npm/Vite. Keep source and runtime aligned.
$manifest = json_decode($source('public/js/.vite/manifest.json'), true);
$poListAsset = $source('public/js/'.$manifest['resources/src/pages/purchase_orders/PurchaseOrders.vue']['file']);
$contains($poListAsset, 'Confirm the PO migrations are applied.', 'Active PO list asset must contain the actionable HTTP 500 diagnostic.');
$purchaseFormAsset = $source('public/js/'.$manifest['resources/src/pages/purchases/PurchaseForm.vue']['file']);
$contains($purchaseFormAsset, 'Select Purchase Order', 'Active GRN form asset must contain the approved PO-selector label.');

// ----- Router + menu -----
$router = $source('resources/src/router/index.js');
$contains($router, "const PurchaseOrders = lazy('PurchaseOrders'", 'Router must lazy-load the PO list page.');
$contains($router, "const PurchaseOrderForm = lazy('PurchaseOrderForm'", 'Router must lazy-load the PO form page.');
$contains($router, "path: 'purchase-orders'", 'Router must register the PO list route.');
$contains($router, "path: 'purchase-orders/create'", 'Router must register the PO create route.');
$contains($router, "path: 'purchase-orders/:id(\\\\d+)'", 'Router must register the PO edit/view route.');

$menu = $source('resources/src/config/menu.js');
$contains($menu, "'/app/purchase_orders/store': '/purchase-orders/create'", 'Legacy menu alias must resolve the PO create route.');
$contains($menu, "'/app/purchase_orders/list': '/purchase-orders'", 'Legacy menu alias must resolve the PO list route.');
$contains($menu, "permissions: ['purchase_orders']", 'Sidebar menu must gate the PO item behind the purchase_orders permission.');

$contains($routes, "'PurchaseOrders' => 'Purchase Orders'", 'English translation API fallback must map PurchaseOrders to Purchase Orders.');

if ($errors !== []) {
    fwrite(STDERR, "PO+GRN regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "PO + GRN Linkage gate: PASS (schema, isolated receipt service, controller scope/locks, minimal PurchasesController footprint, and frontend wiring all protected).\n";
