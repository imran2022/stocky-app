<?php

/**
 * Build K.4 no-dependency regression gate.
 *
 * Fixes two bugs found from a live fresh-install report
 * (`php artisan migrate:fresh --seed`):
 *
 *   1. The Activity Log Report menu item was invisible to every role,
 *      including Owner, on a fresh install — the exact same bug class
 *      already fixed once for 'purchase_orders' via
 *      PurchaseOrdersPermissionSeeder, but the later Activity Log
 *      migration never got the equivalent fix.
 *   2. Neither 'purchase_orders' nor 'activity_log_report' were
 *      selectable anywhere in the Roles & Permissions UI (new-role /
 *      edit-role screen), on ANY install (fresh or not) — because
 *      resources/src/config/permissions.js is a static, hand-frozen
 *      permission catalogue, not a live read of the `permissions` table,
 *      and was never updated when these two permissions were added.
 *
 * Run with: php tests/Regression/build_k4_activity_log_permission_and_config_gap.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

// --- New seeder must exist and mirror the proven PurchaseOrdersPermissionSeeder pattern ---
$seederPath = 'database/seeders/ActivityLogPermissionSeeder.php';
$assert(file_exists($root.'/'.$seederPath), 'ActivityLogPermissionSeeder.php must exist.');
if (file_exists($root.'/'.$seederPath)) {
    $seeder = $read($seederPath);
    $assert(str_contains($seeder, "class ActivityLogPermissionSeeder extends Seeder"), 'Seeder class must be named correctly.');
    $assert(str_contains($seeder, "where('name', 'activity_log_report')"), 'Seeder must look up the activity_log_report permission by name.');
    $assert(str_contains($seeder, "where('permissions.name', 'report_device_management')"), 'Seeder must grant based on roles holding report_device_management, same rule as the migration.');
    $assert(str_contains($seeder, 'if (! $exists) {'), 'Seeder must be idempotent (check before insert).');
}

// --- DatabaseSeeder.php must call it, positioned after PermissionRoleSeeder ---
$dbSeeder = $read('database/seeders/DatabaseSeeder.php');
$assert(str_contains($dbSeeder, 'ActivityLogPermissionSeeder::class'), 'DatabaseSeeder must call ActivityLogPermissionSeeder.');
$permRolePos = strpos($dbSeeder, 'PermissionRoleSeeder::class');
$activityLogPos = strpos($dbSeeder, 'ActivityLogPermissionSeeder::class');
$assert(
    $permRolePos !== false && $activityLogPos !== false && $activityLogPos > $permRolePos,
    'ActivityLogPermissionSeeder must run AFTER PermissionRoleSeeder in the seeder call list, or the fresh-install bug is not actually fixed.'
);

// --- config/permissions.js: both custom permissions must now be selectable ---
$permsJs = $read('resources/src/config/permissions.js');
$assert(
    preg_match('/"v"\s*:\s*"activity_log_report"/', $permsJs) === 1,
    'activity_log_report must be a selectable entry in config/permissions.js.'
);
$assert(
    preg_match('/"v"\s*:\s*"purchase_orders"/', $permsJs) === 1,
    'purchase_orders must be a selectable entry in config/permissions.js.'
);

// --- Every canonical DB permission name (master seeder + the two migrations)
// must now have a matching entry in config/permissions.js, except the two
// pre-existing, deliberately-excluded ones (record_view: a per-user field on
// UserForm.vue, not a role permission; module_settings: a pre-existing base
// vendor permission unrelated to this fix) ---
$permsSeeder = $read('database/seeders/PermissionsSeeder.php');
preg_match_all("/'name'\s*=>\s*'([^']+)'/", $permsSeeder, $nameMatches);
$dbNames = $nameMatches[1];
$dbNames[] = 'purchase_orders';
$dbNames[] = 'activity_log_report';

preg_match_all('/"v"\s*:\s*"([^"]+)"/', $permsJs, $frontendMatches);
$frontendNames = array_flip($frontendMatches[1]);

$knownPreexistingGap = ['record_view', 'module_settings'];
$stillMissing = [];
foreach (array_unique($dbNames) as $name) {
    if (in_array($name, $knownPreexistingGap, true)) {
        continue;
    }
    if (! isset($frontendNames[$name])) {
        $stillMissing[] = $name;
    }
}
$assert(
    count($stillMissing) === 0,
    'Every DB permission (except the two known pre-existing exceptions) must be selectable in config/permissions.js. Still missing: '.implode(', ', $stillMissing)
);

if ($failures) {
    fwrite(STDERR, "Build K.4 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build K.4 regression gate: PASS (Activity Log Report now grants correctly on a fresh install, and both custom permissions are selectable in Roles & Permissions).\n";
