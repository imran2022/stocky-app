<?php

namespace App\Support\Reporting;

use App\Support\UnitQuantityResolver;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Profit Report legacy correctness, follow-up to the historical-cost fix): the legacy (costing OFF)
 * branch of ProfitReportController used to read raw `sale_details` directly, which meant:
 *   - pending/draft sales were counted as realized revenue/profit (no `sales.statut = 'completed'` filter);
 *   - received sale returns were never netted off (no union with `sale_return_details`);
 *   - a box/pack sale (`quantity` stored in the SALE unit, e.g. "2 boxes") was costed as if `quantity` were already
 *     in base units, understating COGS whenever a unit conversion or pack multiplier applies.
 *
 * This class produces ONE normalized set of legacy profit lines — completed sales plus received returns (returns
 * negated), quantity converted to base units via the same UnitQuantityResolver math
 * `CalculatesCogsAndAverageCost::cogsSoldQty()`/`cogsReturnedQty()` already use for the Profit & Loss report —
 * materialized once per request into an indexed temp table, exactly like `CostingReader::profitLinesTemp()` does
 * for the Moving Average branch. `ProfitReportController` groups this ONE normalized source for both branches
 * instead of maintaining separate arithmetic per costing mode.
 *
 * Output columns (one row per source line — sale line, or return line with everything negated):
 *   line_id, sale_unit_id, product_id, product_variant_id, quantity (SALE-unit, signed), base_quantity (BASE-unit,
 *   signed — the only column cost calculations should multiply by), total (revenue, signed), date, warehouse_id,
 *   client_id, category_id, product_name, product_unit_id.
 */
class LegacyProfitLines
{
    /**
     * @param  int[]|null  $allowed  restrict to these warehouses when $warehouseId is not set; null means no
     *                               warehouse restriction at all (every warehouse).
     * @param  bool  $viewRecords  when false, restrict to documents owned by $userId (own-records-only screens).
     *                             Default true preserves every existing caller's behaviour unchanged.
     * @param  int|null  $userId  required when $viewRecords is false.
     * @return string temp table name
     */
    public static function temp(string $from, string $to, ?int $warehouseId, ?array $allowed, bool $viewRecords = true, ?int $userId = null): string
    {
        $wh = fn ($q, string $col) => $q->when($warehouseId, fn ($w) => $w->where($col, $warehouseId),
            fn ($w) => $allowed === null ? $w : $w->whereIn($col, $allowed));
        $own = fn ($q, string $col) => $q->when(! $viewRecords && $userId !== null, fn ($w) => $w->where($col, $userId));

        $saleBaseQty = UnitQuantityResolver::baseQuantityExpression('sd.quantity', 'sd.pack_multiplier', 'su');
        $sales = $own($wh(
            DB::table('sale_details as sd')
                ->join('sales as s', 's.id', '=', 'sd.sale_id')
                ->leftJoin('units as su', 'su.id', '=', 'sd.sale_unit_id')
                ->whereNull('s.deleted_at')
                ->where('s.statut', 'completed')
                ->whereBetween('s.date', [$from, $to]),
            's.warehouse_id'
        ), 's.user_id')->selectRaw("sd.id as line_id, sd.sale_unit_id, sd.product_id, sd.product_variant_id,
                       sd.quantity as quantity, ({$saleBaseQty}) as base_quantity, sd.total as total,
                       s.date, s.warehouse_id, s.client_id");

        $retBaseQty = UnitQuantityResolver::baseQuantityExpression('rd.quantity', 'rd.pack_multiplier', 'ru');
        $returns = $own($wh(
            DB::table('sale_return_details as rd')
                ->join('sale_returns as sr', 'sr.id', '=', 'rd.sale_return_id')
                ->leftJoin('units as ru', 'ru.id', '=', 'rd.sale_unit_id')
                ->whereNull('sr.deleted_at')
                ->where('sr.statut', 'received')
                ->whereBetween('sr.date', [$from, $to]),
            'sr.warehouse_id'
        ), 'sr.user_id')->selectRaw("-rd.id as line_id, rd.sale_unit_id, rd.product_id, rd.product_variant_id,
                       -rd.quantity as quantity, -({$retBaseQty}) as base_quantity, -rd.total as total,
                       sr.date, sr.warehouse_id, sr.client_id");

        $union = $sales->unionAll($returns);

        $base = DB::query()->fromSub($union, 'sd')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->selectRaw('sd.line_id, sd.sale_unit_id, sd.product_id, sd.product_variant_id, sd.quantity,
                         sd.base_quantity, sd.total, sd.date, sd.warehouse_id, sd.client_id, p.category_id,
                         p.name as product_name, COALESCE(sd.sale_unit_id, p.unit_sale_id, p.unit_id) as product_unit_id');

        $sql = $base->toSql();
        $bindings = $base->getBindings();

        $table = 'tmp_legacy_profit_lines_'.bin2hex(random_bytes(6));
        DB::statement("CREATE TEMPORARY TABLE {$table} (
            line_id BIGINT, sale_unit_id INT NULL, product_id INT, product_variant_id INT NULL,
            quantity DECIMAL(20,4), base_quantity DECIMAL(20,4), total DECIMAL(20,4),
            date DATE, warehouse_id INT, client_id INT, category_id INT NULL,
            product_name VARCHAR(255), product_unit_id INT NULL,
            INDEX (product_id), INDEX (warehouse_id), INDEX (client_id), INDEX (date), INDEX (category_id), INDEX (product_unit_id)
        ) ENGINE=InnoDB");
        DB::statement("INSERT INTO {$table} {$sql}", $bindings);

        return $table;
    }
}
