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
 *
 * Explicit ID (not auto-increment): on a FRESH install, migrations run
 * BEFORE seeders, so an empty `permissions` table would hand this row
 * auto-increment ID 1 — directly colliding with PermissionsSeeder's own
 * hardcoded id=1 ('users_view') moments later and aborting the seed with a
 * duplicate-key error. Reserving a high, explicit ID clear of the master
 * seeder's own range (currently up to ~307) avoids this regardless of
 * whether this migration runs before or after that seeder.
 */
return new class extends Migration
{
    // A class constant (not a global `const`) — a top-level const in a
    // migration file can throw "already defined" if Laravel's migrator
    // loads this file more than once in the same process (observed during
    // `migrate:fresh`); a class-scoped constant has no such risk.
    private const RESERVED_PERMISSION_ID = 900001;

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'purchase_orders')->value('id');

        if (! $permissionId) {
            $exists = DB::table('permissions')->where('id', self::RESERVED_PERMISSION_ID)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'id' => self::RESERVED_PERMISSION_ID,
                    'name' => 'purchase_orders',
                    'label' => 'Purchase Orders',
                    'description' => 'Create, view, edit, delete, and receive against Purchase Orders.',
                ]);
            }
            $permissionId = self::RESERVED_PERMISSION_ID;
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
