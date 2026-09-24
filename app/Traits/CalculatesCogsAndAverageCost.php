<?php

namespace App\Traits;

use App\Models\AdjustmentDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseDetail;
use App\Models\SaleDetail;
use App\Models\ServiceJob;
use App\Models\ServiceJobItem;
use App\Support\UnitQuantityResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * Shared FIFO / Average-cost helpers for profit & COGS calculations.
 *
 * Used by both legacy reports and dashboard.
 */
trait CalculatesCogsAndAverageCost
{
    /**
     * Base-unit quantity SQL for a sale / sale-return line (unit operator x pack multiplier).
     * The query must left-join `units` as $unitAlias on the line's sale_unit_id.
     */
    protected function cogsBaseQty(string $detailAlias, string $unitAlias): string
    {
        return UnitQuantityResolver::baseQuantityExpression(
            "{$detailAlias}.quantity", "{$detailAlias}.pack_multiplier", $unitAlias
        );
    }

    /**
     * Base units per one purchase unit (1 when the line has no / an unknown unit).
     */
    protected function cogsPurchaseFactor(string $unitAlias): string
    {
        return UnitQuantityResolver::baseQuantityExpression('1', '1', $unitAlias);
    }

    /**
     * Completed sale lines (base-unit quantity per product/variant) inside a date window.
     */
    protected function cogsSoldQty(?string $from, ?string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords)
    {
        $q = SaleDetail::join('sales as s', 's.id', '=', 'sale_details.sale_id')
            ->leftJoin('units as su', 'su.id', '=', 'sale_details.sale_unit_id')
            ->where('s.statut', 'completed')
            ->when($warehouseId, fn ($w) => $w->where('s.warehouse_id', $warehouseId),
                fn ($w) => $w->whereIn('s.warehouse_id', $warehouseIds))
            ->when(! $viewRecords, fn ($w) => $w->where('s.user_id', '=', Auth::user()->id));
        if ($from !== null) {
            $q->where('sale_details.date', '>=', $from);
        }
        if ($to !== null) {
            $q->where('sale_details.date', $from === null ? '<' : '<=', $to);
        }

        return $q->select('sale_details.product_id', 'sale_details.product_variant_id',
            DB::raw('SUM('.$this->cogsBaseQty('sale_details', 'su').') as qty'))
            ->groupBy('sale_details.product_id', 'sale_details.product_variant_id')
            ->get();
    }

    /**
     * Received sale-return lines (base-unit quantity per product/variant) inside a date window.
     * A return puts goods back on the shelf, so it reduces the quantity whose cost was expensed.
     */
    protected function cogsReturnedQty(?string $from, ?string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords)
    {
        $q = DB::table('sale_return_details as rd')
            ->join('sale_returns as sr', 'sr.id', '=', 'rd.sale_return_id')
            ->leftJoin('units as ru', 'ru.id', '=', 'rd.sale_unit_id')
            ->where('sr.statut', 'received')
            ->whereNull('sr.deleted_at')
            ->when($warehouseId, fn ($w) => $w->where('sr.warehouse_id', $warehouseId),
                fn ($w) => $w->whereIn('sr.warehouse_id', $warehouseIds))
            ->when(! $viewRecords, fn ($w) => $w->where('sr.user_id', '=', Auth::user()->id));
        if ($from !== null) {
            $q->where('sr.date', '>=', $from);
        }
        if ($to !== null) {
            $q->where('sr.date', $from === null ? '<' : '<=', $to);
        }

        return $q->select('rd.product_id', 'rd.product_variant_id',
            DB::raw('SUM('.$this->cogsBaseQty('rd', 'ru').') as qty'))
            ->groupBy('rd.product_id', 'rd.product_variant_id')
            ->get();
    }

