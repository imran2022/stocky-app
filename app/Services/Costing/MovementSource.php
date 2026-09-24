<?php

namespace App\Services\Costing;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads every costed stock movement for a set of products, in base units, set-based (no per-product queries).
 *
 * The inclusion and unit-conversion rules are the ones ProductMovementLedgerService already applies (and that the
 * stock reconciliation tests cover), so a movement here changes stock if and only if the document changes
 * product_warehouse.qte:
 *   purchases      status 'received'
 *   sales          status 'completed'
 *   sale returns   status 'received'
 *   purchase ret.  status 'completed'
 *   transfers      out: 'completed'|'sent', in: 'completed'; approval 'approved' or NULL
 *   adjustments    add / sub          damages   always out
 * Soft-deleted headers (and purchase-return lines) never count. Service products carry no stock and are skipped.
 *
 * Purchase and purchase-return lines carry the app's own "Net Unit Cost" (line discount removed, tax carved out the
 * same way PurchasesController::show does), converted from the purchase unit to a cost per BASE unit.
 */
class MovementSource
{
    /** Same tie-break order as ProductMovementLedgerService. */
    private const PRIORITY = [
        'purchase' => 10, 'sale' => 20, 'transfer_out' => 30, 'transfer_in' => 40, 'adjustment' => 50,
        'sale_return' => 60, 'purchase_return' => 70, 'damage' => 80, 'seed' => 5,
    ];

    /**
     * @param  array<int,int>  $productIds
     * @return array<string,array<int,array<string,mixed>>>  movements grouped by "productId:variantKey", already sorted
     */
    public static function load(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $all = [];
        foreach (array_chunk($productIds, 400) as $chunk) {
            foreach ([
                self::purchases($chunk), self::purchaseReturns($chunk), self::sales($chunk), self::saleReturns($chunk),
                self::transfers($chunk), self::adjustments($chunk), self::damages($chunk), self::seeds($chunk),
            ] as $part) {
                foreach ($part as $row) {
                    $all[] = $row;
                }
            }
        }

        return self::group($all);
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function group(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['product_id'].':'.$r['variant_key']][] = $r;
        }
        foreach ($grouped as $k => $list) {
            usort($list, fn ($a, $b) => [$a['occurred_at'], self::PRIORITY[$a['type']], $a['source_id']]
                <=> [$b['occurred_at'], self::PRIORITY[$b['type']], $b['source_id']]);
            $grouped[$k] = $list;
        }

