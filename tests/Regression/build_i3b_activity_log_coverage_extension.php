<?php

/**
 * Build I3b — Activity Log coverage extension (2026-09-19 deep audit
 * follow-up). Before this build, only Sale, Purchase, Product, Adjustment,
 * Transfer, User, Customer and Role/Permission changes were logged. This
 * adds: Sale Return, Purchase Return, Damage, Quotation, Purchase Order,
 * Warehouse, Shipment, System Settings, and all four Payment types
 * (Sale/Purchase/Sale Return/Purchase Return).
 *
 * This is a static source-contract check (see
 * docs/CUSTOMIZATION_CHECKLIST.md section 15 for what that does and
 * doesn't catch); it was additionally verified once against a real seeded
 * database by creating one instance of every new model and confirming
 * each produced an activity_logs row with the correct module/description,
 * confirming the Warehouse/Settings/Payment explicit (bulk-update) call
 * sites fire correctly, and confirming Settings' backup credentials never
 * appear in a logged new_values payload.
 *
 * Run with: php tests/Regression/build_i3b_activity_log_coverage_extension.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$provider = $read('app/Providers/ActivityLogServiceProvider.php');
foreach ([
    'SaleReturn' => "'Sale Return'",
    'PurchaseReturn' => "'Purchase Return'",
    'Damage' => "'Damage'",
    'Quotation' => "'Quotation'",
    'PurchaseOrder' => "'Purchase Order'",
    'Warehouse' => "'Warehouse'",
    'PaymentSale' => "'Payment (Sale)'",
    'PaymentPurchase' => "'Payment (Purchase)'",
    'PaymentSaleReturns' => "'Payment (Sale Return)'",
    'PaymentPurchaseReturns' => "'Payment (Purchase Return)'",
    'Shipment' => "'Shipment'",
] as $model => $moduleLabel) {
    $assert(
        str_contains($provider, "\\App\\Models\\{$model}::class") && str_contains($provider, $moduleLabel),
        "ActivityLogServiceProvider must hook {$model} under module {$moduleLabel}."
    );
}
$assert(
    str_contains($provider, 'Shipment::deleted(function'),
    'Shipment needs its own explicit deleted() listener (it uses a real Eloquent delete, not soft-delete-via-update).'
);

$logger = $read('app/Services/Custom/ActivityLogger.php');
foreach ([
    'backup_s3_access_key', 'backup_s3_secret_key',
    'backup_gdrive_access_token', 'backup_gdrive_refresh_token', 'backup_gdrive_client_secret',
    'backup_dropbox_access_token',
    'google_calendar_client_secret', 'google_calendar_refresh_token',
] as $secretKey) {
    $assert(
        str_contains($logger, "'{$secretKey}'"),
        "ActivityLogger must never persist the {$secretKey} credential in old/new values."
    );
}

$settingsController = $read('app/Http/Controllers/SettingsController.php');
$assert(
    str_contains($settingsController, "'Settings',") && str_contains($settingsController, 'ActivityLogger::log('),
    'SettingsController::update() must log Settings changes explicitly (bulk update, not observable).'
);

$warehouseController = $read('app/Http/Controllers/WarehouseController.php');
$assert(
    substr_count($warehouseController, 'ActivityLogger::log(') >= 3,
    'WarehouseController must explicitly log update + destroy + delete_by_selection (bulk updates, not observable).'
);

foreach ([
    'PaymentSalesController.php' => 'Payment (Sale)',
    'PaymentPurchasesController.php' => 'Payment (Purchase)',
    'PaymentSaleReturnsController.php' => 'Payment (Sale Return)',
    'PaymentPurchaseReturnsController.php' => 'Payment (Purchase Return)',
] as $file => $label) {
    $content = $read('app/Http/Controllers/'.$file);
    $assert(
        str_contains($content, "ActivityLogger::log(") && str_contains($content, "'{$label}'"),
        "{$file} must explicitly log its deleted() bulk-update call site under module '{$label}'."
    );
}

$reportVue = $read('resources/src/pages/reports/ActivityLogReport.vue');
foreach (['Sale Return', 'Purchase Return', 'Damage', 'Quotation', 'Purchase Order', 'Warehouse', 'Shipment', 'Settings'] as $moduleValue) {
    $assert(
        str_contains($reportVue, "value: '{$moduleValue}'"),
        "ActivityLogReport.vue's module filter must include '{$moduleValue}'."
    );
}

if ($failures) {
    fwrite(STDERR, "Build I3b regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build I3b regression gate: PASS (audit trail now covers Sale/Purchase Returns, Damage, Quotation, Purchase Order, Warehouse, Shipment, Settings, and all Payment types).\n";
