<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Small web-UI wrapper around `php artisan costing:rebuild --apply --enable` (see CostingRebuild), for a business
 * owner who cannot run artisan commands. It does not build any new costing logic — it only calls the existing
 * InventoryCostingService methods, exactly like the console command does (see CostingRebuild::apply()/enable()).
 *
 * Out of scope (by design): a real FIFO/batch-level costing engine. This is only a switch between the two costing
 * methods that already exist, `legacy` and `moving_average`.
 */
class CostingSettingsController extends Controller
{
    /** Cap on how many non-service products a single web request will cost synchronously (safety, not a real limit
     *  for this business's small catalog). Larger catalogs are told to use the CLI command instead. */
    private const MAX_SYNC_PRODUCTS = 3000;

    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);

        return response()->json($this->state());
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);

        if (! Svc::tablesReady()) {
            return response()->json(['message' => 'Inventory Costing tables are not installed yet. Run the migration first.'], 422);
        }

        $data = $request->validate([
            'method' => 'required|in:legacy,moving_average',
            'resync' => 'nullable|boolean',
        ]);
        $method = $data['method'];
        $resync = (bool) ($data['resync'] ?? false);

        $stats = null;
        if ($method === Svc::METHOD_MOVING_AVERAGE) {
            $needsSync = $resync || DB::table('inventory_cost_balances')->count() === 0;
            if ($needsSync) {
                $productIds = $this->nonServiceProductIds();
                if (count($productIds) > self::MAX_SYNC_PRODUCTS) {
                    return response()->json([
                        'message' => 'Catalog is too large ('.count($productIds).' products) to cost from the web UI. '.
                            'Run "php artisan costing:rebuild --apply --enable" from the command line instead.',
                    ], 422);
                }
                $stats = $this->costAll($productIds);
            }
        }

        Svc::setMethod($method);
        Svc::forgetMethodCache();

        return response()->json($this->state($stats));
    }

    /** @return int[] */
    private function nonServiceProductIds(): array
    {
        return DB::table('products')->where('type', '!=', 'is_service')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Mirrors CostingRebuild::costAll() — chunk by 200, reuse InventoryCostingService::syncProducts(). */
    private function costAll(array $productIds): array
    {
        $svc = new Svc;
        $stats = ['products' => 0, 'ledger_rows' => 0, 'seeds' => 0, 'stamps' => 0, 'corrections' => 0];
        foreach (array_chunk($productIds, 200) as $chunk) {
            $r = $svc->syncProducts($chunk);
            foreach ($stats as $k => $_) {
                $stats[$k] += $r[$k];
            }
        }

        return $stats;
    }

    private function state(?array $stats = null): array
    {
        $tablesReady = Svc::tablesReady();

        $out = [
            'tablesReady' => $tablesReady,
            'method' => $tablesReady ? Svc::method() : Svc::METHOD_LEGACY,
            'active' => $tablesReady && Svc::isActive(),
        ];

        if ($tablesReady) {
            $out['productsCosted'] = DB::table('inventory_cost_balances')->distinct('product_id')->count('product_id');
            $out['totalNonServiceProducts'] = count($this->nonServiceProductIds());
        } else {
            $out['productsCosted'] = 0;
            $out['totalNonServiceProducts'] = 0;
        }

        if ($stats !== null) {
            $out['lastSync'] = $stats;
        }

        return $out;
    }
}
