<?php

namespace App\Support\Reporting;

use App\Models\AdjustmentDetail;
use App\Models\PurchaseDetail;
use App\Support\UnitQuantityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Profit Report legacy COGS): a date-anchored average cost per (product_id, product_variant_id), built
 * ONLY from purchase/adjustment history up to a given date — never today's master/variant cost.
 *
 * The legacy (costing OFF) branch of ProfitReportController used to value every sale line at TODAY's
 * `product(_variant).cost`, so editing a product's cost today silently rewrote the profit of every past period that
 * ever sold it — the exact bug `App\Traits\CalculatesCogsAndAverageCost::averageCostBulk()` already avoids for the
 * Profit & Loss report. This class reimplements that same date-anchored average-cost math standalone (same
 * purchases-up-to-`$end` + adjustments-up-to-`$end` aggregation, same warehouse scope, same base-unit conversion via
 * `UnitQuantityResolver`) so the fix does not touch the shared trait used by other controllers.
 *
 * A NULL `avg_cost` means no purchase/adjustment history exists yet for that key — callers should fall back to the
 * current master/variant cost in that case only, exactly like the trait's own `averageCostBulk()` fallback chain.
 */
class HistoricalCostAtDate
{
    /**
     * Materialize a temp table `product_id, product_variant_id, avg_cost` for every product/variant that has
     * purchase or adjustment history at or before $end, scoped to the same warehouse(s) as the report.
     *
     * @param  array|null  $warehouseIds  restrict to these warehouses when $warehouseId is not set; null means no
     *                                    warehouse restriction at all (every warehouse), NOT "no warehouses".
     * @return string temp table name — join it with a null-safe `<=>` on both product_id and product_variant_id
     *                 (product_variant_id is nullable).
     */
    public static function temp(string $end, ?int $warehouseId, ?array $warehouseIds): string
    {
        $factor = UnitQuantityResolver::baseQuantityExpression('1', '1', 'pu');

        $purchases = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'purchase_details.purchase_unit_id')
            ->where('p.statut', 'received')
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $warehouseIds === null ? $q : $q->whereIn('p.warehouse_id', $warehouseIds))
            ->where('p.date', '<=', $end)
            ->selectRaw("purchase_details.product_id, purchase_details.product_variant_id,
                         SUM(purchase_details.quantity * {$factor}) as qty,
                         SUM(purchase_details.quantity * purchase_details.cost) as cost")
            ->groupBy('purchase_details.product_id', 'purchase_details.product_variant_id');

        $adjustments = AdjustmentDetail::join('adjustments as a', 'a.id', '=', 'adjustment_details.adjustment_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'adjustment_details.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'adjustment_details.product_variant_id')
            ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId),
                fn ($q) => $warehouseIds === null ? $q : $q->whereIn('a.warehouse_id', $warehouseIds))
            ->where('a.date', '<=', $end)
            ->selectRaw("adjustment_details.product_id, adjustment_details.product_variant_id,
                         SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) as qty,
                         SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) * COALESCE(NULLIF(pv.cost,0), pr.cost, 0) as cost")
            ->groupBy('adjustment_details.product_id', 'adjustment_details.product_variant_id');

        $union = $purchases->toBase()->unionAll($adjustments->toBase());
        $grouped = DB::table(DB::raw("({$union->toSql()}) as u"))
            ->mergeBindings($union)
            ->selectRaw('u.product_id, u.product_variant_id, SUM(u.qty) as qty, SUM(u.cost) as cost')
            ->groupBy('u.product_id', 'u.product_variant_id');

        $sql = $grouped->toSql();
        $bindings = $grouped->getBindings();

        $table = 'tmp_hist_cost_'.bin2hex(random_bytes(6));
        DB::statement("CREATE TEMPORARY TABLE {$table} (
            product_id INT, product_variant_id INT NULL, avg_cost DECIMAL(20,6) NULL,
            INDEX (product_id), INDEX (product_variant_id)
        ) ENGINE=InnoDB");
        DB::statement(
            "INSERT INTO {$table} (product_id, product_variant_id, avg_cost)
             SELECT product_id, product_variant_id, CASE WHEN qty > 0 THEN cost / qty ELSE NULL END
             FROM ({$sql}) as g",
            $bindings
        );

        return $table;
    }
}
