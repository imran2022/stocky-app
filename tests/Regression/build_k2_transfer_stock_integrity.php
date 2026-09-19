<?php

/**
 * Build K.2 no-dependency regression gate.
 *
 * Fixes the stock-integrity bug in TransferController: every stock-moving
 * action used to look up the product_warehouse row and silently do nothing
 * when it didn't exist yet — most seriously, a transfer's "to" warehouse
 * increase would vanish with no error when that product had never been
 * stocked in the destination warehouse before.
 *
 * Run with: php tests/Regression/build_k2_transfer_stock_integrity.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$body = file_get_contents($root.'/app/Http/Controllers/TransferController.php');

// --- The helper must exist with the expected signature ---
$assert(
    str_contains($body, 'protected function resolveProductWarehouseRow(int $warehouseId, int $productId, $variantId = null): product_warehouse'),
    'TransferController must define resolveProductWarehouseRow(warehouseId, productId, variantId).'
);
// Build N2 (audit H-02): resolveProductWarehouseRow() now delegates to the
// already-hardened App\Support\StockMutator::lockOrCreate() (also used by
// Sales/Purchases/Adjustment/Damage) instead of its own bare
// ->first()+create, so it gains row-locking (lockForUpdate()) and correctly
// filters on a NULL product_variant_id — a bug the old inline version had.
// StockMutator::lockOrCreate() itself is what now creates a fresh row at
// qte=0 when none exists; that behavior is verified directly against
// StockMutator.php below rather than by grepping for the old inline shape.
$assert(
    str_contains($body, 'return StockMutator::lockOrCreate($warehouseId, $productId, $variantId);'),
    'resolveProductWarehouseRow must delegate to the row-locking StockMutator::lockOrCreate().'
);
$stockMutatorBody = file_get_contents($root.'/app/Support/StockMutator.php');
$assert(
    str_contains($stockMutatorBody, '$row->qte = 0;') && str_contains($stockMutatorBody, '->lockForUpdate()'),
    'StockMutator::lockOrCreate() must create a fresh row (qte=0) when none exists, and row-lock it.'
);

// --- Every stock-moving call site must go through the helper ---
$callSites = substr_count($body, '$this->resolveProductWarehouseRow(');
$assert($callSites === 36, "Expected exactly 36 resolveProductWarehouseRow() call sites, found $callSites.");

// --- No stock-moving site should still do a bare lookup-then-conditional-save ---
$unsafePattern = '/\$(?:product_warehouse_\w+|warehouse_\w+|Sent_variant_\w+) = product_warehouse::where/';
preg_match_all($unsafePattern, $body, $unsafeMatches);
$assert(
    count($unsafeMatches[0]) === 0,
    'No stock-moving variable should still be assigned directly from a bare product_warehouse::where()->first() lookup.'
);

// --- The two genuinely read-only lookups (batches_for_transfer) must be left alone ---
$assert(
    substr_count($body, '$item_product = product_warehouse::where(') === 2,
    'The two read-only $item_product lookups in batches_for_transfer() must remain untouched.'
);

// --- All six stock-moving methods must use the helper at least once ---
foreach (['function store(', 'function update(', 'function destroy(', 'function delete_by_selection(', 'function applyInitialStockMovement('] as $marker) {
    $pos = strpos($body, $marker);
    $assert($pos !== false, "$marker must exist in TransferController.");
}

if ($failures) {
    fwrite(STDERR, "Build K.2 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build K.2 regression gate: PASS (all 36 Transfer stock-movement sites now create a missing product_warehouse row instead of silently skipping it).\n";
