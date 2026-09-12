<?php

namespace App\Services\Custom;

use App\Support\UnitQuantityResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the custom Product-list insight metrics in a bounded number of queries.
 *
 * Build C introduced the set-based query shape. Build D1 aligned transaction
 * visibility with warehouse scope and soft-delete rules. Build D2 keeps that
 * architecture while making the rolling 30-day comparison exact and counting
 * only finalized (received) Sale Returns. G1 converts Sold (30d)/Previous 30d/
 * Lifetime Sold/Lifetime Returned to base-unit quantity via
 * UnitQuantityResolver, so Return Rate compares like-for-like. Scope is
 * limited to these five columns; POS, invoice rendering, stock movement, and
 * purchasing quantities are untouched (see UnitQuantityResolver's docblock).
 */
class ProductInsightService
{
    /**
     * @param  array<int>  $productIds
     * @param  array<int>  $allowedWarehouseIds
     * @return array<int, array{
     *     last_purchase_date: ?string,
     *     last_purchase_cost: ?float,
     *     total_sold_30d: float,
     *     total_sold_prev30d: float,
     *     last_sold_date: ?string,
     *     lifetime_sold: float,
     *     lifetime_returned: float,
     *     warehouse_count: int
     * }>
     */
    public function forProducts(
        array $productIds,
        ?int $warehouseId,
        array $allowedWarehouseIds,
        bool $isAllWarehouses
    ): array {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $metrics = [];
        foreach ($productIds as $productId) {
            $metrics[$productId] = $this->emptyMetrics();
        }

        $salesWindows = $this->rolling30DayWindows();

        // One grouped pass supplies all completed-sale metrics that previously
        // required four queries per product row.
        //
        // G1: quantities are converted to base units via UnitQuantityResolver
        // before summing — see that class for the exact rule. A left join to
        // `units` (aliased `su`) is required for the resolver's SQL expression
        // to reference the line's own sale unit.
        $saleBaseQty = UnitQuantityResolver::baseQuantityExpression('sd.quantity', 'sd.pack_multiplier', 'su');

        $salesMetrics = DB::table('sale_details as sd')
            ->join('sales as s', 's.id', '=', 'sd.sale_id')
            ->whereIntegerInRaw('sd.product_id', $productIds)
            ->where('s.statut', 'completed')
            ->whereNull('s.deleted_at');

        UnitQuantityResolver::joinSaleUnit($salesMetrics, 'sd', 'su');

        $this->applyWarehouseScope(
            $salesMetrics,
            's.warehouse_id',
            $warehouseId,
            $allowedWarehouseIds,
            $isAllWarehouses
        );

        $salesMetrics = $salesMetrics
            ->groupBy('sd.product_id')
            ->select('sd.product_id')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN sd.date >= ? AND sd.date < ? THEN {$saleBaseQty} ELSE 0 END), 0) as total_sold_30d",
                [$salesWindows['current_start'], $salesWindows['current_end_exclusive']]
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN sd.date >= ? AND sd.date < ? THEN {$saleBaseQty} ELSE 0 END), 0) as total_sold_prev30d",
                [$salesWindows['previous_start'], $salesWindows['previous_end_exclusive']]
            )
            ->selectRaw('MAX(sd.date) as last_sold_date')
            ->selectRaw("COALESCE(SUM({$saleBaseQty}), 0) as lifetime_sold")
            ->get();

        foreach ($salesMetrics as $row) {
            $productId = (int) $row->product_id;
            $metrics[$productId]['total_sold_30d'] = (float) $row->total_sold_30d;
            $metrics[$productId]['total_sold_prev30d'] = (float) $row->total_sold_prev30d;
            $metrics[$productId]['last_sold_date'] = $row->last_sold_date;
            $metrics[$productId]['lifetime_sold'] = (float) $row->lifetime_sold;
        }

        // Return Rate must represent finalized stock-affecting returns only.
        // Stocky's Sale Return workflow changes inventory only when statut is
        // 'received'; pending returns therefore must not contribute here.
        //
        // G1: same base-unit conversion as the sold-side metrics above, so
        // Return Rate compares like-for-like (base units over base units)
        // instead of mixing raw pack/unit line counts.
        $returnBaseQty = UnitQuantityResolver::baseQuantityExpression('srd.quantity', 'srd.pack_multiplier', 'ru');

        $returnMetrics = DB::table('sale_return_details as srd')
            ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
            ->whereIntegerInRaw('srd.product_id', $productIds)
            ->where('sr.statut', 'received')
            ->whereNull('sr.deleted_at');

        UnitQuantityResolver::joinReturnUnit($returnMetrics, 'srd', 'ru');

        $this->applyWarehouseScope(
            $returnMetrics,
            'sr.warehouse_id',
            $warehouseId,
            $allowedWarehouseIds,
            $isAllWarehouses
        );

        $returnMetrics = $returnMetrics
            ->groupBy('srd.product_id')
            ->select('srd.product_id')
            ->selectRaw("COALESCE(SUM({$returnBaseQty}), 0) as lifetime_returned")
            ->get();

        foreach ($returnMetrics as $row) {
            $metrics[(int) $row->product_id]['lifetime_returned'] = (float) $row->lifetime_returned;
        }

        // Warehouse Count keeps the same selected-warehouse / assigned-
        // warehouse visibility used by the Product list before this extraction.
        $warehouseMetrics = DB::table('product_warehouse as pw')
            ->whereIntegerInRaw('pw.product_id', $productIds)
            ->whereNull('pw.deleted_at')
            ->where('pw.qte', '>', 0);

        if ($warehouseId) {
            $warehouseMetrics->where('pw.warehouse_id', $warehouseId);
        } elseif (! $isAllWarehouses) {
            $allowedWarehouseIds = array_values(array_unique(array_map('intval', $allowedWarehouseIds)));
            if ($allowedWarehouseIds === []) {
                // Match the legacy whereIn([], ...) behavior explicitly: a user
                // with no assigned warehouse must get zero Warehouse Count.
                $warehouseMetrics->whereRaw('1 = 0');
            } else {
                $warehouseMetrics->whereIntegerInRaw('pw.warehouse_id', $allowedWarehouseIds);
            }
        }

        $warehouseMetrics = $warehouseMetrics
            ->groupBy('pw.product_id')
            ->select('pw.product_id')
            ->selectRaw('COUNT(DISTINCT pw.warehouse_id) as warehouse_count')
            ->get();

        foreach ($warehouseMetrics as $row) {
            $metrics[(int) $row->product_id]['warehouse_count'] = (int) $row->warehouse_count;
        }

        // Preserve the exact legacy "latest purchase" ordering:
        // purchase date DESC, then purchase_detail id DESC. Nested grouped
        // subqueries keep this set-based without requiring a database-specific
        // window function, which is safer for existing Stocky/MySQL/MariaDB
        // installations while preserving Build C's compatibility contract.
        $latestPurchaseDates = DB::table('purchase_details as latest_pd')
            ->join('purchases as latest_p', 'latest_p.id', '=', 'latest_pd.purchase_id')
            ->whereIntegerInRaw('latest_pd.product_id', $productIds)
            ->where('latest_p.statut', 'received')
            ->whereNull('latest_p.deleted_at');

        $this->applyWarehouseScope(
            $latestPurchaseDates,
            'latest_p.warehouse_id',
            $warehouseId,
            $allowedWarehouseIds,
            $isAllWarehouses
        );

        $latestPurchaseDates = $latestPurchaseDates
            ->groupBy('latest_pd.product_id')
            ->select('latest_pd.product_id')
            ->selectRaw('MAX(latest_p.date) as last_purchase_date');

        $latestPurchaseDetailIds = DB::table('purchase_details as candidate_pd')
            ->join('purchases as candidate_p', 'candidate_p.id', '=', 'candidate_pd.purchase_id')
            ->joinSub($latestPurchaseDates, 'latest_dates', function ($join) {
                $join->on('latest_dates.product_id', '=', 'candidate_pd.product_id')
                    ->on('latest_dates.last_purchase_date', '=', 'candidate_p.date');
            })
            ->whereIntegerInRaw('candidate_pd.product_id', $productIds)
            ->where('candidate_p.statut', 'received')
            ->whereNull('candidate_p.deleted_at');

        $this->applyWarehouseScope(
            $latestPurchaseDetailIds,
            'candidate_p.warehouse_id',
            $warehouseId,
            $allowedWarehouseIds,
            $isAllWarehouses
        );

        $latestPurchaseDetailIds = $latestPurchaseDetailIds
            ->groupBy('candidate_pd.product_id')
            ->select('candidate_pd.product_id')
            ->selectRaw('MAX(candidate_pd.id) as last_purchase_detail_id');

        $lastPurchases = DB::table('purchase_details as pd')
            ->join('purchases as p', 'p.id', '=', 'pd.purchase_id')
            ->joinSub($latestPurchaseDetailIds, 'latest_detail_ids', function ($join) {
                $join->on('latest_detail_ids.last_purchase_detail_id', '=', 'pd.id');
            })
            ->whereNull('p.deleted_at')
            ->get(['pd.product_id', 'pd.cost', 'p.date']);

        foreach ($lastPurchases as $row) {
            $productId = (int) $row->product_id;
            $metrics[$productId]['last_purchase_date'] = $row->date;
            $metrics[$productId]['last_purchase_cost'] = (float) $row->cost;
        }

        return $metrics;
    }


    /**
     * Exact rolling 30-calendar-day windows used by Sold (30d) and its
     * comparison period. The current window includes today and the preceding
     * 29 dates; the previous window is the immediately preceding 30 dates.
     *
     * Half-open intervals ([start, end)) avoid end-of-day/time precision bugs
     * and also prevent future-dated SaleDetail rows from leaking into the
     * current 30-day metric. `sale_details.date` is a DATE column, so application
     * timezone day boundaries are the correct business boundary here.
     *
     * @return array{
     *     current_start: string,
     *     current_end_exclusive: string,
     *     previous_start: string,
     *     previous_end_exclusive: string
     * }
     */
    public function rolling30DayWindows(): array
    {
        $today = now()->startOfDay();
        $currentStart = $today->copy()->subDays(29)->toDateString();

        return [
            'current_start' => $currentStart,
            'current_end_exclusive' => $today->copy()->addDay()->toDateString(),
            'previous_start' => $today->copy()->subDays(59)->toDateString(),
            'previous_end_exclusive' => $currentStart,
        ];
    }

    /**
     * Apply Product-list warehouse visibility consistently to an aggregate.
     *
     * A selected warehouse wins. Otherwise restricted users are limited to
     * their assigned warehouses; all-warehouse users keep the historical
     * all-warehouse view.
     */
    private function applyWarehouseScope(
        Builder $query,
        string $warehouseColumn,
        ?int $warehouseId,
        array $allowedWarehouseIds,
        bool $isAllWarehouses
    ): void {
        if ($warehouseId) {
            $query->where($warehouseColumn, $warehouseId);

            return;
        }

        if ($isAllWarehouses) {
            return;
        }

        $allowedWarehouseIds = array_values(array_unique(array_map('intval', $allowedWarehouseIds)));

        if ($allowedWarehouseIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIntegerInRaw($warehouseColumn, $allowedWarehouseIds);
    }

    /**
     * @return array{
     *     last_purchase_date: ?string,
     *     last_purchase_cost: ?float,
     *     total_sold_30d: float,
     *     total_sold_prev30d: float,
     *     last_sold_date: ?string,
     *     lifetime_sold: float,
     *     lifetime_returned: float,
     *     warehouse_count: int
     * }
     */
    private function emptyMetrics(): array
    {
        return [
            'last_purchase_date' => null,
            'last_purchase_cost' => null,
            'total_sold_30d' => 0.0,
            'total_sold_prev30d' => 0.0,
            'last_sold_date' => null,
            'lifetime_sold' => 0.0,
            'lifetime_returned' => 0.0,
            'warehouse_count' => 0,
        ];
    }
}
