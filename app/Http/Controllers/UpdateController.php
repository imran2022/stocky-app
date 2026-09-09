<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\sms_gateway;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class UpdateController extends Controller
{
    public function get_version_info(Request $request)
    {
        $currentVersion = trim(File::get(base_path('version.txt')));

        // Fetch latest version info from update server
        $latestVersion = '';
        $latestInfo = null;
        $changelog = [];

        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 10],
                'https' => ['timeout' => 10],
            ]);
            $json = @file_get_contents('https://update-stocky.ui-lib.com/stocky_version.json', false, $ctx);
            if ($json !== false) {
                $latestInfo = json_decode($json, true);
                $latestVersion = $latestInfo['version'] ?? '';

                // Build changelog from remote data if available
                if (!empty($latestInfo['changelog'])) {
                    $changelog = $latestInfo['changelog'];
                } elseif (!empty($latestInfo['release_notes'])) {
                    $changelog = [
                        [
                            'version' => $latestVersion,
                            'date' => $latestInfo['date'] ?? now()->toDateString(),
                            'items' => array_map(function ($note) {
                                if (is_string($note)) {
                                    return ['type' => 'misc', 'text' => $note];
                                }
                                return $note;
                            }, (array) $latestInfo['release_notes']),
                        ],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail - we'll just show current version
        }

        // Load update history
        $updateHistory = [];
        $historyFile = storage_path('app/update_history.json');
        if (File::exists($historyFile)) {
            $updateHistory = json_decode(File::get($historyFile), true) ?: [];
            $updateHistory = array_reverse($updateHistory);
        }

        return response()->json([
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'latest_info' => $latestInfo,
            'changelog' => $changelog,
            'update_history' => array_slice($updateHistory, 0, 10),
        ]);
    }

    public function viewStep1(Request $request)
    {
        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');
        if ($permission) {
            $version = null;
            try {
                if (File::exists(base_path('version.txt'))) {
                    $version = trim(File::get(base_path('version.txt')));
                }
            } catch (\Throwable $e) {
                // Version chip is optional — never block the update page over it.
            }

            return view('update.viewStep1', ['version' => $version]);
        }
    }

    // Repair tables whose `id` primary key lost its AUTO_INCREMENT attribute
    // (usually caused by importing a SQL dump that dropped the clause).
    public function fix_auto_increment(Request $request)
    {
        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');

        if (! $permission) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        ini_set('max_execution_time', 2000);

        $database = DB::getDatabaseName();

        // Tables with a single-column integer primary key named `id` that is NOT auto_increment
        $broken = DB::select("
            SELECT c.TABLE_NAME AS table_name, c.COLUMN_TYPE AS column_type
            FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ?
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND c.COLUMN_NAME = 'id'
              AND c.COLUMN_KEY = 'PRI'
              AND c.EXTRA NOT LIKE '%auto_increment%'
              AND c.DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
              AND (SELECT COUNT(*) FROM information_schema.COLUMNS c2
                   WHERE c2.TABLE_SCHEMA = c.TABLE_SCHEMA
                     AND c2.TABLE_NAME = c.TABLE_NAME
                     AND c2.COLUMN_KEY = 'PRI') = 1
        ", [$database]);

        $fixed = [];
        $errors = [];

        foreach ($broken as $col) {
            $table = $col->table_name;

            try {
                // A row with id = 0 is a leftover from inserts made while auto_increment
                // was missing. Renumber it first so the ALTER cannot renumber it implicitly.
                if (DB::table($table)->where('id', 0)->exists()) {
                    $max = (int) DB::table($table)->max('id');
                    DB::table($table)->where('id', 0)->update(['id' => $max + 1]);
                }

                DB::statement("ALTER TABLE `{$table}` MODIFY `id` {$col->column_type} NOT NULL AUTO_INCREMENT");
                $fixed[] = $table;

            } catch (\Throwable $e) {
                $errors[$table] = $e->getMessage();
            }
        }

        return response()->json([
            'success' => count($errors) === 0,
            'checked_tables_needing_fix' => count($broken),
            'fixed' => $fixed,
            'errors' => $errors,
        ]);
    }

    public function lastStep(Request $request)
    {
        ini_set('max_execution_time', 2000); 
		ini_set('memory_limit', '512M');
        
        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');

        if ($permission) {
            ini_set('max_execution_time', 2000);

            try {

                Artisan::call('config:cache');
                Artisan::call('config:clear');

                // ----------------------------------------------------
                // ✅ Backward compatibility for old sales (NO discount_method)
                // ----------------------------------------------------
                 if (!Schema::hasColumn('sales', 'discount_method')) {

                    // Run ONLY if the old loyalty column exists
                    if (Schema::hasColumn('sales', 'discount_from_points')) {

                        DB::table('sales')
                            ->where('discount_from_points', '>', 0)
                            ->update([
                                'discount' => DB::raw('GREATEST(discount - discount_from_points, 0)')
                            ]);
                    }
                }


                Artisan::call('migrate --force');

                $role = Role::findOrFail(1);
                $role->permissions()->detach();

                $permissions = [
                    0 => 'view_employee',
                    1 => 'add_employee',
                    2 => 'edit_employee',
                    3 => 'delete_employee',
                    4 => 'company',
                    5 => 'department',
                    6 => 'designation',
                    7 => 'office_shift',
                    8 => 'attendance',
                    9 => 'leave',
                    10 => 'holiday',
                    11 => 'Top_products',
                    12 => 'Top_customers',
                    13 => 'shipment',
                    14 => 'users_report',
                    15 => 'stock_report',
                    16 => 'sms_settings',
                    17 => 'pos_settings',
                    18 => 'payment_gateway',
                    19 => 'mail_settings',
                    20 => 'dashboard',
                    21 => 'pay_due',
                    22 => 'pay_sale_return_due',
                    23 => 'pay_supplier_due',
                    24 => 'pay_purchase_return_due',
                    25 => 'product_report',
                    26 => 'product_sales_report',
                    27 => 'product_purchases_report',
                    28 => 'notification_template',
                    29 => 'edit_product_sale',
                    30 => 'edit_product_purchase',
                    31 => 'edit_product_quotation',
                    32 => 'edit_tax_discount_shipping_sale',
                    33 => 'edit_tax_discount_shipping_purchase',
                    34 => 'edit_tax_discount_shipping_quotation',
                    35 => 'module_settings',
                    36 => 'count_stock',
                    37 => 'deposit_add',
                    38 => 'deposit_delete',
                    39 => 'deposit_edit',
                    40 => 'deposit_view',
                    41 => 'account',
                    42 => 'inventory_valuation',
                    43 => 'expenses_report',
                    44 => 'deposits_report',
                    45 => 'transfer_money',
                    46 => 'payroll',
                    47 => 'projects',
                    48 => 'tasks',
                    49 => 'appearance_settings',
                    50 => 'translations_settings',
                    51 => 'subscription_product',
                    52 => 'report_error_logs',
                    53 => 'payment_methods',
                    54 => 'report_transactions',
                    55 => 'report_sales_by_category',
                    56 => 'report_sales_by_brand',
                    57 => 'opening_stock_import',
                    58 => 'seller_report',
                    59 => 'Store_settings_view',
                    60 => 'Orders_view',
                    61 => 'Collections_view',
                    62 => 'Banners_view',
                    63 => 'inactive_customers_report',
                    64 => 'zeroSalesProducts',
                    65 => 'Dead_Stock_Report',
                    66 => 'draft_invoices_report',
                    67 => 'discount_summary_report',
                    68 => 'tax_summary_report',
                    69 => 'Stock_Aging_Report',
                    70 => 'Stock_Transfer_Report',
                    71 => 'Stock_Adjustment_Report',
                    72 => 'Top_Suppliers_Report',
                    73 => 'Subscribers_view',
                    74 => 'Messages_view',
                    75 => 'cash_register_report',
                    76 => 'woocommerce_settings',
                    77 => 'customer_display_screen_setup',
                    78 => 'quickbooks_settings',
                    79 => 'customer_loyalty_points_report',
                    80 => 'assets',
                    81 => 'damage_view',
                    82 => 'cash_flow_report',
                    83 => 'report_attendance_summary',
                    84 => 'return_ratio_report',
                    85 => 'negative_stock_report',
                    86 => 'accounting_dashboard',
                    87 => 'chart_of_accounts',
                    88 => 'journal_entries',
                    89 => 'trial_balance',
                    90 => 'accounting_profit_loss',
                    91 => 'balance_sheet',
                    92 => 'accounting_tax_report',
                    93 => 'service_jobs',
                    94 => 'service_jobs_report',
                    95 => 'checklist_completion_report',
                    96 => 'customer_maintenance_history_report',
                    97 => 'bookings',
                    98 => 'subcategory',
                    99 => 'login_device_management',
                    100 => 'report_device_management',
                    101 => 'update_settings',
                    102 => 'analytics_report',
                    103 => 'Stock_Inventory_Valuation',
                    104 => 'AI_Reports',
                    105 => 'report_warranty',
                    106 => 'system_health_view', 
                    107 => 'real_time_sales_counter',
                    108 => 'warehouse_locations',
                    109 => 'internal_location_report',
                    110 => 'contracts',
                    111 => 'commissions_view',
                    112 => 'commissions_add',
                    113 => 'commissions_edit',
                    114 => 'commissions_delete',
                    115 => 'knowledge_base_view',
                    116 => 'webhooks_view',
                    117 => 'webhooks_add',
                    118 => 'webhooks_edit',
                    119 => 'webhooks_delete',
                    120 => 'sales_3d_dashboard',
                    121 => 'batch_view',
                    122 => 'batch_manage',
                    123 => 'batch_writeoff',
                    124 => 'batch_force_override',
                    125 => 'expiry_report',
                    126 => 'Batch_Register_Report',
                    127 => 'kitchen_display_view',
                    128 => 'kitchen_display_manage',

                    // Recruit module
                    129 => 'recruit_job',
                    130 => 'recruit_category',
                    131 => 'recruit_candidate',
                    132 => 'recruit_application',
                    133 => 'recruit_interview',

                    // Meeting module
                    134 => 'meeting',
                    135 => 'meeting_attendance',
                    136 => 'meeting_report',

                    // Marketing module
                    137 => 'marketing_dashboard',
                    138 => 'marketing_campaigns',
                    139 => 'marketing_segments',
                    140 => 'marketing_templates',
                    141 => 'marketing_reports',
                    142 => 'marketing_settings',

                    // Serial / IMEI tracking module
                    143 => 'serial_numbers',
                    144 => 'serial_numbers_report',

                    // Real Estate module
                    145 => 'realestate_properties',
                    146 => 'realestate_categories',
                    147 => 'realestate_inquiries',

                    // Trays module (Booking Orders)
                    148 => 'trays',
                    149 => 'size_guides',
                    150 => 'loyalty_rewards',
                    151 => 'ewallet',

                    // Promotions module
                    152 => 'promotion',

                    // Document Archive module
                    153 => 'documents_view',
                    154 => 'documents_add',
                    155 => 'documents_edit',
                    156 => 'documents_delete',

                    // Vehicle & Fleet Management module
                    157 => 'fleet_vehicles_view',
                    158 => 'fleet_vehicles_add',
                    159 => 'fleet_vehicles_edit',
                    160 => 'fleet_vehicles_delete',
                    161 => 'fleet_maintenance',
                    162 => 'fleet_fuel',
                    163 => 'fleet_assignments',
                    164 => 'fleet_reports',

                    // Hospital Management module
                    165 => 'hms_dashboard',
                    166 => 'hms_patients_view',
                    167 => 'hms_patients_add',
                    168 => 'hms_patients_edit',
                    169 => 'hms_patients_delete',
                    170 => 'hms_doctors',
                    171 => 'hms_departments',
                    172 => 'hms_appointments',
                    173 => 'hms_visits',
                    174 => 'hms_admissions',
                    175 => 'hms_wards',
                    176 => 'hms_lab',
                    177 => 'hms_billing',
                    178 => 'hms_reports',

                    // School Management module
                    179 => 'school_dashboard',
                    180 => 'school_students_view',
                    181 => 'school_students_add',
                    182 => 'school_students_edit',
                    183 => 'school_students_delete',
                    184 => 'school_teachers',
                    185 => 'school_academics',
                    186 => 'school_enrollment',
                    187 => 'school_attendance',
                    188 => 'school_exams',
                    189 => 'school_timetable',
                    190 => 'school_fees',
                    191 => 'school_reports',

                    // Projects Management additions
                    192 => 'project_milestones',
                    193 => 'project_timesheets',
                    194 => 'project_reports',

                    // Asset Management additions
                    195 => 'asset_assignments',
                    196 => 'asset_maintenance',
                    197 => 'asset_transfers',
                    198 => 'asset_reports',

                    // Shopify Integration
                    199 => 'shopify_stores',
                    200 => 'shopify_sync',
                    201 => 'shopify_logs',

                    // Advanced Manufacturing MRP
                    202 => 'mrp_boms',
                    203 => 'mrp_production',
                    204 => 'mrp_quality',
                    205 => 'mrp_planning',
                    206 => 'mrp_reports',

                    // Settings → Modules (business module toggles)
                    207 => 'business_modules',




                ];

                foreach ($permissions as $permission_slug) {
                    $perm = Permission::firstOrCreate(['name' => $permission_slug]);
                }

                $permissions_data = Permission::pluck('id')->toArray();
                $role->permissions()->attach($permissions_data);

                // create new sms gateway infobip
                sms_gateway::firstOrCreate(['title' => 'infobip']);
                sms_gateway::firstOrCreate(['title' => 'termii']); // ✅ Create "termii" gateway
                sms_gateway::firstOrCreate(['title' => 'custom']); // ✅ Create "custom" gateway

                // Check if the SMS gateway with the title 'nexmo' exists
                $nexmoGateway = sms_gateway::where('title', 'nexmo')->first();

                if ($nexmoGateway) {
                    // If it exists, delete it
                    $nexmoGateway->delete();
                }

                // Insert SMS message template: subscription_reminder
                DB::table('sms_messages')->updateOrInsert(
                    [
                        'name' => 'subscription_reminder',
                        'text' => "Hello {client_name},\nThis is a reminder from {business_name} that your subscription will renew automatically on {next_billing_date}. \nPlease ensure your payment method is up-to-date to avoid interruptions.\nThank you!",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Insert SMS message template: asset_validation_due (tags: {asset_name},{asset_tag},{next_validation},{business_name})
                $smsAssetValidationKeys = ['name' => 'asset_validation_due'];
                if (Schema::hasColumn('sms_messages', 'locale')) {
                    $smsAssetValidationKeys['locale'] = 'en';
                }
                DB::table('sms_messages')->updateOrInsert(
                    $smsAssetValidationKeys,
                    [
                        'text' => "Reminder from {business_name}: Asset {asset_name} ({asset_tag}) is due for validation on {next_validation}. Please verify the tool or equipment.",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Insert email message template: booking
                DB::table('email_messages')->updateOrInsert(
                    ['name' => 'booking'],
                    [
                        'subject' => 'Your Booking Confirmation',
                        'body' => '<p><b><span style="font-size:14px;">Dear {contact_name},</span></b></p><p>Your booking has been confirmed. Booking number: {booking_number}.</p><p><span style="font-size:14px;">Date: {booking_date}<br>Time: {start_time} - {end_time}<br>Service: {service_name}</span></p><p>If you have any questions, please don\'t hesitate to contact us.</p><p>Best regards,</p><p><b><span style="font-size:14px;">{business_name}</span></b></p>',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Insert email message template: asset_validation_due (tags: {asset_name},{asset_tag},{next_validation},{asset_edit_url},{business_name})
                $emailAssetValidationKeys = ['name' => 'asset_validation_due'];
                if (Schema::hasColumn('email_messages', 'locale')) {
                    $emailAssetValidationKeys['locale'] = 'en';
                }
                DB::table('email_messages')->updateOrInsert(
                    $emailAssetValidationKeys,
                    [
                        'subject' => 'Asset validation due: {asset_name}',
                        'body' => '<p>The following asset is due for validation (or is overdue). Please verify the tool or equipment.</p><p><b>Asset:</b> {asset_name} ({asset_tag})<br><b>Next validation date:</b> {next_validation}</p><p><a href="{asset_edit_url}">View / Edit asset</a></p><p>Best regards,<br><b>{business_name}</b></p>',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // ✅ Run TranslationSeeder automatically
                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\TranslationSeeder',
                    '--force' => true,
                ]);

                // ✅ Run StoreSettingSeeder automatically
                Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\StoreSettingSeeder',
                    '--force' => true,
                ]);

                // ----------------------------------------------------
                // ✅ Clean product names containing < or >
                // ----------------------------------------------------
                $cleaned = 0;
                \App\Models\Product::where('name', 'REGEXP', '<|>')
                    ->chunkById(200, function ($products) use (&$cleaned) {
                        foreach ($products as $p) {
                            $old = $p->name;
                            // Replace dangerous characters or strip HTML
                            $clean = str_replace(['<', '>'], ['‹', '›'], strip_tags($old));
                            if ($clean !== $old) {
                                $p->name = $clean;
                                $p->save();
                                $cleaned++;
                            }
                        }
                    });

                // ✅ Clear caches so translations are picked up immediately
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('view:clear');
                Artisan::call('route:clear');

            } catch (\Exception $e) {

                return $e->getMessage();

                return 'Something went wrong';
            }

            return view('update.finishedUpdate');
        }
    }
}
