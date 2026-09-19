<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {

        $this->call([
            ClientSeeder::class,
            CurrencySeeder::class,
            SettingSeeder::class,
            ServerSeeder::class,
            PermissionsSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            UserRoleSeeder::class,
            PermissionRoleSeeder::class,
            // Fresh-install fix: grants 'purchase_orders' (added by a later
            // migration) to whichever role(s) hold 'Purchases_view' — must
            // run after PermissionRoleSeeder has populated those links, not
            // before. See PurchaseOrdersPermissionSeeder's own docblock for
            // why this can't just live in the migration alone.
            PurchaseOrdersPermissionSeeder::class,
            // Same fresh-install fix, same reasoning, for 'activity_log_report'
            // (added by a later migration too) — grants it to whichever
            // role(s) hold 'report_device_management'. See
            // ActivityLogPermissionSeeder's own docblock.
            ActivityLogPermissionSeeder::class,
            Warehouse::class,
            StoreSettingSeeder::class,
        ]);

    }
}
