<?php

/**
 * PO/GRN Phase 1.4: supplier match, locked over-receipt protection, and the
 * unsafe linked-GRN edit block.
 *
 * Static/source-contract checks only, matching the existing build_*.php
 * convention (see build_po_grn.php, build_po_phase1_3.php). This sandbox had
 * no vendor/ directory and no network access to Composer/Packagist, so a
 * real Eloquent/database run was not possible here — these checks confirm
 * the code is wired the way CUSTOMIZATIONS.md and the Phase 1.4 handoff
 * describe, NOT that the SQL/locking actually behaves correctly against a
 * live database. Run tests/Regression/PHASE1_4_MANUAL_VERIFICATION.md's
 * scenarios against the real Laragon environment before treating this as
 * verified in production.
 */

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

$service = $source('app/Services/Custom/PurchaseOrderReceiptService.php');

// ----- Supplier match -----
$contains($service, '?int $grnSupplierId = null', 'validateReceivablePo must accept the GRN supplier id.');
$contains($service, '(int) $po->provider_id !== $grnSupplierId', 'validateReceivablePo must reject a supplier mismatch.');

// ----- Locked over-receipt check exists and locks in a stable order -----
$contains($service, 'function lockAndValidateReceiptLines(PurchaseOrder $po, array $requestDetails): void', 'lockAndValidateReceiptLines() is missing.');
$contains($service, "PurchaseOrder::whereKey(\$po->id)->lockForUpdate()->first()", 'PO row must be locked before the over-receipt check.');
$contains($service, '->orderBy(\'id\')', 'PO-detail rows must be locked in a stable (ascending id) order.');
$contains($service, '->lockForUpdate()', 'PO-detail rows must be locked for update.');
$contains($service, "abort(422, 'Cannot receive this GRN because it exceeds the Purchase Order", 'Over-receipt must abort the entire GRN with HTTP 422.');
$contains($service, '$requestedByDetail[$poDetailId] = ($requestedByDetail[$poDetailId] ?? 0.0) + $baseQty', 'Duplicate/split lines against the same PO detail must be summed before comparison.');

$purchases = $source('app/Http/Controllers/PurchasesController.php');

// ----- store() calls the locked check before mutation, inside its own transaction -----
$contains($purchases, "(int) \$request->supplier_id\r\n        );", 'The fast precheck must now pass the GRN supplier id.');
$storeTxn = strpos($purchases, '\DB::transaction(function () use ($request) {');
$lockCall = strpos($purchases, 'lockAndValidateReceiptLines($po, ', $storeTxn ?: 0);
$firstMutation = strpos($purchases, '$order = new Purchase;', $storeTxn ?: 0);
if ($storeTxn === false || $lockCall === false || $firstMutation === false || $lockCall > $firstMutation) {
    $errors[] = 'The locked over-receipt check must run inside store()\'s transaction, before the GRN row is created.';
}

// ----- update() blocks the unsafe linked-GRN edit before any mutation -----
$updateFn = strpos($purchases, 'public function update(Request $request, $id)');
$guard = strpos($purchases, "\$current_Purchase->statut === 'received' || \$request->statut === 'received'", $updateFn ?: 0);
$firstDetailRead = strpos($purchases, '$old_purchase_details = PurchaseDetail::where(\'purchase_id\', $id)->get();', $updateFn ?: 0);
if ($updateFn === false || $guard === false || $firstDetailRead === false || $guard > $firstDetailRead) {
    $errors[] = 'update() must reject an unsafe linked-GRN edit before touching any existing detail/stock row.';
}
$contains($purchases, "This GRN is linked to a Purchase Order and involves a \"received\" state.", 'update() must give a human, actionable rejection message.');

if ($errors) {
    fwrite(STDERR, "PO/GRN Phase 1.4 gate FAILED:\n");
    foreach ($errors as $error) fwrite(STDERR, " - {$error}\n");
    exit(1);
}

echo "PO/GRN Phase 1.4 gate: PASS (supplier match, locked over-receipt check wired before mutation, unsafe linked-GRN edit blocked before mutation).\n";
echo "NOTE: static source checks only — see PHASE1_4_MANUAL_VERIFICATION.md for the real-database steps still required before production sign-off.\n";
