<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a single 'purchase_orders' permission covering view/create/update/
 * delete on the PO feature — the same coarse-grained, one-permission-per-
 * feature convention already used for 'shipment' (see ShipmentPolicy),
 * rather than the four-way Purchases_view/add/edit/delete split. A PO
 * workflow's create/edit/view/delete actions are used together by the same
 * staff far more often than Purchases' actions are split across roles, so
 * the simpler convention fits better here.
 *
 * Automatically granted to whichever role(s) currently hold
 * 'Purchases_view', on the reasonable assumption that staff already
 * trusted to manage GRNs should be able to manage the POs that precede
 * them. This is a starting default, not a permanent binding — an
 * administrator can freely reassign the new permission afterward from the
 * existing Roles & Permissions settings screen, exactly like any other
 * permission.
 *
 * Written as a migration (not the master PermissionsSeeder.php) so it runs
 * automatically and idempotently via `php artisan migrate` on an existing
 * production database, without needing to re-run — and risk side effects
 * from — the full seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'purchase_orders')->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'purchase_orders',
                'label' => 'Purchase Orders',
                'description' => 'Create, view, edit, delete, and receive against Purchase Orders.',
            ]);
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

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'purchase_orders')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
