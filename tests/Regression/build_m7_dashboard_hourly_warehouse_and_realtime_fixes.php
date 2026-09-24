<?php

/**
 * Build M7 — two real bugs on the Real-time Sales Counter page, plus its
 * "Today's sales by hour" and "Sales by Warehouse" panels added to the main
 * Dashboard (2026-09-19).
 *
 * Bugs (both in DashboardController::real_time_sales_counter_data()):
 *   1. The hourly chart always showed everything in the midnight (00h)
 *      bucket. Root cause: `sales.date` is a DATE-only column (no time of
 *      day) and `sales.time` is a separate TIME column, but the hourly
 *      breakdown query grouped by `HOUR(sales.date)` — HOUR() on a bare
 *      DATE is always 0 in MySQL, so every sale landed in hour 0
 *      regardless of when it actually happened, even though the table
 *      right below it (which builds its timestamp from date+time
 *      together) showed the correct times. Fixed to derive the hour from
 *      `sales.time` instead (via a new driver-portable hourExpression()
 *      helper, also reused by the two new Dashboard queries below).
 *   2. The "Recent Sales" table's Reference column was always blank.
 *      Root cause: the backend returned the field as lowercase 'ref', but
 *      the Vue table's column reads dataIndex 'Ref' (capitalized, matching
 *      every other invoice-reference field in this app) — a silent key
 *      mismatch, not a missing column. Fixed to return 'Ref'.
 *
 * Feature: the user asked for the same "Today's sales by hour" chart and a
 * "Sales by Warehouse" breakdown to also show on the main Dashboard (not
 * just the Real-time Sales Counter page). Added two new
 * DashboardController methods (HourlySalesToday, SalesByWarehouse) and
 * wired their output into dashboard_data()'s response as
 * 'hourly_sales_today' (always today, like the counter page) and
 * 'sales_by_warehouse' (respects the Dashboard's own date-range filter).
 *
 * This test creates two real sales with their `time` column forced to
 * known values (02:15:00 and 14:40:00, on two different real warehouses)
 * and confirms, against the REAL rendered data:
 *   1. real_time_sales_counter_data(): hour 2 and hour 14 each gained
 *      exactly one sale (not hour 0), and the "Recent Sales" entries carry
 *      a non-empty 'Ref' key matching the sale's real Ref.
 *   2. HourlySalesToday() / SalesByWarehouse() (the two new Dashboard
 *      methods, called directly — dashboard_data() itself also calls the
 *      vendor's pre-existing SalesChart(), which uses a MySQL-only
 *      DATE_FORMAT() and cannot run against this sqlite test sandbox at
 *      all; that's a pre-existing, unrelated vendor gap, not something
 *      this build touched, so it's out of scope here) — same two-hour
 *      buckets and both warehouses with the right invoice count/amount
 *      deltas — using before/after deltas throughout, since the sandbox
 *      DB already has other "today"-dated fixture sales left over from
 *      earlier builds.
 *
 * Run with: php tests/Regression/build_m7_dashboard_hourly_warehouse_and_realtime_fixes.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Audit Batch 2: this gate sells products that have no stock on hand (it tests other behaviour), so
// allow overselling for its duration and restore the setting afterwards.
$__prevOversell = \Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->value('allow_overselling');
\Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => 1]);
register_shutdown_function(function () use ($__prevOversell) {
    \Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->update(['allow_overselling' => (int) $__prevOversell]);
});

use App\Http\Controllers\DashboardController;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);
app('request')->setUserResolver(fn ($guard = null) => $admin);

$controller = app(DashboardController::class);
$warehouse1 = Warehouse::whereNull('deleted_at')->first();
$warehouse2 = Warehouse::create(['name' => 'Build M7 Test Warehouse 2']);
$product = DB::table('products')->whereNull('deleted_at')->first();

$nextClientCode = ((int) Client::max('code')) + 1;
$client = Client::create([
    'name' => 'Build M7 Test Client', 'firstname' => 'M7', 'lastname' => 'Client',
    'code' => (string) $nextClientCode,
]);

$salesController = app(\App\Http\Controllers\SalesController::class);

function fetchRealTime(DashboardController $controller): array
{
    $req = Request::create('/api/real_time_sales_counter_data', 'GET');
    app()->instance('request', $req);
    $res = json_decode($controller->real_time_sales_counter_data($req)->getContent(), true);

    return $res ?? [];
}

/**
 * Calls the two new Dashboard methods directly with the same args
 * dashboard_data() would pass for an "all warehouses, today" request —
 * see the docblock above for why this test doesn't go through
 * dashboard_data() itself.
 */
function fetchDashboardPanels(DashboardController $controller): array
{
    $today = now()->toDateString();
    $arrayWarehousesId = Warehouse::where('deleted_at', null)->pluck('id')->toArray();

    return [
        'hourly_sales_today' => $controller->HourlySalesToday(0, $arrayWarehousesId),
        'sales_by_warehouse' => $controller->SalesByWarehouse(0, $arrayWarehousesId, $today, $today)->toArray(),
    ];
}

function hourlyCounts(array $hourly): array
{
    $out = array_fill(0, 24, 0);
    foreach ($hourly as $row) {
        $h = (int) ($row['hour'] ?? -1);
        if ($h >= 0 && $h < 24) {
            $out[$h] = (int) ($row['count'] ?? 0);
        }
    }

    return $out;
}

function warehouseInvoiceCounts(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['warehouse_id']] = (int) ($row['total_invoice'] ?? 0);
    }

    return $out;
}

// ==================== Baselines (before creating test sales) ====================
$baselineRealTime = fetchRealTime($controller);
$baselineHourlyRT = hourlyCounts($baselineRealTime['hourly'] ?? []);

