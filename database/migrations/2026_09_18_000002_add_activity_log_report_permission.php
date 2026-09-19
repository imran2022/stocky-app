<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a single 'activity_log_report' permission for the new admin-facing
 * Activity Log report — same coarse-grained, one-permission-per-feature
 * convention used for 'purchase_orders' (see that migration's own comment
 * for the full reasoning).
 *
 * Automatically granted to whichever role(s) currently hold
 * 'report_device_management' (the permission guarding the existing,
 * self-service Login Activity Report/Login Device Management pages) — the
 * closest existing analogue, on the assumption that staff already trusted
 * to see login/session security data should also see the broader activity
 * log. A starting default, freely reassignable afterward from Roles &
 * Permissions like any other permission.
 *
 * Written as a migration (not the master PermissionsSeeder.php) so it runs
 * automatically and idempotently via `php artisan migrate` on an existing
 * production database.
 *
 * Explicit ID, clear of both the master seeder's range (~307) and the
 * 'purchase_orders' migration's reserved id (900001) — see that
 * migration's comment for why a fresh install needs an explicit id here
 * rather than auto-increment.
 */
return new class extends Migration
{
    private const RESERVED_PERMISSION_ID = 900002;

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'activity_log_report')->value('id');

        if (! $permissionId) {
            $exists = DB::table('permissions')->where('id', self::RESERVED_PERMISSION_ID)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'id' => self::RESERVED_PERMISSION_ID,
                    'name' => 'activity_log_report',
                    'label' => 'Activity Log Report',
                    'description' => 'View the admin-facing activity log (create/update/delete history across Sales, Purchases, Products, Customers, and logins).',
                ]);
            }
            $permissionId = self::RESERVED_PERMISSION_ID;
        }

        $roleIdsWithDeviceManagement = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'report_device_management')
            ->pluck('permission_role.role_id');

        foreach ($roleIdsWithDeviceManagement as $roleId) {
            $exists = DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $exists) {
                DB::table('permission_role')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'activity_log_report')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
