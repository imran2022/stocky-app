<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Grants the 'activity_log_report' permission (created by the
 * add_activity_log_report_permission migration) to whichever role(s) hold
 * 'report_device_management' — same rule, same reasoning as that
 * migration's own grant logic.
 *
 * Why this exists as BOTH a migration step AND a seeder: exact same
 * reasoning as PurchaseOrdersPermissionSeeder (see that file's own
 * docblock for the full explanation). On an existing, already-running
 * site, `php artisan migrate` runs alone and roles/permissions already
 * exist, so the migration's own grant logic works by itself. But on a
 * FRESH install (`php artisan migrate:fresh --seed`), migrations run
 * BEFORE any seeder — so at migration time `permission_role` is
 * completely empty and the migration's grant logic silently finds zero
 * roles to grant to. This was already found and fixed once for
 * 'purchase_orders'; the Activity Log migration was added later and
 * missed the same fix, so on a fresh install the Activity Log Report
 * menu item was invisible to every role, including Owner, exactly like
 * the earlier Purchase Orders bug. This seeder re-runs the identical
 * grant AFTER PermissionRoleSeeder has populated `permission_role` with
 * 'report_device_management' links, so the fresh-install path ends up in
 * the same state the already-running-site path was always in.
 *
 * Idempotent: safe to run multiple times (checks for an existing link
 * before inserting), so re-running `db:seed` doesn't create duplicates.
 */
class ActivityLogPermissionSeeder extends Seeder
{
    public function run()
    {
        $permissionId = DB::table('permissions')->where('name', 'activity_log_report')->value('id');
        if (! $permissionId) {
            // The migration didn't run yet, or the permission was removed —
            // nothing to grant. Not an error condition for a seeder to hit.
            return;
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
}
