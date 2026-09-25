<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SaleDetail;
use App\Models\Unit;

/**
 * Audit fix (Profit Report correction phase, commit 5): Sale Return pack/unit integrity.
 *
 * Before this fix, SalesReturnController::store()/update()/destroy() each carried their own copy
 * of the same "resolve a unit, apply a pack multiplier, mutate stock" logic — and all three copies
 * shared the same bug: when a sale line had no sale_unit_id, the code resolved the product's
 * default sale unit into $unit and then immediately discarded it with an unconditional
 * `$unit = null;`. Two further problems compounded this:
 *
 *  - create_sell_return() (the endpoint that pre-fills the return form) never sent back the
 *    original sale line's product_pack_id/pack_multiplier/pack_name at all, so the frontend's own
 *    `d.pack_multiplier ?? 1` fallback silently turned a "2 packs of 6" sale into a "return of 1
 *    base unit" the moment it was returned — understating both the restocked quantity and the
 *    Legacy Profit Report's COGS reversal by a full pack's worth every time.
 *  - store()/update() then trusted whatever pack_multiplier/sale_unit_id the browser submitted for
 *    the CURRENT return, instead of deriving it from the original sale line — so even a corrected
 *    frontend could not be trusted to get this right server-side.
 *
 * This class is the ONE place that now resolves a return line's pack/unit, computes its signed
 * base-unit quantity, and mutates stock — used identically by store(), update() and destroy(), so
 * there is exactly one implementation to get right instead of three to keep in sync.
 */
class SaleReturnStock
{
    /**
     * Resolve the effective Unit for a return line. Unlike the old code, this never discards a
     * legitimate fallback: when $saleUnitId is null, the product's own default sale unit is
     * resolved and RETURNED (not thrown away). Returns null only when nothing can be resolved at
     * all (no explicit unit and the product has no default sale unit either — e.g. a service).
     */
    public static function resolveUnit(?int $saleUnitId, int $productId): ?Unit
    {
        if ($saleUnitId !== null) {
            return Unit::find($saleUnitId);
        }

        $product = Product::with('unitSale')->find($productId);

        return ($product && $product->unitSale) ? Unit::find($product->unitSale->id) : null;
    }

    /**
     * Server-side pack/unit snapshot for a return line, derived from the ORIGINAL sale detail —
     * looked up by (sale_id, product_id, product_variant_id), the same key
     * SalesReturnController::edit_sell_return() already uses to find it. Never trusts the
     * browser-submitted sale_unit_id/pack_multiplier/product_pack_id/pack_name for a return line;
     * those are always overwritten with what is returned here.
     *
     * A product of type 'is_service' is not stocked and legitimately has no sale unit — for those,
     * 'unit' comes back null and the caller skips stock mutation, but the line itself is still
     * valid and must not be rejected.
     *
     * @return array{sale_unit_id:?int, unit:?Unit, product_pack_id:?int, pack_multiplier:float, pack_name:?string, is_service:bool}
     *
     * @throws \InvalidArgumentException when no matching sale line exists, or a non-service
     *                                    product has no unit that can be resolved for it.
     */
    public static function deriveSnapshot(int $saleId, int $productId, ?int $variantId): array
    {
        $saleDetail = SaleDetail::where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if (! $saleDetail) {
            throw new \InvalidArgumentException('This product/variant was not found on the sale being returned.');
        }

        $product = Product::find($productId);
        $isService = $product && $product->type === 'is_service';

        $unit = self::resolveUnit($saleDetail->sale_unit_id, $productId);

        if (! $unit && ! $isService) {
            throw new \InvalidArgumentException(
                'No sale unit could be resolved for this product; the return cannot be processed.'
            );
        }

        $packMultiplier = (float) ($saleDetail->pack_multiplier ?? 0) > 0 ? (float) $saleDetail->pack_multiplier : 1.0;

        return [
            'sale_unit_id' => $unit?->id,
            'unit' => $unit,
            'product_pack_id' => $saleDetail->product_pack_id,
            'pack_multiplier' => $packMultiplier,
            'pack_name' => $saleDetail->pack_name,
            'is_service' => $isService,
        ];
    }

    /**
     * Signed base-unit quantity for one return line (positive = base units to restock).
     * $unit may be null for a service line (or genuinely un-resolvable legacy data being reversed
     * on delete/update) — such a line contributes no stock movement.
     */
    public static function baseQuantity(float $quantity, float $packMultiplier, ?Unit $unit): float
    {
        $packQty = $quantity * ($packMultiplier > 0 ? $packMultiplier : 1.0);

        if (! $unit || (float) $unit->operator_value <= 0) {
            return 0.0;
        }

        return $unit->operator === '/' ? $packQty / $unit->operator_value : $packQty * $unit->operator_value;
    }

    /**
     * Apply a signed base-unit delta to warehouse stock. Locked (StockMutator::lockOrCreate), so
     * this always finds-or-creates the row instead of silently no-op'ing when none exists yet, and
     * always serializes against a concurrent mutation of the same row.
     */
    public static function applyStock(int $warehouseId, int $productId, ?int $variantId, float $baseQtyDelta): void
    {
        if ($baseQtyDelta == 0.0) {
            return;
        }

        $row = StockMutator::lockOrCreate($warehouseId, $productId, $variantId);
        $row->qte += $baseQtyDelta;
        $row->save();
    }
}