$baselineDash = fetchDashboardPanels($controller);
$baselineHourlyDash = hourlyCounts($baselineDash['hourly_sales_today'] ?? []);
$baselineWarehouseCounts = warehouseInvoiceCounts($baselineDash['sales_by_warehouse'] ?? []);

// ==================== Create two sales at known hours, on two warehouses ====================
$makeSale = static function (int $warehouseId, string $time) use ($salesController, $client, $product) {
    $req = Request::create('/api/sales', 'POST', [
        'client_id' => $client->id, 'warehouse_id' => $warehouseId,
        'date' => now()->toDateString(), 'statut' => 'completed', 'notes' => 'Build M7 hourly/warehouse test',
        'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'discount_Method' => '2',
        'shipping' => 0, 'GrandTotal' => 100, 'discount_from_points' => 0, 'used_points' => 0,
        'payment' => ['status' => 'pending'],
        'details' => [[
            'product_id' => $product->id, 'quantity' => 1, 'Unit_price' => 100,
            'tax_percent' => 0, 'tax_method' => '1', 'subtotal' => 100,
            'discount' => 0, 'discount_Method' => '2', 'product_variant_id' => null,
            'serial_numbers' => [], 'sale_unit_id' => $product->unit_sale_id ?? $product->unit_id,
        ]],
    ]);
    app()->instance('request', $req);
    $id = json_decode($salesController->store($req)->getContent(), true)['sale_id'] ?? null;
    if ($id) {
        // store() always stamps `time` as now() — force it to the hour this
        // test needs, exactly as a real sale made at that time of day would
        // have it, so the hourly-breakdown bug is exercised for real.
        Sale::where('id', $id)->update(['time' => $time]);
    }

    return $id;
};

$saleIdHour2 = $makeSale((int) $warehouse1->id, '02:15:00');
$saleIdHour14 = $makeSale((int) $warehouse2->id, '14:40:00');
$assert($saleIdHour2 !== null, 'Setup: the 02:15 test sale (warehouse 1) must have been created.');
$assert($saleIdHour14 !== null, 'Setup: the 14:40 test sale (warehouse 2) must have been created.');

// ==================== 1. Real-time Sales Counter: hourly bug fix ====================
$rt = fetchRealTime($controller);
$hourlyRT = hourlyCounts($rt['hourly'] ?? []);

$assert(($hourlyRT[2] ?? 0) === ($baselineHourlyRT[2] ?? 0) + 1, 'Test 1a: hour 2 (02h) must have gained exactly one sale.');
$assert(($hourlyRT[14] ?? 0) === ($baselineHourlyRT[14] ?? 0) + 1, 'Test 1b: hour 14 (14h) must have gained exactly one sale.');
$assert(($hourlyRT[0] ?? 0) === ($baselineHourlyRT[0] ?? 0), 'Test 1c: hour 0 (midnight) must NOT have absorbed these two sales (the original bug).');

// ==================== 2. Real-time Sales Counter: Ref key fix ====================
$recent = collect($rt['recent_sales'] ?? []);
$rec2 = $recent->firstWhere('id', $saleIdHour2);
$rec14 = $recent->firstWhere('id', $saleIdHour14);
$assert($rec2 !== null && $rec14 !== null, 'Setup: both test sales must appear in recent_sales (only the latest 10 are returned, so this also confirms ordering).');
if ($rec2) {
    $assert(array_key_exists('Ref', $rec2) && ! empty($rec2['Ref']), 'Test 2a: recent_sales row must carry a non-empty "Ref" key (was lowercase "ref", which the frontend table never reads).');
    $assert(! array_key_exists('ref', $rec2), 'Test 2b: the old lowercase "ref" key must be gone, not just an added duplicate.');
}

// ==================== 3. Dashboard: hourly_sales_today ====================
$dash = fetchDashboardPanels($controller);
$hourlyDash = hourlyCounts($dash['hourly_sales_today'] ?? []);
$assert(($hourlyDash[2] ?? 0) === ($baselineHourlyDash[2] ?? 0) + 1, 'Test 3a: Dashboard hourly_sales_today hour 2 must have gained exactly one sale.');
$assert(($hourlyDash[14] ?? 0) === ($baselineHourlyDash[14] ?? 0) + 1, 'Test 3b: Dashboard hourly_sales_today hour 14 must have gained exactly one sale.');

// ==================== 4. Dashboard: sales_by_warehouse ====================
$warehouseCounts = warehouseInvoiceCounts($dash['sales_by_warehouse'] ?? []);
$assert(($warehouseCounts[(int) $warehouse1->id] ?? 0) === ($baselineWarehouseCounts[(int) $warehouse1->id] ?? 0) + 1, 'Test 4a: Dashboard sales_by_warehouse must show +1 invoice on warehouse 1.');
$assert(($warehouseCounts[(int) $warehouse2->id] ?? 0) === 1, 'Test 4b: Dashboard sales_by_warehouse must show the brand-new warehouse 2 with exactly 1 invoice (it had none before).');

// ==================== Cleanup ====================
Sale::where('notes', 'Build M7 hourly/warehouse test')->forceDelete();
Client::where('id', $client->id)->forceDelete();
Warehouse::where('id', $warehouse2->id)->forceDelete();

// ==================== Report ====================
if ($failures !== []) {
    fwrite(STDERR, "Build M7 regression FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Build M7 regression: PASS (hourly chart now buckets by the real hour instead of always midnight, Recent Sales' Reference column now has data, and Dashboard now exposes hourly_sales_today + sales_by_warehouse matching real per-hour/per-warehouse sale counts).\n";
