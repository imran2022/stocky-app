<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Audit fix (Batch 2, findings S9/S10/C3): the "Allow overselling" switch
 * (Settings > Features) is documented as "skip stock checks on all
 * transactions", i.e. when it is OFF the checks must exist - but the server only
 * checked multi-pack lines, so sales, POS, transfers, adjustments and returns
 * could drive stock negative (and two concurrent checkouts both passed).
 *
 * assertAvailable() runs INSIDE the caller's DB transaction. It row-locks the
 * affected product_warehouse rows (in a stable order, so concurrent documents
 * serialise instead of deadlocking) and rejects with a 422 ValidationException
 * when the requested base quantity exceeds what is on hand.
 *
 * Only managed-stock physical products are checked (services, combos and rows
 * with manage_stock = 0 are skipped). $credit lets an edit count the quantity
 * the document being edited already holds (an edit first hands its old lines
 * back, then takes the new ones).
 */
class StockGuard
{
    public static function overSellingAllowed(): bool
    {
        $s = Setting::whereNull('deleted_at')->first();

        return (bool) ($s->allow_overselling ?? false);
    }

    /** Base-unit factor for a unit id (null unit = 1). */
    public static function unitFactor($unitId): float
    {
        $u = $unitId ? Unit::find($unitId) : null;
        if (! $u || (float) $u->operator_value <= 0) {
            return 1.0;
        }

        return $u->operator === '/' ? 1 / (float) $u->operator_value : (float) $u->operator_value;
    }

    /**
     * Build a need entry.
     *
     * @return array{product_id:int, variant_id:?int, qty:float}
     */
    public static function need($productId, $variantId, $qty, $unitId = null, $packMultiplier = 1, ?string $fallbackRelation = null): array
    {
        $pm = (float) $packMultiplier;
        if (! $unitId && $fallbackRelation) {
            $unitId = optional(optional(Product::with($fallbackRelation)->find($productId))->{$fallbackRelation})->id;
        }

        return [
            'product_id' => (int) $productId,
            'variant_id' => $variantId ? (int) $variantId : null,
            'qty' => (float) $qty * self::unitFactor($unitId) * ($pm > 0 ? $pm : 1),
        ];
    }

    /**
     * Needs from request lines (skips service lines). $unitKey is the line's unit
     * field, $fallbackRelation the Product relation used when it is empty.
     */
    public static function needsFromLines(array $lines, string $unitKey, string $fallbackRelation): array
    {
        $out = [];
        foreach ($lines as $l) {
            // Audit fix (MF-05): product_type used to come straight from the request line — a physical product
            // described as a service would never even enter the "needs" list, so assertAvailable() (which DOES
            // resolve type from the database) never got a chance to check it at all. The database is the only
            // authority on a product's type now.
            $product = isset($l['product_id']) ? Product::find($l['product_id']) : null;
            if ($product && $product->type === 'is_service') {
                continue;
            }
            $out[] = self::need(
                $l['product_id'] ?? 0, $l['product_variant_id'] ?? null, $l['quantity'] ?? 0,
                $l[$unitKey] ?? null, $l['pack_multiplier'] ?? 1, $fallbackRelation
            );
        }

        return $out;
    }

    /** Needs of the subtracting lines of an adjustment. */
    public static function adjustmentNeeds(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (($l['type'] ?? 'add') !== 'add') {
                $out[] = self::need($l['product_id'] ?? 0, $l['product_variant_id'] ?? null, $l['quantity'] ?? 0);
            }
        }

        return $out;
    }

    /** What an adjustment being edited already holds: subtracts are handed back, adds are taken back. */
    public static function adjustmentHeld(array $oldLines): array
    {
        $out = [];
        foreach ($oldLines as $l) {
            $n = self::need($l['product_id'] ?? 0, $l['product_variant_id'] ?? null, $l['quantity'] ?? 0);
            if (($l['type'] ?? 'add') === 'add') {
                $n['qty'] = -$n['qty'];
            }
            $out[] = $n;
        }

        return $out;
    }

    /**
     * @param  array  $needs   list of need() entries removed from the warehouse
     * @param  array  $credit  list of need() entries the edited document already holds
     *
     * @throws ValidationException
     */
    public static function assertAvailable($warehouseId, array $needs, array $credit = []): void
    {
        if (self::overSellingAllowed()) {
            return;
        }

        $sum = function (array $rows): array {
            $out = [];
            foreach ($rows as $r) {
                $k = $r['product_id'].'|'.(int) $r['variant_id'];
                $out[$k] = ($out[$k] ?? 0) + $r['qty'];
            }

            return $out;
        };
        $need = $sum($needs);
        $held = $sum($credit);
        ksort($need);

        $errors = [];
        foreach ($need as $k => $qty) {
            if ($qty <= 0) {
                continue;
            }
            [$pid, $vid] = array_map('intval', explode('|', $k));
            $product = Product::find($pid);
            if (! $product || in_array($product->type, ['is_service', 'is_combo', 'is_digital'], true)) {
                continue; // services, combos, digital: no on-hand stock
            }
            $q = DB::table('product_warehouse')->whereNull('deleted_at')
                ->where('warehouse_id', $warehouseId)->where('product_id', $pid);
            $vid ? $q->where('product_variant_id', $vid) : $q->whereNull('product_variant_id');
            $row = $q->lockForUpdate()->first();
            if ($row && ! $row->manage_stock) {
                continue;
            }
            $available = (float) ($row->qte ?? 0) + ($held[$k] ?? 0);
            if ($qty > $available + 1e-6) {
                $errors[] = sprintf(
                    '%s: needs %s but only %s in stock.',
                    $product->name ?? ('#'.$pid),
                    rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format(max(0, $available), 3, '.', ''), '0'), '.')
                );
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['stock' => $errors]);
        }
    }
}
