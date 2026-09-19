<?php

/**
 * Build I1 regression gate — Activity Log (Phase 1: Sales, Purchases,
 * Products, Customers + existing login sessions).
 *
 * Static/source checks only (no database), matching the existing
 * build_*.php convention. Does not verify actual log rows get written on a
 * live database — do that manually (create/edit/delete a Sale, Purchase,
 * Product and Customer, then check the new report) before treating this as
 * verified in production.
 */

$root = dirname(__DIR__, 2);

$source = function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        fwrite(STDERR, "Build I1 regression gate FAILED:\n - Missing file: {$relative}\n");
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

$migration = $source('database/migrations/2026_09_18_000001_create_activity_logs_table.php');
$permMigration = $source('database/migrations/2026_09_18_000002_add_activity_log_report_permission.php');
$model = $source('app/Models/ActivityLog.php');
$logger = $source('app/Services/Custom/ActivityLogger.php');
$provider = $source('app/Providers/ActivityLogServiceProvider.php');
$controller = $source('app/Http/Controllers/ActivityLogController.php');
$clientController = $source('app/Http/Controllers/ClientController.php');
$userController = $source('app/Http/Controllers/UserController.php');
$permissionsController = $source('app/Http/Controllers/PermissionsController.php');
$settingPolicy = $source('app/Policies/SettingPolicy.php');
$configApp = $source('config/app.php');
$routes = $source('routes/api.php');
$menu = $source('resources/src/config/menu.js');
$router = $source('resources/src/router/index.js');
$page = $source('resources/src/pages/reports/ActivityLogReport.vue');

// --- Schema ---
$contains($migration, "Schema::create('activity_logs'", 'Migration must create activity_logs table.');
foreach (['user_id', 'module', 'action', 'subject_type', 'subject_id', 'description', 'old_values', 'new_values', 'ip_address', 'user_agent'] as $col) {
    $contains($migration, "'{$col}'", "activity_logs must have a {$col} column.");
}
$contains($permMigration, "'activity_log_report'", 'Permission migration must add activity_log_report.');

// --- Model + service ---
$contains($model, 'class ActivityLog extends Model', 'ActivityLog model must exist.');
$contains($logger, 'class ActivityLogger', 'ActivityLogger service must exist.');
$contains($logger, 'public static function log(', 'ActivityLogger must expose log().');
$contains($logger, 'public static function diff(', 'ActivityLogger must expose diff() for old/new values.');

// --- Provider: the actual instrumentation ---
$contains($provider, 'class ActivityLogServiceProvider', 'Provider must exist.');
$contains($provider, '\App\Models\Sale::class', 'Provider must hook Sale.');
$contains($provider, '\App\Models\Purchase::class', 'Provider must hook Purchase.');
$contains($provider, '\App\Models\Product::class', 'Provider must hook Product.');
$contains($provider, "\\App\\Models\\Client::created(function", 'Provider must hook Client created.');
// Soft-delete-via-update correctness (see provider's own docblock for why
// this matters): the updated() closure must detect deleted_at specifically,
// not just log every update as 'updated'.
$contains($provider, "wasChanged('deleted_at')", 'Provider must detect soft-delete via updated(), not rely on a deleted() event that never fires in this codebase.');
$contains($configApp, 'App\Providers\ActivityLogServiceProvider::class', 'Provider must be registered in config/app.php.');

// --- Phase 2: Adjustment, Transfer, User hooks ---
$contains($provider, '\App\Models\Adjustment::class', 'Provider must hook Adjustment.');
$contains($provider, '\App\Models\Transfer::class', 'Provider must hook Transfer.');
$contains($provider, '\App\Models\User::class', 'Provider must hook User.');

// --- Security: a password must never reach this log, hashed or not ---
$contains($logger, "'password'", 'ActivityLogger must exclude password from every diff/snapshot.');
$contains($logger, 'public static function sanitize(', 'ActivityLogger must expose sanitize() for full-attribute snapshots (created rows), not just diff().');
$contains($provider, 'ActivityLogger::sanitize(', "Provider's created-row snapshots must go through sanitize(), not raw getAttributes().");
if (str_contains($provider, "'password'") === false && substr_count($provider, 'getAttributes()') > 0 && substr_count($provider, 'ActivityLogger::sanitize(') < substr_count($provider, 'getAttributes()')) {
    $errors[] = 'Every getAttributes() call in the provider must be wrapped in ActivityLogger::sanitize() — found one that is not.';
}

// --- SettingPolicy: the permission row alone is not enough in this app (hotfix #3's lesson) ---
$contains($settingPolicy, 'function activity_log_report(', 'SettingPolicy must define an activity_log_report() ability method — a permissions-table row alone does not authorize it in this app.');

// --- ClientController: the two explicit call sites (Observer can't reach these) ---
$clientLogCalls = substr_count($clientController, 'ActivityLogger::log(');
if ($clientLogCalls < 3) {
    $errors[] = "ClientController must have at least 3 explicit ActivityLogger::log() calls (update, single delete, bulk delete) — found {$clientLogCalls}.";
}

// --- UserController: profile-edit bulk update needs an explicit call too ---
$contains($userController, 'ActivityLogger::log(', 'UserController must explicitly log the profile-edit bulk update.');
$contains($userController, "'password', 'remember_token'", 'UserController\'s explicit diff must exclude password fields.');

// --- PermissionsController: Role create/update/delete, all bulk/pivot operations ---
$permissionsLogCalls = substr_count($permissionsController, 'ActivityLogger::log(');
if ($permissionsLogCalls < 4) {
    $errors[] = "PermissionsController must have at least 4 explicit ActivityLogger::log() calls (create, update, single delete, bulk delete) — found {$permissionsLogCalls}.";
}
$contains($permissionsController, "'Role'", 'PermissionsController must log under the Role module.');

// --- Report endpoint ---
$contains($controller, 'class ActivityLogController', 'Report controller must exist.');
$contains($controller, 'UserLoginSession', 'Report controller must merge in existing login sessions, not duplicate them.');
$contains($controller, "'activity_log_report'", 'Report endpoint must be permission-gated.');
$contains($routes, "'ActivityLogController@index'", 'Route must be registered.');

// --- Frontend wiring ---
$contains($menu, 'activity_log_report', 'Menu entry must reference the activity_log_report permission.');
$contains($router, 'ActivityLogReport', 'Router must register the new page.');
$contains($page, "useCrudTable('reports/activity-log'", 'Page must call the new report endpoint via useCrudTable.');
$contains($page, 'ReportPage', 'Page must use the shared ReportPage shell, not a one-off layout.');

if (! empty($errors)) {
    fwrite(STDERR, "Build I1 regression gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

echo "Build I1 regression gate passed.\n";