        return $grouped;
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Base units per one unit of the line's unit (falls back to the product's unit), same as the ledger service. */
    public static function factorSql(string $du, string $pu, ?string $pack = null): string
    {
        $packExpr = $pack === null ? '1' : "COALESCE(NULLIF({$pack}, 0), 1)";
        $op = "COALESCE({$du}.operator, {$pu}.operator)";
        $val = "COALESCE(NULLIF({$du}.operator_value, 0), NULLIF({$pu}.operator_value, 0), 1)";

        return "({$packExpr} * CASE WHEN {$op} = '/' THEN 1.0 / {$val} WHEN {$op} = '*' THEN {$val} ELSE 1 END)";
    }

    /** The app's "Net Unit Cost" for a purchase-side line (per purchase unit). */
    public static function netCostSql(string $d): string
    {
        $disc = "(CASE WHEN {$d}.discount_method = '2' THEN COALESCE({$d}.discount,0) ELSE {$d}.cost * COALESCE({$d}.discount,0) / 100 END)";
        $tax = "(CASE WHEN {$d}.tax_method = '2' THEN COALESCE({$d}.TaxNet,0) * ({$d}.cost - {$disc}) / 100 ELSE 0 END)";

        return "({$d}.cost - {$disc} - {$tax})";
    }

    private static function at($date, $time, $createdAt): string
    {
        $t = $time ?: ($createdAt ? Carbon::parse($createdAt)->format('H:i:s') : '00:00:00');

        return trim($date.' '.$t);
    }

    private static function base(array $r, string $type, int $sourceId, int $warehouse, float $qty, $ref, string $at, ?float $cost = null, array $extra = []): array
    {
        return array_merge([
            'product_id' => (int) $r['product_id'],
            'variant_key' => (int) ($r['product_variant_id'] ?? 0),
            'product_variant_id' => $r['product_variant_id'] !== null ? (int) $r['product_variant_id'] : null,
            'warehouse_id' => $warehouse,
            'type' => $type,
            'source_id' => $sourceId,
            'reference_id' => $ref !== null ? (int) $ref : null,
            'occurred_at' => $at,
            'qty' => $qty,
            'unit_cost' => $cost,
            'estimated' => false,
        ], $extra);
    }

    private static function purchases(array $pids): array
    {
        $factor = self::factorSql('du', 'pu');
        $rows = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')->where('h.statut', 'received')
            ->selectRaw("d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.warehouse_id,
                d.quantity * {$factor} as base_qty, ".self::netCostSql('d')." / {$factor} as unit_cost")
            ->get();

        return $rows->map(fn ($r) => self::base((array) $r, 'purchase', $r->id, (int) $r->warehouse_id, (float) $r->base_qty, $r->ref,
            self::at($r->date, $r->time, $r->created_at), (float) $r->unit_cost))->all();
    }

    private static function purchaseReturns(array $pids): array
    {
        $factor = self::factorSql('du', 'pu');
        $rows = DB::table('purchase_return_details as d')
            ->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')->where('h.statut', 'completed')
            ->selectRaw("d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.warehouse_id,
                d.quantity * {$factor} as base_qty, ".self::netCostSql('d')." / {$factor} as unit_cost")
            ->get();

        return $rows->map(fn ($r) => self::base((array) $r, 'purchase_return', $r->id, (int) $r->warehouse_id, -(float) $r->base_qty, $r->ref,
            self::at($r->date, $r->time, $r->created_at), (float) $r->unit_cost))->all();
    }

    private static function sales(array $pids): array
    {
        $factor = self::factorSql('du', 'pu', 'd.pack_multiplier');
        $rows = DB::table('sale_details as d')
            ->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.sale_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_sale_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')->where('h.statut', 'completed')
            ->selectRaw("d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.warehouse_id,
                d.quantity * {$factor} as base_qty")
            ->get();

        return $rows->map(fn ($r) => self::base((array) $r, 'sale', $r->id, (int) $r->warehouse_id, -(float) $r->base_qty, $r->ref,
            self::at($r->date, $r->time, $r->created_at)))->all();
    }

    private static function saleReturns(array $pids): array
    {
        $factor = self::factorSql('du', 'pu', 'd.pack_multiplier');
        $rows = DB::table('sale_return_details as d')
            ->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.sale_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_sale_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')->where('h.statut', 'received')
            ->selectRaw("d.id, d.product_id, d.product_variant_id, h.id as ref, h.sale_id, h.date, h.time, h.created_at, h.warehouse_id,
                d.quantity * {$factor} as base_qty")
            ->get();

        // the sale lines each return came from: same sale, same product/variant
        $saleIds = $rows->pluck('sale_id')->filter()->unique()->values()->all();
        $lines = [];
        if ($saleIds !== []) {
            foreach (array_chunk($saleIds, 1000) as $chunk) {
                foreach (DB::table('sale_details')->whereIn('sale_id', $chunk)->whereIn('product_id', $pids)
                    ->get(['id', 'sale_id', 'product_id', 'product_variant_id']) as $l) {
                    $lines[$l->sale_id.':'.$l->product_id.':'.(int) $l->product_variant_id][] = (int) $l->id;
                }
            }
        }

        return $rows->map(fn ($r) => self::base((array) $r, 'sale_return', $r->id, (int) $r->warehouse_id, (float) $r->base_qty, $r->ref,
            self::at($r->date, $r->time, $r->created_at), null,
            ['orig_sources' => $r->sale_id ? ($lines[$r->sale_id.':'.$r->product_id.':'.(int) $r->product_variant_id] ?? []) : []]))->all();
    }

    private static function transfers(array $pids): array
    {
        $factor = self::factorSql('du', 'pu');
        $rows = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')
            ->where(fn ($q) => $q->where('h.approval_status', 'approved')->orWhereNull('h.approval_status'))
            ->whereIn('h.statut', ['completed', 'sent'])
            ->selectRaw("d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.statut,
                h.from_warehouse_id, h.to_warehouse_id, d.quantity * {$factor} as base_qty")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $at = self::at($r->date, $r->time, $r->created_at);
            $out[] = self::base((array) $r, 'transfer_out', $r->id, (int) $r->from_warehouse_id, -(float) $r->base_qty, $r->ref, $at);
            if ($r->statut === 'completed') {
                $out[] = self::base((array) $r, 'transfer_in', $r->id, (int) $r->to_warehouse_id, (float) $r->base_qty, $r->ref, $at, null,
                    ['pair_source_id' => (int) $r->id]);
            }
        }

        return $out;
    }

    private static function adjustments(array $pids): array
    {
        $rows = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('inventory_cost_stamps as s', fn ($j) => $j->on('s.source_id', '=', 'd.id')->where('s.source_type', '=', 'adjustment'))
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')
            ->selectRaw('d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.warehouse_id,
                d.quantity as base_qty, d.type, s.unit_cost as stamp_cost, s.basis as stamp_basis')
            ->get();

        return $rows->map(function ($r) {
            $add = $r->type === 'add';

            return self::base((array) $r, 'adjustment', $r->id, (int) $r->warehouse_id, $add ? (float) $r->base_qty : -(float) $r->base_qty, $r->ref,
                self::at($r->date, $r->time, $r->created_at), $add && $r->stamp_cost !== null ? (float) $r->stamp_cost : null,
                ['estimated' => $add && $r->stamp_basis !== 'manual', 'needs_stamp' => $add && $r->stamp_cost === null]);
        })->all();
    }

    private static function damages(array $pids): array
    {
        $rows = DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->whereIn('d.product_id', $pids)->where('p.type', '!=', 'is_service')
            ->whereNull('h.deleted_at')
            ->selectRaw('d.id, d.product_id, d.product_variant_id, h.id as ref, h.date, h.time, h.created_at, h.warehouse_id, d.quantity as base_qty')
            ->get();

        return $rows->map(fn ($r) => self::base((array) $r, 'damage', $r->id, (int) $r->warehouse_id, -(float) $r->base_qty, $r->ref,
            self::at($r->date, $r->time, $r->created_at)))->all();
    }

    private static function seeds(array $pids): array
    {
        $rows = DB::table('inventory_cost_seeds')->whereIn('product_id', $pids)->get();

        return $rows->map(fn ($r) => self::base((array) $r, 'seed', $r->id, (int) $r->warehouse_id, (float) $r->qty_delta, null,
            (string) $r->occurred_at, $r->unit_cost !== null ? (float) $r->unit_cost : null, ['estimated' => true]))->all();
    }
}
