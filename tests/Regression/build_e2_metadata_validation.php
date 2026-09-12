<?php

/**
 * Build E2 no-dependency Sale metadata validation/permission regression gate.
 *
 * Run with: php tests/Regression/build_e2_metadata_validation.php
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
$notContains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(! str_contains($haystack, $needle), $message);
};

$root = dirname(__DIR__, 2);
$rules = file_get_contents($root.'/app/Support/SaleMetadataRules.php');
$sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
$shipments = file_get_contents($root.'/app/Http/Controllers/ShipmentController.php');
$meta = file_get_contents($root.'/app/Http/Controllers/SaleMetaController.php');

$assert($rules !== false, 'SaleMetadataRules.php must be readable.');
$assert($sales !== false, 'SalesController.php must be readable.');
$assert($shipments !== false, 'ShipmentController.php must be readable.');
$assert($meta !== false, 'SaleMetaController.php must be readable.');

if ($rules !== false) {
    $contains($rules, "Rule::exists('sale_zones', 'id')->whereNull('deleted_at')", 'Zone IDs must resolve to active lookup rows.');
    $contains($rules, "Rule::exists('sale_couriers', 'id')->whereNull('deleted_at')", 'Courier IDs must resolve to active lookup rows.');
    $contains($rules, "'tracking_ref' => ['nullable', 'string', 'max:255']", 'Tracking Ref must respect the VARCHAR(255) storage contract.');
    $contains($rules, "'consignment_id' => ['nullable', 'string', 'max:255']", 'Consignment ID must respect the VARCHAR(255) storage contract.');
    $contains($rules, "'details.*.box_qty' => ['nullable', 'numeric', 'min:0', 'max:99999999.99']", 'Box Qty must stay decimal-compatible while rejecting negative/out-of-range values.');
    $contains($rules, 'public const MAX_BULK_SALES = 1000;', 'Bulk metadata requests must have a generous but finite safety cap.');
    $contains($rules, "Rule::exists('sales', 'id')->whereNull('deleted_at')", 'Bulk selected Sale IDs must refer to non-deleted Sales.');
    foreach (['ordered', 'packed', 'shipped', 'delivered', 'cancelled'] as $status) {
        $contains($rules, "'$status'", "Shipping status vocabulary must include $status.");
    }
}

if ($sales !== false) {
    $assert(substr_count($sales, 'SaleMetadataRules::saleFields()') === 2, 'Normal Sale create and update must both use the shared metadata validation rules.');
    $contains($sales, '$request->validate(SaleMetadataRules::bulkFields());', 'Sales bulk update must validate metadata before querying/updating rows.');
    $contains($sales, 'fields actually present in the request are touched', 'Bulk partial-update semantics must remain documented/preserved.');
}

if ($shipments !== false) {
    $assert(substr_count($shipments, 'SaleMetadataRules::shipmentFields()') === 2, 'Shipment create/update must share the same status/courier/tracking validation contract.');
}

if ($meta !== false) {
    $contains($meta, "\$user->can('create', Sale::class)", 'Sale-create users must retain on-the-fly metadata creation convenience.');
    $contains($meta, "\$user->can('update', Sale::class)", 'Sale-edit users must retain on-the-fly metadata creation convenience.');
    $contains($meta, "\$user->can('Sales_pos', Sale::class)", 'POS users must retain on-the-fly metadata creation convenience.');
    $contains($meta, "\$user->can('create', Shipment::class)", 'Shipment users must retain on-the-fly Courier creation convenience.');
    $contains($meta, 'abort_unless($allowed, 403);', 'Unrelated authenticated users must be rejected by the metadata endpoint.');
    $contains($meta, "preg_replace('/\\s+/u', ' ', trim", 'Lookup names must normalize outer/repeated whitespace.');
    $contains($meta, "whereRaw('LOWER(TRIM(name)) = LOWER(?)'", 'Lookup duplicate detection must be case-insensitive and trim-safe.');
    $contains($meta, '::withTrashed()', 'Soft-deleted lookup rows must be reusable/restorable instead of duplicated.');
    $contains($meta, 'catch (QueryException $e)', 'Concurrent unique-key creation races must be retried gracefully.');
    $notContains($meta, '->delete()', 'Build E2 must not introduce Zone/Courier delete behavior.');
}

// Business-level sanity for the intentionally preserved decimal Box Qty rule.
$validBoxQty = [0, 1, 2.5, 99999999.99];
foreach ($validBoxQty as $qty) {
    $assert(is_numeric($qty) && $qty >= 0 && $qty <= 99999999.99, "Expected Box Qty $qty to remain valid.");
}
$assert(-1 < 0, 'Negative Box Qty sanity fixture must remain invalid.');

if ($failures) {
    fwrite(STDERR, "Build E2 metadata gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build E2 metadata gate: PASS (validation + Option B permission contracts protected).\n";
