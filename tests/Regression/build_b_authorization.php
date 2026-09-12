<?php

/**
 * Build B no-dependency authorization/integrity regression gate.
 *
 * Run with: php tests/Regression/build_b_authorization.php
 * This is a fast source-contract guard; database-backed Laravel feature tests
 * remain the stronger runtime layer when Composer/PHP extensions are present.
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
$sales = file_get_contents($root.'/app/Http/Controllers/SalesController.php');
$shipments = file_get_contents($root.'/app/Http/Controllers/ShipmentController.php');

// Bulk Sale action: browser-selected ids must be narrowed by the same record
// ownership and warehouse visibility rules as the Sales list.
$contains($sales, "\$salesToUpdate = Sale::whereIn('id', \$ids)->whereNull('deleted_at');", 'Bulk update must start from a scoped query, not a direct update.');
$contains($sales, 'if (! $user->hasRecordView())', 'Bulk update must honor record_view ownership.');
$contains($sales, "\$salesToUpdate->where('user_id', \$user->id);", 'Bulk update must restrict owner when record_view is disabled.');
$contains($sales, "UserWarehouse::where('user_id', \$user->id)", 'Bulk update must resolve assigned warehouses.');
$contains($sales, "\$salesToUpdate->whereIn('warehouse_id', \$allowedWarehouseIds);", 'Bulk update must restrict assigned warehouses.');
$notContains($sales, "Sale::whereIn('id', \$ids)->whereNull('deleted_at')->update(\$payload)", 'Direct unscoped bulk update must not return.');

// Shipment access: every route is scoped through its parent Sale, and update
// cannot reassign a shipment to a different Sale.
$contains($shipments, 'private function visibleSalesQuery($user)', 'Shipment controller must define canonical visible Sale scope.');
$contains($shipments, 'private function visibleShipmentsQuery($user)', 'Shipment controller must scope Shipments through visible Sales.');
$contains($shipments, 'if (! $user->hasRecordView())', 'Shipment Sale scope must honor record_view ownership.');
$contains($shipments, "->whereIn('warehouse_id', \$allowedWarehouseIds);", 'Shipment Sale scope must honor warehouse assignments.');
$contains($shipments, "\$this->authorizeForUser(\$request->user('api'), 'view', Shipment::class);", 'Shipment show must require Shipment permission.');
$contains($shipments, "\$this->visibleSalesQuery(\$user)->with('client')->findOrFail(\$id);", 'Shipment show must resolve the requested Sale inside visible scope.');
$contains($shipments, 'Shipment reference already belongs to another sale.', 'Shipment store must block cross-Sale Ref rebinding.');
$contains($shipments, 'Shipment cannot be reassigned to another sale.', 'Shipment update must reject Sale reassignment.');
$contains($shipments, "\$shipment->update(\$request->only([\n                'delivered_to', 'phone_number', 'shipping_address', 'status', 'shipping_details',", 'Shipment update must not mass-update sale_id.');
$contains($shipments, '$status_counts = $this->visibleShipmentsQuery($user)', 'Shipment KPI counts must use the same visibility scope as the list.');

if ($failures) {
    fwrite(STDERR, "Build B authorization gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build B authorization gate: PASS (bulk Sale + Shipment access contracts protected).\n";
