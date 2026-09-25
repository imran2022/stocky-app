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

        $damage = (float) $wh(DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_variants as pv', fn ($j) => $j->on('pv.id', '=', 'd.product_variant_id')->whereNotNull('d.product_variant_id'))
            ->whereNull('h.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->whereBetween('h.date', [$from, $to]), 'h.warehouse_id')
            ->selectRaw('COALESCE(SUM(COALESCE(pv.cost, p.cost, 0) * d.quantity), 0) as total')
            ->value('total');

        $adjustmentOut = (float) $wh(DB::table('adjustment_details as ad')
            ->join('adjustments as a', 'a.id', '=', 'ad.adjustment_id')
            ->leftJoin('products as p', 'p.id', '=', 'ad.product_id')
            ->leftJoin('product_variants as pv', fn ($j) => $j->on('pv.id', '=', 'ad.product_variant_id')->whereNotNull('ad.product_variant_id'))
            ->whereNull('a.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->where('ad.type', 'sub')
            ->whereBetween('a.date', [$from, $to]), 'a.warehouse_id')
            ->selectRaw('COALESCE(SUM(COALESCE(pv.cost, p.cost, 0) * ad.quantity), 0) as total')
            ->value('total');

        return $damage + $adjustmentOut;
    }
}
