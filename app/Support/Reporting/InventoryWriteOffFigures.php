<?php

namespace App\Support\Reporting;

use App\Services\Costing\CostingReader;
use Illuminate\Support\Facades\DB;

/**
 * The cost of stock that left the business without being sold: Damage documents (always a loss) and the DECREASE
 * side of stock Adjustments (a count found less than the system expected). This is a real cost of doing business —
 * standard accounting practice (and every professional inventory system: QuickBooks, Zoho Inventory, Odoo) expenses
 * it immediately, the same way COGS is expensed on a sale.
 *
 * The INCREASE side of an Adjustment (a count found MORE than expected) is deliberately excluded: found stock is not
 * recognised as income until it is actually sold (accounting conservatism — losses are recognised immediately, gains
 * are not recognised until realised). It still raises inventory value; it just never shows up here or in profit.
 *
 * Cost basis: the product's/variant's cost when Moving Average costing is off (matches every other legacy figure —
 * "master cost" is a real number, just not one that follows a specific unit through time); the Moving Average
 * running cost from the ledger at the moment of the loss when costing is on (CostingReader::writeOffCost). Either
 * way this is a NEW figure — it did not exist in the legacy Profit & Loss / Dashboard profit before, which counted
 * neither Damage nor Adjustment losses as an expense.
 */
class InventoryWriteOffFigures
{
    /**
     * @param  int[]  $warehouseIds  used only when $warehouseId is falsy (0/null = "all visible warehouses")
     */
    public static function cost(string $from, string $to, ?int $warehouseId, array $warehouseIds): float
    {
        if (CostingReader::active()) {
            return CostingReader::writeOffCost($from, $to, $warehouseId ?: null, $warehouseIds);
        }

        $wh = fn ($q, string $col) => $q->when($warehouseId, fn ($w) => $w->where($col, $warehouseId), fn ($w) => $w->whereIn($col, $warehouseIds));

        // Audit fix (MF-14, external "Must-Fix" audit 2026-09-25): the legacy branch used to value every write-off
        // (Damage, Adjustment decrease) at TODAY's live product/variant cost, so editing a product's cost today
        // silently rewrote the write-off expense of every past period that ever wrote it off -- the exact same bug
        // class already fixed for legacy COGS in ProfitReportController via HistoricalCostAtDate. Anchored the same
        // way here: one date-anchored average per (product, variant, warehouse) as of $to (the report's end date),
        // built from purchase/adjustment history up to that date -- consistent with every other legacy-mode report
        // in this codebase, none of which attempt a true per-line as-of-that-exact-day cost either. A NULL
        // avg_cost (no purchase/adjustment history yet for that key) falls back to today's master/variant cost,
        // exactly like HistoricalCostAtDate's own documented fallback contract.
        $damageProductIds = $wh(DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->whereNull('h.deleted_at')
            ->whereBetween('h.date', [$from, $to]), 'h.warehouse_id')
            ->distinct()->pluck('d.product_id')->all();

        $adjProductIds = $wh(DB::table('adjustment_details as ad')
            ->join('adjustments as a', 'a.id', '=', 'ad.adjustment_id')
            ->whereNull('a.deleted_at')->where('ad.type', 'sub')
            ->whereBetween('a.date', [$from, $to]), 'a.warehouse_id')
            ->distinct()->pluck('ad.product_id')->all();

        $productIds = array_values(array_unique(array_merge($damageProductIds, $adjProductIds)));

        if ($productIds === []) {
            return 0.0;
        }

        // includeAdjustments=false: this class prices Adjustment "decrease" write-offs itself, so it must not feed
        // adjustments (least of all that very same decrease) into the average used to price them -- see the
        // parameter's docblock on HistoricalCostAtDate::temp() for why that would be self-referential.
        $histTable = HistoricalCostAtDate::temp($to, $warehouseId, $warehouseIds, $productIds, false);

        $damage = (float) $wh(DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_variants as pv', fn ($j) => $j->on('pv.id', '=', 'd.product_variant_id')->whereNotNull('d.product_variant_id'))
            ->leftJoin("{$histTable} as hc", fn ($j) => $j->on('hc.product_id', '=', 'd.product_id')
                ->on('hc.warehouse_id', '=', 'h.warehouse_id')
                ->whereRaw('hc.product_variant_id <=> d.product_variant_id'))
            ->whereNull('h.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->whereBetween('h.date', [$from, $to]), 'h.warehouse_id')
            ->selectRaw('COALESCE(SUM(COALESCE(hc.avg_cost, pv.cost, p.cost, 0) * d.quantity), 0) as total')
            ->value('total');

        $adjustmentOut = (float) $wh(DB::table('adjustment_details as ad')
            ->join('adjustments as a', 'a.id', '=', 'ad.adjustment_id')
            ->leftJoin('products as p', 'p.id', '=', 'ad.product_id')
            ->leftJoin('product_variants as pv', fn ($j) => $j->on('pv.id', '=', 'ad.product_variant_id')->whereNotNull('ad.product_variant_id'))
            ->leftJoin("{$histTable} as hc", fn ($j) => $j->on('hc.product_id', '=', 'ad.product_id')
                ->on('hc.warehouse_id', '=', 'a.warehouse_id')
                ->whereRaw('hc.product_variant_id <=> ad.product_variant_id'))
            ->whereNull('a.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->where('ad.type', 'sub')
            ->whereBetween('a.date', [$from, $to]), 'a.warehouse_id')
            ->selectRaw('COALESCE(SUM(COALESCE(hc.avg_cost, pv.cost, p.cost, 0) * ad.quantity), 0) as total')
            ->value('total');

        return $damage + $adjustmentOut;
    }
}
