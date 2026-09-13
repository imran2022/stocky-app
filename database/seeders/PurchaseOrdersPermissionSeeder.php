<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Grants the 'purchase_orders' permission (created by the
 * add_purchase_orders_permission migration) to whichever role(s) hold
 * 'Purchases_view' — same rule, same reasoning as that migration's own
 * grant logic.
 *
 * Why this exists as BOTH a migration step AND a seeder: on an existing,
 * already-running site, `php artisan migrate` is run alone (no re-seed),
 * and at that point roles/permissions/links already exist, so the
 * migration's own grant logic works correctly by itself. But on a FRESH
 * install (`php artisan migrate:fresh --seed`), migrations run BEFORE any
 * seeder — so at migration time, `permission_role` is completely empty
 * and the migration's grant logic silently finds zero roles to grant to
 * (a real bug found via a fresh-install report: the Purchase Orders menu
 * item was invisible to every role, including Owner, because no role had
 * ever actually been granted the permission on a fresh seed). This seeder
 * re-runs the identical grant AFTER PermissionRoleSeeder has populated
 * `permission_role` with 'Purchases_view' links, so the fresh-install path
 * ends up in the same state the already-running-site path was always in.
 *
 * Idempotent: safe to run multiple times (checks for an existing link
 * before inserting), so re-running `db:seed` doesn't create duplicates.
 */
class PurchaseOrdersPermissionSeeder extends Seeder
{
    public function run()
    {
        $permissionId = DB::table('permissions')->where('name', 'purchase_orders')->value('id');
        if (! $permissionId) {
            // The migration didn't run yet, or the permission was removed —
            // nothing to grant. Not an error condition for a seeder to hit.
            return;
        }

        $roleIdsWithPurchaseView = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'Purchases_view')
            ->pluck('permission_role.role_id');

        foreach ($roleIdsWithPurchaseView as $roleId) {
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
