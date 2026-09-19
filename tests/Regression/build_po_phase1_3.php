<?php

/** PO/GRN Phase 1.3: real view, human copy, columns/documents, safe deletion. */

$root = dirname(__DIR__, 2);
$errors = [];
$source = function (string $relative) use ($root, &$errors): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        $errors[] = "Missing file: {$relative}";
        return '';
    }
    return file_get_contents($path);
};
$contains = function (string $text, string $needle, string $message) use (&$errors): void {
    if (! str_contains($text, $needle)) $errors[] = $message;
};
$excludes = function (string $text, string $needle, string $message) use (&$errors): void {
    if (str_contains($text, $needle)) $errors[] = $message;
};

$list = $source('resources/src/pages/purchase_orders/PurchaseOrders.vue');
$contains($list, '?mode=view', 'Reference/View must open the read-only PO view state.');
$contains($list, "['draft', 'ordered'].includes(record.status)", 'Edit must only appear for editable PO statuses.');
foreach (['View Purchase Order', 'Edit Purchase Order', 'Receive Items', 'Download PDF', 'Email to Supplier', 'Attachments'] as $label) {
    $contains($list, $label, "Missing human action label: {$label}");
}
$contains($list, "dataIndex: 'created_by_name'", 'Created By column is missing.');
$contains($list, "dataIndex: 'last_grn_date'", 'Last GRN Date column is missing.');
$contains($list, "key: 'has_documents'", 'Attachment indicator column is missing.');
$contains($list, 'async function uploadPoDocuments()', 'Attachment upload UI is missing.');

$form = $source('resources/src/pages/purchase_orders/PurchaseOrderForm.vue');
$contains($form, "route.query.mode === 'view'", 'PO form/view component must recognize explicit view mode.');
$contains($form, "!po.value.is_editable", 'Non-editable/received POs must automatically use the details view.');
$contains($form, 'Purchase Order Details', 'Read-only PO details title is missing.');
$contains($form, 'Ordered Quantity', 'Read-only item quantities are missing.');
$contains($form, 'Received Quantity', 'Read-only received quantities are missing.');
$contains($form, 'Remaining Quantity', 'Read-only remaining quantities are missing.');
$excludes($form, 'PoStatusAutoNote', 'Raw PoStatusAutoNote must be removed.');
$excludes($form, 'PoNotEditable', 'Raw PoNotEditable must be removed.');

$grnForm = $source('resources/src/pages/purchases/PurchaseForm.vue');
$contains($grnForm, 'Load Remaining Items', 'GRN action must use human wording.');
$contains($grnForm, 'item(s) remaining from the selected Purchase Order.', 'GRN availability message must use human wording.');
$excludes($grnForm, 'PoItemsAvailable', 'Raw PoItemsAvailable must be removed.');
$excludes($grnForm, 'LoadAllItems', 'Raw LoadAllItems must be removed.');

$controller = $source('app/Http/Controllers/PurchaseOrderController.php');
foreach (['created_by_name', 'last_grn_date', 'has_documents'] as $field) {
    $contains($controller, "'{$field}' =>", "PO list API field {$field} is missing.");
}
$contains($controller, "->where('statut', 'received')", 'Last GRN Date must only use finalized received GRNs.');

$safety = $source('app/Services/Custom/GrnDeletionSafetyService.php');
$contains($safety, "\$requirements[\$key]['quantity'] += \$baseQty", 'Deletion safety must aggregate duplicate/bulk contributions.');
$contains($safety, 'ksort($requirements, SORT_STRING)', 'Stock rows need a stable lock order.');
$contains($safety, '->lockForUpdate()->first()', 'Stock safety check must lock rows through deletion commit.');
$contains($safety, 'throw new HttpResponseException', 'Unsafe deletion must abort and roll back with HTTP 422.');

$purchases = $source('app/Http/Controllers/PurchasesController.php');
$contains($purchases, 'assertSafeToDelete(collect([$current_Purchase]))', 'Single GRN deletion safety call is missing.');
$contains($purchases, 'assertSafeToDelete($preflightPurchases)', 'Bulk GRN cumulative safety call is missing.');
$bulkSafety = strpos($purchases, 'assertSafeToDelete($preflightPurchases)');
$bulkMutation = strpos($purchases, 'foreach ($selectedIds as $purchase_id)', $bulkSafety ?: 0);
if ($bulkSafety === false || $bulkMutation === false || $bulkSafety > $bulkMutation) {
    $errors[] = 'Bulk safety must finish before the first selected GRN is mutated.';
}

$manifest = json_decode($source('public/js/.vite/manifest.json'), true);
foreach ([
    'resources/src/pages/purchase_orders/PurchaseOrders.vue' => ['View Purchase Order', 'Attachments'],
    'resources/src/pages/purchase_orders/PurchaseOrderForm.vue' => ['Purchase Order Details', 'Remaining Quantity'],
    'resources/src/pages/purchases/PurchaseForm.vue' => ['Load Remaining Items'],
] as $entry => $needles) {
    $asset = $source('public/js/'.$manifest[$entry]['file']);
    foreach ($needles as $needle) $contains($asset, $needle, "Active asset {$entry} is missing {$needle}.");
    foreach (['PoStatusAutoNote', 'PoNotEditable', 'PoItemsAvailable', 'LoadAllItems'] as $raw) {
        $excludes($asset, $raw, "Active asset {$entry} still leaks raw key {$raw}.");
    }
}

if ($errors) {
    fwrite(STDERR, "PO/GRN Phase 1.3 gate FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, " - {$error}\n");
    exit(1);
}

echo "PO/GRN Phase 1.3 gate: PASS (read-only view, human copy, documents/columns, and transactional aggregate GRN deletion safety).\n";
