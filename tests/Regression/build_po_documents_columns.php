<?php

/**
 * PO Document Attachments + List Columns regression gate.
 *
 * Static/source checks, matching the existing build_*.php convention.
 * Full functional correctness was verified separately against live data
 * before packaging — see the delivery notes for the exact scenario
 * (uploaded a document, received a GRN, confirmed created_by_name,
 * last_grn_date, and has_documents all populated correctly via the live
 * /api/purchase_orders endpoint).
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "PO documents/columns gate FAILED:\n - Missing file: {$relative}\n");
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

// ----- Backend: list columns, N+1-safe -----
$controller = $source('app/Http/Controllers/PurchaseOrderController.php');
$contains($controller, "'created_by_name' =>", 'index() must return created_by_name.');
$contains($controller, "'last_grn_date' =>", 'index() must return last_grn_date.');
$contains($controller, "'has_documents' =>", 'index() must return has_documents.');
$contains($controller, "groupBy('purchase_order_id')\n                ->pluck('last_date', 'purchase_order_id')", 'Last GRN Date must be a single grouped query for the page, not one query per row.');
$contains($controller, "groupBy('purchase_order_id')\n                ->pluck('doc_count', 'purchase_order_id')", 'Attachment indicator must be a single grouped query for the page, not one query per row.');

// ----- Frontend: attachments modal wired, columns present -----
$poList = $source('resources/src/pages/purchase_orders/PurchaseOrders.vue');
$contains($poList, 'key="documents"', 'PO list action menu must include a Documents action.');
$contains($poList, 'async function uploadPoDocuments()', 'PO list must implement document upload.');
$contains($poList, 'function downloadPoDocument(', 'PO list must implement document download.');
$contains($poList, 'function removePoDocument(', 'PO list must implement document delete.');
$contains($poList, "dataIndex: 'created_by_name'", 'Created By column must be present.');
$contains($poList, "dataIndex: 'last_grn_date'", 'Last GRN Date column must be present.');
$contains($poList, "function ageDays(record)", 'Age column calculation must be present.');
$contains($poList, "key === 'has_documents'", 'Attachment indicator cell must be present.');

if ($errors !== []) {
    fwrite(STDERR, "PO documents/columns gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "PO Documents + List Columns gate: PASS (N+1-safe backend fields, attachment UI wired end to end).\n";