    /**
     * Fast COGS using:
     *  - FIFO: purchases grouped once + pointer burn-up to start date
     *  - AVG: set-based average cost per product/variant at end date
     *
     * Every quantity is in the product's base unit: sale lines are converted by their unit and
     * pack multiplier, purchase layers by their purchase unit (cost per base unit = cost / factor).
     * Received sale returns are netted off the quantity sold (net cost of goods actually consumed).
     */
    protected function calcCogsAndAvgCostFast(string $start, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        // Respect per-user record_view permissions (same pattern as DashboardController 504–506)
        $user = Auth::user();
        $view_records = $user ? $user->hasRecordView() : false;

        // Moving-average costing ON: COGS is the per-line cost stored in the ledger, not a recomputation from the
        // master cost or purchase layers. FIFO and AVG are the same number then (one method, one profit).
        if (\App\Services\Costing\CostingReader::active()) {
            $stored = \App\Services\Costing\CostingReader::cogsForWindow($start, $end, $warehouseId, $warehouseIds, $view_records, $user?->id);

            return ['fifo' => $stored, 'avg' => $stored];
        }

        $key = fn ($pid, $vid) => $pid.':'.($vid ?? 'null');

        $salesQty = $this->cogsSoldQty($start, $end, $warehouseId, $warehouseIds, $view_records)
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
        $returnQty = $this->cogsReturnedQty($start, $end, $warehouseId, $warehouseIds, $view_records)
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Keys touched in the period (sold or returned)
        $soldKeys = collect();
        foreach ($salesQty->concat($returnQty) as $r) {
            $soldKeys[$key($r->product_id, $r->product_variant_id)] = (object) [
                'product_id' => $r->product_id, 'product_variant_id' => $r->product_variant_id,
            ];
        }
        $soldKeys = $soldKeys->values();

        if ($soldKeys->isEmpty()) {
            return ['fifo' => 0.0, 'avg' => 0.0];
        }

        $productIds = $soldKeys->pluck('product_id')->unique()->values();
        $variantIds = $soldKeys->pluck('product_variant_id')->unique()->filter()->values();

        // Preload product.cost once for safe fallbacks when there are no purchases/adjustments
        $productCosts = Product::whereIn('id', $productIds)
            ->pluck('cost', 'id');

        // Variant products store their cost on the variant (products.cost is 0 for them)
        $variantCosts = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->pluck('cost', 'id');

        // Net quantity before start (to burn FIFO layers)
        $salesBefore = $this->cogsSoldQty(null, $start, $warehouseId, $warehouseIds, $view_records)
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
        $returnBefore = $this->cogsReturnedQty(null, $start, $warehouseId, $warehouseIds, $view_records)
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Purchases (all time) as base-unit layers ordered oldest first (for FIFO)
        $factor = $this->cogsPurchaseFactor('pu');
        $purchases = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'purchase_details.purchase_unit_id')
            ->where('p.statut', 'received')
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('p.warehouse_id', $warehouseIds))
            ->whereIn('purchase_details.product_id', $productIds)
            ->select([
                'purchase_details.product_id',
                'purchase_details.product_variant_id',
                DB::raw("purchase_details.quantity * {$factor} as quantity"),
                DB::raw("purchase_details.cost / {$factor} as cost"),
                'p.date',
            ])
            ->orderBy('p.date', 'asc')
            ->orderBy('purchase_details.id', 'asc')
            ->get()
            ->groupBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Average cost per key at end date (set-based)
        $avgCost = $this->averageCostBulk($productIds->all(), $variantIds->all(), $end, $warehouseId, $warehouseIds);

        $totalFifo = 0.0;
        $totalAvg = 0.0;

        foreach ($soldKeys as $k) {
            $kstr = $key($k->product_id, $k->product_variant_id);
            // Net base units consumed in the period (negative when returns exceed sales)
            $qtyNet = (float) ($salesQty[$kstr]->qty ?? 0) - (float) ($returnQty[$kstr]->qty ?? 0);
            if (abs($qtyNet) < 1e-9) {
                continue;
            }

            // ---- AVG ----
            $avg = (float) ($avgCost[$kstr] ?? 0);

            // If there is no cost from purchases/adjustments, safely fall back to
            // the variant cost (variant products keep cost on the variant), then product.cost
            if ($avg <= 0) {
                $fallbackCost = 0.0;
                if ($k->product_variant_id && isset($variantCosts[$k->product_variant_id])) {
                    $fallbackCost = (float) $variantCosts[$k->product_variant_id];
                }
                if ($fallbackCost <= 0 && isset($productCosts[$k->product_id])) {
                    $fallbackCost = (float) $productCosts[$k->product_id];
                }
                if ($fallbackCost > 0) {
                    $avg = $fallbackCost;
                }
            }

            $totalAvg += $avg * $qtyNet;

            // Returns exceeding sales in the period have no layer to burn: value them at average
            if ($qtyNet < 0) {
                $totalFifo += $avg * $qtyNet;

                continue;
            }

            // ---- FIFO ----
            $layers = ($purchases[$kstr] ?? collect())->values(); // list of {quantity, cost}
            if ($layers->isEmpty()) {
                // no purchases -> fallback to average (which may already include product.cost fallback)
                $totalFifo += $avg * $qtyNet;

                continue;
            }

            // burn layers for net sales before start
            $burn = max(0.0, (float) ($salesBefore[$kstr]->qty ?? 0) - (float) ($returnBefore[$kstr]->qty ?? 0));
            $i = 0;
            while ($burn > 0 && $i < $layers->count()) {
                $q = (float) $layers[$i]->quantity;
                if ($q <= 0) {
                    $i++;

                    continue;
                }
                $consume = min($q, $burn);
                $layers[$i]->quantity = $q - $consume;
                $burn -= $consume;
                if ($layers[$i]->quantity <= 0) {
                    $i++;
                }
            }

            // now cost the period's net sales
            $remain = $qtyNet;
            while ($remain > 0) {
                if ($i >= $layers->count()) {
                    // ran out of layers -> fallback to avg (which may already include product.cost fallback) for the rest
                    $totalFifo += $avg * $remain;
                    $remain = 0;
                    break;
                }
                $q = max(0.0, (float) $layers[$i]->quantity);
                $c = (float) $layers[$i]->cost;
                if ($q <= 0) {
                    $i++;

                    continue;
                }

                $take = min($q, $remain);
                $totalFifo += $take * $c;
                $layers[$i]->quantity = $q - $take;
                $remain -= $take;
                if ($layers[$i]->quantity <= 0) {
                    $i++;
                }
            }
        }

        return ['fifo' => $totalFifo, 'avg' => $totalAvg];
    }

    /**
     * Revenue and parts cost from service / repair jobs delivered in the period.
     *
     * Delivery is the point of realisation for a repair job: it is where the parts
     * leave stock and where their cost is snapshotted onto the line, mirroring the way
     * a sale is realised when it is marked completed.
     *
     * @return array{revenue: float, parts_cost: float, profit: float, count: int}
     */
    protected function serviceJobTotals(string $start, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->endOfDay();

        // Jobs with no warehouse (labour-only, or created before warehouses were tracked
        // on the job) belong to no single warehouse, so they only surface unfiltered.
        $scopeWarehouse = function ($q, string $column) use ($warehouseId, $warehouseIds) {
            return $warehouseId
                ? $q->where($column, $warehouseId)
                : $q->where(fn ($w) => $w->whereIn($column, $warehouseIds)->orWhereNull($column));
        };

        $jobs = ServiceJob::whereNull('deleted_at')
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$from, $to]);
        $scopeWarehouse($jobs, 'warehouse_id');

        $revenue = (float) (clone $jobs)->sum('total_amount');
        $count = (int) (clone $jobs)->count();

        $parts = ServiceJobItem::join('service_jobs as sj', 'sj.id', '=', 'service_job_items.service_job_id')
            ->whereNull('service_job_items.deleted_at')
            ->whereNull('sj.deleted_at')
            ->where('sj.status', 'delivered')
            ->where('service_job_items.type', 'part')
            ->whereBetween('sj.delivered_at', [$from, $to]);
        $scopeWarehouse($parts, 'sj.warehouse_id');

        $partsCost = (float) $parts->sum(
            DB::raw('service_job_items.cost * service_job_items.quantity')
        );

        return [
            'revenue' => $revenue,
            'parts_cost' => $partsCost,
            'profit' => $revenue - $partsCost,
            'count' => $count,
        ];
    }

    /**
     * Set-based average cost by (product,variant) at end date.
     * AVG = (Σ purchases qty*cost + Σ adjustments(±qty)*product.cost) / (Σ purchases qty + Σ adjustments qty)
     */
    protected function averageCostBulk(array $productIds, array $variantIds, string $end, ?int $warehouseId, array $warehouseIds): array
    {
        $key = fn ($pid, $vid) => $pid.':'.($vid ?? 'null');

        // Purchases up to end
        $factor = $this->cogsPurchaseFactor('pu');
        $pIn = PurchaseDetail::join('purchases as p', 'p.id', '=', 'purchase_details.purchase_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'purchase_details.purchase_unit_id')
            ->where('p.statut', 'received')
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('p.warehouse_id', $warehouseIds))
            ->whereIn('purchase_details.product_id', $productIds)
            ->where('p.date', '<=', $end)
            ->select(
                'purchase_details.product_id',
                'purchase_details.product_variant_id',
                DB::raw("SUM(purchase_details.quantity * {$factor}) as qty"),
                DB::raw('SUM(purchase_details.quantity * purchase_details.cost) as cost')
            )
            ->groupBy('purchase_details.product_id', 'purchase_details.product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Adjustments up to end (valued at product.cost)
        $adj = AdjustmentDetail::join('adjustments as a', 'a.id', '=', 'adjustment_details.adjustment_id')
            ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId),
                fn ($q) => $q->whereIn('a.warehouse_id', $warehouseIds))
            ->whereIn('adjustment_details.product_id', $productIds)
            ->where('a.date', '<=', $end)
            ->leftJoin('products as pr', 'pr.id', '=', 'adjustment_details.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'adjustment_details.product_variant_id')
            ->select(
                'adjustment_details.product_id',
                'adjustment_details.product_variant_id',
                DB::raw("SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) as qty"),
                DB::raw("SUM(CASE WHEN adjustment_details.type='add' THEN adjustment_details.quantity ELSE -adjustment_details.quantity END) * COALESCE(NULLIF(pv.cost,0), pr.cost, 0) as cost")
            )
            ->groupBy('adjustment_details.product_id', 'adjustment_details.product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));

        // Build map avg cost per key
        $avg = [];
        // unify keys from purchases/adjustments
        $keys = collect(array_unique(array_merge($pIn->keys()->all(), $adj->keys()->all())));
        foreach ($keys as $kstr) {
            $pq = (float) ($pIn[$kstr]->qty ?? 0);
            $pc = (float) ($pIn[$kstr]->cost ?? 0);
            $aq = (float) ($adj[$kstr]->qty ?? 0);
            $ac = (float) ($adj[$kstr]->cost ?? 0);

            $qty = $pq + $aq;
            $cost = $pc + $ac;
            $avg[$kstr] = $qty > 0 ? ($cost / $qty) : 0.0;
        }

        return $avg;
    }
}











