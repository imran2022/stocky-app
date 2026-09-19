<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a single 'invoice_receivables_report' permission for the new
 * Invoice Receivables Report (2026-09-19) — same coarse-grained,
 * one-permission-per-feature convention used for 'purchase_orders' and
 * 'activity_log_report' (see those migrations for the full reasoning).
 *
 * Automatically granted to whichever role(s) currently hold
 * 'Reports_sales' (the permission guarding the existing Sales Report) —
 * the closest existing analogue, since this report is a receivables view
 * of the same sales data. A starting default, freely reassignable
 * afterward from Roles & Permissions like any other permission.
 *
 * Written as a migration (not the master PermissionsSeeder.php) so it runs
 * automatically and idempotently via `php artisan migrate` on an existing
 * production database.
 *
 * Explicit ID, clear of the master seeder's range (~307) and the
 * 'purchase_orders' (900001) / 'activity_log_report' (900002) migrations'
 * reserved ids.
 */
return new class extends Migration
{
    private const RESERVED_PERMISSION_ID = 900003;

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'invoice_receivables_report')->value('id');

        if (! $permissionId) {
            $exists = DB::table('permissions')->where('id', self::RESERVED_PERMISSION_ID)->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'id' => self::RESERVED_PERMISSION_ID,
                    'name' => 'invoice_receivables_report',
                    'label' => 'Invoice Receivables Report',
                    'description' => 'View the invoice-wise receivables report (Net Invoice, Paid, Remaining, Due Date, Overdue).',
                ]);
            }
            $permissionId = self::RESERVED_PERMISSION_ID;
        }

        $roleIdsWithSalesReport = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'Reports_sales')
            ->pluck('permission_role.role_id');

        foreach ($roleIdsWithSalesReport as $roleId) {
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
        $permissionId = DB::table('permissions')->where('name', 'invoice_receivables_report')->value('id');

        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
