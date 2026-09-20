<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\RealTimeSalesDisplay;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealTimeSalesDisplayController extends Controller
{
    private const LEGACY_TOKEN_KEY = 'real_time_sales_display:active';

    public function index(Request $request)
    {
        $user = $this->authorizedUser($request);
        $this->migrateLegacyDisplay($user);
        $query = RealTimeSalesDisplay::with(['creator:id,firstname,lastname,username', 'warehouse:id,name'])
            ->latest('id')
            ->limit(50);
        $this->applyDisplayVisibility($query, $user);

        return response()->json([
            'displays' => $query->get()->map(fn (RealTimeSalesDisplay $display) => $this->displayResource($display))->values(),
        ]);
    }

    public function generate(Request $request)
    {
        $user = $this->authorizedUser($request);
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
            'refresh_seconds' => ['nullable', 'integer', 'min:10', 'max:120'],
            'show_customer_names' => ['nullable', 'boolean'],
        ]);

        $allowedWarehouseIds = $this->allowedWarehouseIds($user);
        if (empty($allowedWarehouseIds)) {
            return response()->json(['message' => 'No warehouse is available to this user.'], 422);
        }

        $warehouseId = (int) $request->input('warehouse_id', 0);
        if ($warehouseId !== 0 && ! in_array($warehouseId, $allowedWarehouseIds, true)) {
            return response()->json(['message' => 'The selected warehouse is not available to this user.'], 422);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDay();
        $display = RealTimeSalesDisplay::create([
            'name' => trim((string) $request->input('name')),
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => Crypt::encryptString($token),
            'warehouse_ids' => $warehouseId ? [$warehouseId] : $allowedWarehouseIds,
            'warehouse_id' => $warehouseId ?: null,
            'show_customer_names' => $request->boolean('show_customer_names', false),
            'refresh_seconds' => min(120, max(10, (int) $request->input('refresh_seconds', 30))),
            'created_by' => (int) $user->id,
            'scope_user_id' => (int) $user->id,
            'view_all_records' => (bool) $user->hasRecordView(),
            'expires_at' => $expiresAt,
        ]);
        $display->load(['creator:id,firstname,lastname,username', 'warehouse:id,name']);

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
            'display' => $this->displayResource($display, $token),
        ]);
    }

    public function current(Request $request)
    {
        $user = $this->authorizedUser($request);
        $this->migrateLegacyDisplay($user);
        $query = RealTimeSalesDisplay::with(['creator:id,firstname,lastname,username', 'warehouse:id,name'])
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id');
        $this->applyDisplayVisibility($query, $user);
        $display = $query->first();
        if ($display) {
            return response()->json(['active' => true] + $this->displayResource($display));
        }

        // Backward compatibility for the one cache-only token created before
        // Phase 4. It remains usable until its original expiry.
        $config = Cache::get(self::LEGACY_TOKEN_KEY);
        if (! is_array($config) || empty($config['token_ciphertext']) || empty($config['expires_at'])) {
            return response()->json(['active' => false]);
        }

        try {
            $expiresAt = Carbon::parse($config['expires_at']);
            if ($expiresAt->isPast()) {
                Cache::forget(self::LEGACY_TOKEN_KEY);
                return response()->json(['active' => false]);
            }
            $token = Crypt::decryptString($config['token_ciphertext']);
        } catch (\Throwable $error) {
            return response()->json(['active' => false]);
        }

        return response()->json([
            'active' => true,
            'url' => url('/real-time-sales-display').'?token='.$token,
            'expires_at' => $expiresAt->toIso8601String(),
            'name' => 'Legacy display',
            'warehouse_id' => (int) ($config['selected_warehouse_id'] ?? 0),
            'refresh_seconds' => (int) ($config['refresh_seconds'] ?? 30),
            'show_customer_names' => (bool) ($config['show_customer_names'] ?? false),
        ]);
    }

    public function regenerate(Request $request, int $id)
    {
        $user = $this->authorizedUser($request);
        $display = $this->visibleDisplay($id, $user);
        $token = Str::random(64);
        $expiresAt = now()->addDay();
        $display->update([
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => Crypt::encryptString($token),
            'expires_at' => $expiresAt,
            'last_seen_at' => null,
            'revoked_at' => null,
        ]);
        $display->load(['creator:id,firstname,lastname,username', 'warehouse:id,name']);

        return response()->json([
            'message' => 'Display link regenerated.',
            'display' => $this->displayResource($display, $token),
        ]);
    }

    public function revoke(Request $request, int $id)
    {
        $user = $this->authorizedUser($request);
        $display = $this->visibleDisplay($id, $user);
        if (! $display->revoked_at) {
            $display->update(['revoked_at' => now()]);
        }

        return response()->json(['message' => 'Display access revoked.']);
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

        $display = RealTimeSalesDisplay::where('token_hash', hash('sha256', $token))->first();
        if ($display) {
            if (! $display->isAccessible()) {
                return null;
            }

            if (! $display->last_seen_at || $display->last_seen_at->lte(now()->subMinute())) {
                RealTimeSalesDisplay::whereKey($display->id)->update(['last_seen_at' => now()]);
            }

            return [
                'display_id' => (int) $display->id,
                'warehouse_ids' => array_map('intval', $display->warehouse_ids ?: []),
                'selected_warehouse_id' => (int) ($display->warehouse_id ?: 0),
                'show_customer_names' => (bool) $display->show_customer_names,
                'refresh_seconds' => (int) $display->refresh_seconds,
                'view_all_records' => (bool) $display->view_all_records,
                'user_id' => (int) $display->scope_user_id,
                'expires_at' => $display->expires_at?->toIso8601String(),
            ];
        }

        $legacy = Cache::get(self::LEGACY_TOKEN_KEY);
        if (! is_array($legacy) || empty($legacy['hash'])) {
            return null;
        }

        return hash_equals((string) $legacy['hash'], hash('sha256', $token)) ? $legacy : null;
    }

    private function authorizedUser(Request $request)
    {
        $user = $request->user('api');
        $role = $user ? $user->roles()->first() : null;
        abort_unless($user && $role && $role->inRole('real_time_sales_counter'), 403, 'Unauthorized');

        return $user;
    }

    private function migrateLegacyDisplay($user): void
    {
        $legacy = Cache::get(self::LEGACY_TOKEN_KEY);
        if (! is_array($legacy) || empty($legacy['hash']) || empty($legacy['token_ciphertext']) || empty($legacy['expires_at'])) {
            return;
        }

        try {
            $expiresAt = Carbon::parse($legacy['expires_at']);
            if ($expiresAt->isPast()) {
                Cache::forget(self::LEGACY_TOKEN_KEY);
                return;
            }

            RealTimeSalesDisplay::firstOrCreate(
                ['token_hash' => (string) $legacy['hash']],
                [
                    'name' => 'Existing display',
                    'token_encrypted' => (string) $legacy['token_ciphertext'],
                    'warehouse_id' => ! empty($legacy['selected_warehouse_id']) ? (int) $legacy['selected_warehouse_id'] : null,
                    'warehouse_ids' => array_map('intval', $legacy['warehouse_ids'] ?? []),
                    'refresh_seconds' => (int) ($legacy['refresh_seconds'] ?? 30),
                    'show_customer_names' => (bool) ($legacy['show_customer_names'] ?? false),
                    'created_by' => (int) ($legacy['created_by'] ?? $user->id),
                    'scope_user_id' => (int) ($legacy['user_id'] ?? $user->id),
                    'view_all_records' => (bool) ($legacy['view_all_records'] ?? false),
                    'expires_at' => $expiresAt,
                ]
            );
            Cache::forget(self::LEGACY_TOKEN_KEY);
        } catch (\Throwable $error) {
            // Leave the legacy cache entry intact so the old public link keeps
            // working if migration is temporarily unavailable.
        }
    }

    private function allowedWarehouseIds($user): array
    {
        return $user->is_all_warehouses
            ? Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
    }

    private function applyDisplayVisibility($query, $user): void
    {
        if ($user->is_all_warehouses) {
            return;
        }

        // Limited users manage only links they created. This prevents one
        // cashier from retrieving another user's encrypted display URL or
        // regenerating a link that carries a broader record-view snapshot.
        $query->where('created_by', $user->id);
    }

    private function visibleDisplay(int $id, $user): RealTimeSalesDisplay
    {
        $query = RealTimeSalesDisplay::query()->whereKey($id);
        $this->applyDisplayVisibility($query, $user);

        return $query->firstOrFail();
    }

    private function displayResource(RealTimeSalesDisplay $display, ?string $plainToken = null): array
    {
        $active = $display->isAccessible();
        $status = 'active';
        if ($display->revoked_at) {
            $status = 'revoked';
        } elseif (! $display->expires_at || $display->expires_at->isPast()) {
            $status = 'expired';
        } elseif ($display->last_seen_at) {
            $onlineWindow = max(90, ((int) $display->refresh_seconds * 3));
            $status = $display->last_seen_at->gte(now()->subSeconds($onlineWindow)) ? 'online' : 'idle';
        }

        if ($active && ! $plainToken) {
            try {
                $plainToken = Crypt::decryptString($display->token_encrypted);
            } catch (\Throwable $error) {
                $plainToken = null;
            }
        }

        $creatorName = trim(($display->creator?->firstname ?? '').' '.($display->creator?->lastname ?? ''));
        if ($creatorName === '') {
            $creatorName = $display->creator?->username ?: '—';
        }

        return [
            'id' => (int) $display->id,
            'name' => $display->name,
            'url' => $active && $plainToken ? url('/real-time-sales-display').'?token='.$plainToken : null,
            'warehouse_id' => (int) ($display->warehouse_id ?: 0),
            'warehouse_name' => $display->warehouse?->name ?: 'All permitted warehouses',
            'refresh_seconds' => (int) $display->refresh_seconds,
            'show_customer_names' => (bool) $display->show_customer_names,
            'created_by' => $creatorName,
            'created_at' => $display->created_at?->toIso8601String(),
            'expires_at' => $display->expires_at?->toIso8601String(),
            'last_seen_at' => $display->last_seen_at?->toIso8601String(),
            'revoked_at' => $display->revoked_at?->toIso8601String(),
            'status' => $status,
            'active' => $active,
        ];
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
            'expires_at' => $config['expires_at'] ?? null,
            'currency' => optional($setting?->Currency)->symbol ?: optional($setting?->Currency)->code ?: '',
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }
}
