<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealTimeSalesDisplayController extends Controller
{
    private const TOKEN_KEY = 'real_time_sales_display:active';

    public function generate(Request $request)
    {
        $user = $request->user('api');
        $role = $user ? $user->roles()->first() : null;
        if (! $user || ! $role || ! $role->inRole('real_time_sales_counter')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allowedWarehouseIds = $user->is_all_warehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();

        $warehouseId = (int) $request->input('warehouse_id', 0);
        if ($warehouseId !== 0 && ! in_array($warehouseId, $allowedWarehouseIds, true)) {
            return response()->json(['message' => 'The selected warehouse is not available to this user.'], 422);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDay();
        Cache::put(self::TOKEN_KEY, [
            'hash' => hash('sha256', $token),
            'warehouse_ids' => $warehouseId ? [$warehouseId] : $allowedWarehouseIds,
            'selected_warehouse_id' => $warehouseId,
            'show_customer_names' => $request->boolean('show_customer_names', false),
            'refresh_seconds' => min(120, max(10, (int) $request->input('refresh_seconds', 30))),
            'created_by' => (int) $user->id,
            // Preserve the authenticated report's record-level visibility.
            'view_all_records' => (bool) $user->hasRecordView(),
            'user_id' => (int) $user->id,
        ], $expiresAt);

        $url = url('/real-time-sales-display').'?token='.$token;
        $qrSvg = null;
        if (class_exists(\SimpleSoftwareIO\QrCode\Facades\QrCode::class)) {
            $qrSvg = \SimpleSoftwareIO\QrCode\Facades\QrCode::size(200)->generate($url);
        }

        return response()->json([
            'token' => $token,
            'url' => $url,
            'qr' => $qrSvg,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function page(Request $request)
    {
        $config = $this->tokenConfig((string) $request->query('token', ''));
        abort_unless($config, 403, 'Unauthorized or expired display access');

        return view('real_time_sales_display', [
            'displayToken' => (string) $request->query('token'),
        ]);
    }

    public function data(Request $request)
    {
        $token = (string) $request->query('token', '');
        $config = $this->tokenConfig($token);
        if (! $config) {
            return response()->json(['message' => 'Unauthorized or expired display access'], 403);
        }

        $cacheKey = 'real_time_sales_display:data:'.hash('sha256', $token);
        $payload = Cache::remember($cacheKey, now()->addSeconds(8), function () use ($config) {
            return $this->buildPayload($config);
        });

        return response()->json($payload);
    }

    private function tokenConfig(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $config = Cache::get(self::TOKEN_KEY);
        if (! is_array($config) || empty($config['hash'])) {
            return null;
        }

        return hash_equals((string) $config['hash'], hash('sha256', $token)) ? $config : null;
    }

    private function buildPayload(array $config): array
    {
        $warehouseIds = array_values(array_filter(array_map('intval', $config['warehouse_ids'] ?? [])));
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $base = Sale::query()
            ->whereNull('sales.deleted_at')
            ->where('sales.date', $today)
            ->whereIn('sales.warehouse_id', $warehouseIds);
        if (empty($config['view_all_records'])) {
            $base->where('sales.user_id', (int) ($config['user_id'] ?? 0));
        }

        $todayCount = (clone $base)->count();
        $todayTotal = (float) ((clone $base)->sum('GrandTotal') ?? 0);
        $todayPaid = (float) ((clone $base)->sum('paid_amount') ?? 0);
        $statusCounts = (clone $base)
            ->select('payment_statut', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('payment_statut')
            ->pluck('aggregate', 'payment_statut');

        $lastSale = (clone $base)->orderByDesc('sales.date')->orderByDesc('sales.id')->first();
        $lastSaleAt = $lastSale
            ? Carbon::parse(trim($lastSale->date.' '.($lastSale->time ?? '00:00:00')))->toIso8601String()
            : null;

        $driver = DB::connection()->getDriverName();
        $hourExpression = $driver === 'sqlite'
            ? "CAST(strftime('%H', COALESCE(sales.time, '00:00:00')) AS INTEGER)"
            : "HOUR(COALESCE(sales.time, '00:00:00'))";
        $hourlyRows = (clone $base)
            ->select(DB::raw($hourExpression.' as hour'), DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(GrandTotal),0) as total'))
            ->groupBy(DB::raw($hourExpression))
            ->get();
        $hourly = array_fill(0, 24, null);
        foreach ($hourly as $hour => $_) {
            $hourly[$hour] = ['hour' => $hour, 'count' => 0, 'total' => 0.0];
        }
        foreach ($hourlyRows as $row) {
            $hour = (int) $row->hour;
            if ($hour >= 0 && $hour <= 23) {
                $hourly[$hour] = ['hour' => $hour, 'count' => (int) $row->count, 'total' => (float) $row->total];
            }
        }

        $recentSales = (clone $base)
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->orderByDesc('sales.date')
            ->orderByDesc('sales.id')
            ->limit(8)
            ->get([
                'sales.id', 'sales.Ref', 'sales.date', 'sales.time', 'sales.GrandTotal',
                'sales.paid_amount', 'sales.payment_statut', 'clients.name as client_name',
                'warehouses.name as warehouse_name',
            ])
            ->map(function ($sale) use ($config) {
                return [
                    'id' => (int) $sale->id,
                    'Ref' => $sale->Ref,
                    'date' => Carbon::parse(trim($sale->date.' '.($sale->time ?? '00:00:00')))->toIso8601String(),
                    'grand_total' => (float) $sale->GrandTotal,
                    'payment_status' => $sale->payment_statut,
                    'client_name' => ! empty($config['show_customer_names']) ? $sale->client_name : null,
                    'warehouse_name' => $sale->warehouse_name,
                ];
            })->values();

        $saleIds = (clone $base)->pluck('sales.id');
        $topProducts = collect();
        if ($saleIds->isNotEmpty()) {
            $topProducts = SaleDetail::leftJoin('products', 'sale_details.product_id', '=', 'products.id')
                ->whereIn('sale_details.sale_id', $saleIds)
                ->select(
                    'products.id as product_id',
                    'products.name as product_name',
                    DB::raw('SUM(sale_details.quantity) as quantity'),
                    DB::raw('SUM(sale_details.total) as total')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name ?: '—',
                    'quantity' => (float) $row->quantity,
                    'total' => (float) $row->total,
                ])->values();
        }

        $lastSaleExpression = $driver === 'sqlite'
            ? "MAX(sales.date || ' ' || COALESCE(sales.time, '00:00:00'))"
            : "MAX(CONCAT(sales.date, ' ', COALESCE(sales.time, '00:00:00')))";
        $salesByLocation = (clone $base)
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->select(
                'sales.warehouse_id',
                'warehouses.name as warehouse_name',
                DB::raw('COUNT(*) as total_invoice'),
                DB::raw('COALESCE(SUM(sales.GrandTotal),0) as amount'),
                DB::raw($lastSaleExpression.' as last_sale')
            )
            ->groupBy('sales.warehouse_id', 'warehouses.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'name' => $row->warehouse_name ?: '—',
                'total_invoice' => (int) $row->total_invoice,
                'amount' => (float) $row->amount,
                'last_sale' => $row->last_sale ? Carbon::parse($row->last_sale)->toIso8601String() : null,
            ])->values();

        $yesterdayQuery = Sale::query()
            ->whereNull('sales.deleted_at')
            ->where('sales.date', $yesterday)
            ->whereIn('sales.warehouse_id', $warehouseIds);
        if (empty($config['view_all_records'])) {
            $yesterdayQuery->where('sales.user_id', (int) ($config['user_id'] ?? 0));
        }
        $yesterdayTotal = (float) $yesterdayQuery->sum('GrandTotal');

        $setting = Setting::with('Currency')->first();

        return [
            'today_count' => $todayCount,
            'today_total' => $todayTotal,
            'today_paid' => $todayPaid,
            'today_due' => max(0, $todayTotal - $todayPaid),
            'yesterday_total' => $yesterdayTotal,
            'last_sale_at' => $lastSaleAt,
            'payment_status_counts' => [
                'paid' => (int) ($statusCounts['paid'] ?? 0),
                'partial' => (int) ($statusCounts['partial'] ?? 0),
                'unpaid' => (int) ($statusCounts['unpaid'] ?? 0),
            ],
            'hourly' => array_values($hourly),
            'recent_sales' => $recentSales,
            'top_products' => $topProducts,
            'sales_by_location' => $salesByLocation,
            'selected_warehouse_id' => (int) ($config['selected_warehouse_id'] ?? 0),
            'selected_warehouse_name' => count($warehouseIds) === 1
                ? (Warehouse::whereKey($warehouseIds[0])->value('name') ?: 'All Warehouses')
                : 'All Warehouses',
            'show_customer_names' => (bool) ($config['show_customer_names'] ?? false),
            'refresh_seconds' => (int) ($config['refresh_seconds'] ?? 30),
            'currency' => optional($setting?->Currency)->symbol ?: optional($setting?->Currency)->code ?: '',
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }
}
