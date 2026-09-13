<?php

namespace App\Services\Custom;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\product_warehouse;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Pre-flight safety check for deleting a GRN (Purchase). A received GRN
 * added stock; if some of that stock has since been sold/moved out, naively
 * reversing the addition on delete would push the product's warehouse
 * quantity negative — a real, reported operational risk (see this feature's
 * design discussion: deleting a GRN after its stock has partly sold should
 * not silently create phantom negative stock).
 *
 * Deliberately read-only and called BEFORE any mutation in
 * PurchasesController::destroy()/delete_by_selection() — a blocked delete
 * must leave stock, PO linkage, payments, and the GRN itself completely
 * untouched, not partially reverse things and then bail out partway.
 *
 * Mirrors the exact base-unit conversion already used by the stock-reversal
 * loop directly below this check's call site, so the "would this go
 * negative" arithmetic matches the "how much do we actually subtract"
 * arithmetic precisely — this check and that loop must never disagree.
 */
class GrnDeletionSafetyService
{
    /**
     * @param  Purchase  $purchase  The GRN being considered for deletion.
     * @param  Collection<int, \App\Models\PurchaseDetail>  $details  Its
     *     persisted detail rows (same collection the caller already
     *     fetched for its own stock-reversal loop).
     * @return string[] Human-readable problem descriptions, one per
     *     affected product/variant line. Empty array means safe to delete.
     */
    public function checkSafeToDelete(Purchase $purchase, Collection $details): array
    {
        // A GRN that was never 'received' never added stock in the first
        // place (mirrors the exact condition the stock-reversal loop
        // itself checks) — nothing to protect against.
        if ($purchase->statut !== 'received') {
            return [];
        }

        $problems = [];

        foreach ($details as $detail) {
            $unit = $this->resolveUnit($detail);
            $baseQty = $this->toBaseQuantity((float) $detail->quantity, $unit);

            $stockQuery = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $purchase->warehouse_id)
                ->where('product_id', $detail->product_id);

            if ($detail->product_variant_id !== null) {
                $stockQuery->where('product_variant_id', $detail->product_variant_id);
            } else {
                $stockQuery->whereNull('product_variant_id');
            }

            $productWarehouse = $stockQuery->first();
            $currentStock = $productWarehouse ? (float) $productWarehouse->qte : 0.0;
            $resultingStock = $currentStock - $baseQty;

            // Small epsilon guards against float-precision false positives
            // (e.g. 19.999999999 due to prior unit-conversion rounding)
            // landing a hair below zero when it's really exactly zero.
            if ($resultingStock < -0.0001) {
                $productName = optional(Product::find($detail->product_id))->name ?? "Product #{$detail->product_id}";
                $problems[] = sprintf(
                    "'%s' — deleting this GRN would make stock %s (currently %s, this GRN contributed %s). Sell-through since receiving means this can't be safely reversed automatically.",
                    $productName,
                    number_format($resultingStock, 2),
                    number_format($currentStock, 2),
                    number_format($baseQty, 2)
                );
            }
        }

        return $problems;
    }

    /**
     * Identical resolution rule to the existing stock-reversal loop: a
     * line-level purchase_unit_id if present, otherwise the product's own
     * default purchase unit.
     */
    private function resolveUnit($detail): ?Unit
    {
        if ($detail->purchase_unit_id !== null) {
            return Unit::find($detail->purchase_unit_id);
        }

        $product = Product::with('unitPurchase')->find($detail->product_id);

        return $product && $product->unitPurchase ? Unit::find($product->unitPurchase->id) : null;
    }

    /** Identical conversion rule used throughout this codebase's stock math. */
    private function toBaseQuantity(float $quantity, ?Unit $unit): float
    {
        if (! $unit) {
            return $quantity;
        }

        return $unit->operator === '/'
            ? $quantity / ($unit->operator_value ?: 1)
            : $quantity * ($unit->operator_value ?: 1);
    }
}
