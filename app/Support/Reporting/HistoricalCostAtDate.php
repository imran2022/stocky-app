<?php

namespace App\Support\Reporting;

use App\Models\AdjustmentDetail;
use App\Models\PurchaseDetail;
use App\Support\UnitQuantityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Profit Report legacy COGS): a date-anchored average cost per (product_id, product_variant_id,
 * warehouse_id), built ONLY from purchase/adjustment history up to a given date.
 *
 * The legacy (costing OFF) branch of ProfitReportController used to value every sale line at TODAY's
 * `product(_variant).cost`, so editing a product's cost today silently rewrote the profit of every past period that
 * ever sold it. This class computes a date-anchored average instead (same purchases-up-to-`$end` +
 * adjustments-up-to-`$end` aggregation, same base-unit conversion via `UnitQuantityResolver` that
 * `App\Traits\CalculatesCogsAndAverageCost` already uses for the Profit & Loss report) so the fix does not touch
 * that shared trait.
 *
 * Kept WAREHOUSE-SPECIFIC (product_id, product_variant_id, warehouse_id all in the group-by and the output): an
 * earlier version of this class grouped by product/variant only, which blended different warehouses' purchase costs
 * into one number applied to every warehouse (e.g. stock bought at 100 in warehouse A and 300 in warehouse B both
 * being reported at a blended 200) — confirmed by an external code review and by a live reproduction. Each row here
 * is one warehouse's own average; a caller viewing "all warehouses" gets one row per warehouse and must join on
 * warehouse_id too, not just product/variant.
 *
 * IMPORTANT — does not (and cannot) fully avoid "today's master/variant cost" in every case: purchase-line cost is a
 * value stored on the purchase at the time it was recorded (stable), but an ADJUSTMENT addition here is still valued
 * at `COALESCE(pv.cost, pr.cost, 0)` — the CURRENT master/variant cost — because historical adjustments do not carry
 * their own stamped cost the way Moving Average's ledger does. So a key costed purely from purchases is immune to a
 * later master-cost edit; a key with adjustment history in its mix is not. Moving Average remains the authoritative
 * source for actual COGS/inventory value; this class only narrows (does not close) the Legacy-mode gap.
 *
 * A NULL `avg_cost` means no purchase/adjustment history exists yet for that key — callers should fall back to the
 * current master/variant cost in that case only, exactly like the trait's own `averageCostBulk()` fallback chain.
 */
class HistoricalCostAtDate
{
    /**
     * Materialize a temp table `product_id, product_variant_id, warehouse_id, avg_cost` for every product/variant/
     * warehouse combination that has purchase or adjustment history at or before $end.
     *
     * @param  array|null  $warehouseIds  restrict to these warehouses when $warehouseId is not set; null means no
     *                                    warehouse restriction at all (every warehouse), NOT "no warehouses".
     * @param  array|null  $productIds  when given, restricts the underlying purchase/adjustment scan to these
     *                                  product ids (performance: the caller already knows which products were
     *                                  actually sold/returned in the report window — there is no need to aggregate
     *                                  history for the rest of a large catalog). Null scans every product.
     * @param  bool  $includeAdjustments  Audit fix (MF-14): pass false to build a PURCHASES-ONLY average — needed
     *                                    by any caller that is itself pricing an Adjustment (e.g. write-off
     *                                    expense: an Adjustment "decrease" being costed) — including adjustments in
     *                                    the same average that is used to price one of those adjustments is
     *                                    self-referential and, because a 'sub' adjustment here is valued at TODAY's
     *                                    master cost rather than a stamped historical one, can badly distort the
     *                                    blended average (confirmed by a live repro). Default true preserves every
     *                                    existing caller (ProfitReportController's legacy COGS) unchanged.
     * @return string temp table name — join it on product_id, warehouse_id AND a null-safe `<=>` on
     *                 product_variant_id (product_variant_id is nullable).
     */
    public static function temp(string $end, ?int $warehouseId, ?array $warehouseIds, ?array $productIds = null, bool $includeAdjustments = true): string
    {
        $factor = UnitQuantityResolver::baseQuantityExpression('1', '1', 'pu');

        $purchases = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'purchase_details.purchase_unit_id')
            ->where('p.statut', 'received')
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $warehouseIds === null ? $q : $q->whereIn('p.warehouse_id', $warehouseIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('purchase_details.product_id', $productIds))
            ->where('p.date', '<=', $end)
            ->selectRaw("purchase_details.product_id, purchase_details.product_variant_id, p.warehouse_id,
                         SUM(purchase_details.quantity * {$factor}) as qty,
                         SUM(purchase_details.quantity * purchase_details.cost) as cost")
            ->groupBy('purchase_details.product_id', 'purchase_details.product_variant_id', 'p.warehouse_id');

        $union = $purchases->toBase();

        if ($includeAdjustments) {
            $adjustments = AdjustmentDetail::join('adjustments as a', 'a.id', '=', 'adjustment_details.adjustment_id')
                ->leftJoin('products as pr', 'pr.id', '=', 'adjustment_details.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'adjustment_details.product_variant_id')
                ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId),
                    fn ($q) => $warehouseIds === null ? $q : $q->whereIn('a.warehouse_id', $warehouseIds))
                ->when($productIds !== null, fn ($q) => $q->whereIn('adjustment_details.product_id', $productIds))
                ->where('a.date', '<=', $end)
                ->selectRaw("adjustment_details.product_id, adjustment_details.product_variant_id, a.warehouse_id,
                             SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) as qty,
                             SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) * COALESCE(NULLIF(pv.cost,0), pr.cost, 0) as cost")
                ->groupBy('adjustment_details.product_id', 'adjustment_details.product_variant_id', 'a.warehouse_id');

            $union = $union->unionAll($adjustments->toBase());
        }

        $grouped = DB::table(DB::raw("({$union->toSql()}) as u"))
            ->mergeBindings($union)
            ->selectRaw('u.product_id, u.product_variant_id, u.warehouse_id, SUM(u.qty) as qty, SUM(u.cost) as cost')
            ->groupBy('u.product_id', 'u.product_variant_id', 'u.warehouse_id');

        $sql = $grouped->toSql();
        $bindings = $grouped->getBindings();

        $table = 'tmp_hist_cost_'.bin2hex(random_bytes(6));
        DB::statement("CREATE TEMPORARY TABLE {$table} (
            product_id INT, product_variant_id INT NULL, warehouse_id INT, avg_cost DECIMAL(20,6) NULL,
            INDEX (product_id), INDEX (product_variant_id), INDEX (warehouse_id)
        ) ENGINE=InnoDB");
        DB::statement(
            "INSERT INTO {$table} (product_id, product_variant_id, warehouse_id, avg_cost)
             SELECT product_id, product_variant_id, warehouse_id, CASE WHEN qty > 0 THEN cost / qty ELSE NULL END
             FROM ({$sql}) as g",
            $bindings
        );

        return $table;
    }
}
