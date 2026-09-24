<?php

namespace App\Support;

use App\Models\Purchase;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Batch 2, finding C3): a purchase return used to be accepted for any
 * quantity and product - the same purchased line could be returned repeatedly,
 * driving stock negative and creating a supplier credit that was never earned.
 * This class enforces, inside the caller's transaction:
 *
 *  - the return belongs to a live, received purchase from the same supplier;
 *  - every line is a product/variant that was actually on that purchase;
 *  - cumulative returned quantity (all other non-deleted returns of that
 *    purchase + this one), compared in base units, never exceeds the quantity
 *    purchased;
 *  - a line is credited at no more than the amount actually paid per unit;
 *  - the header GrandTotal is consistent with the lines, discount, tax_rate and
 *    shipping (SaleTotalsVerifier).
 *
 * Throws \InvalidArgumentException; callers turn it into a 422.
 */
class PurchaseReturnLimits
{
    private const QTY_EPS = 0.0005;

    /**
     * @param  int|string|null  $purchaseId
     * @param  int|null  $excludeReturnId  the return being edited (its old quantities do not count)
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(
        $purchaseId,
        $supplierId,
        array $details,
        $shipping,
        $discount,
        $submittedGrandTotal,
        $submittedTaxNet,
        $taxRate,
        ?int $excludeReturnId = null
    ): void {
        if (empty($purchaseId)) {
            throw new \InvalidArgumentException('A purchase return must reference the purchase it returns.');
        }

        $purchase = Purchase::whereNull('deleted_at')->lockForUpdate()->find($purchaseId);
        if (! $purchase) {
            throw new \InvalidArgumentException('The purchase being returned does not exist or was deleted.');
        }
        if ($purchase->statut !== 'received') {
            throw new \InvalidArgumentException('Only a received purchase can be returned.');
        }
        if (! empty($supplierId) && (int) $supplierId !== (int) $purchase->provider_id) {
            throw new \InvalidArgumentException('The supplier does not match the purchase being returned.');
        }

        $units = Unit::all()->keyBy('id');
        $factor = function ($unitId, $packMultiplier) use ($units) {
            $f = 1.0;
            $u = $unitId ? $units->get($unitId) : null;
            if ($u && (float) $u->operator_value > 0) {
                $f = $u->operator === '/' ? 1 / (float) $u->operator_value : (float) $u->operator_value;
            }
            $pm = (float) $packMultiplier;

            return $f * ($pm > 0 ? $pm : 1);
        };
        $key = fn ($pid, $vid) => (int) $pid.'|'.(int) $vid;

        // What was bought: base quantity, amount paid, per key.
        $sold = [];
        foreach (DB::table('purchase_details')->where('purchase_id', $purchase->id)->get() as $d) {
            $k = $key($d->product_id, $d->product_variant_id);
            $base = (float) $d->quantity * $factor($d->purchase_unit_id, 1);
            $sold[$k]['base'] = ($sold[$k]['base'] ?? 0) + $base;
            $sold[$k]['amount'] = ($sold[$k]['amount'] ?? 0) + (float) $d->total;
        }

        // What was already returned by other returns of this purchase.
        $returned = [];
        $q = DB::table('purchase_return_details as rd')
            ->join('purchase_returns as r', 'r.id', '=', 'rd.purchase_return_id')
            ->where('r.purchase_id', $purchase->id)
            ->whereNull('r.deleted_at');
        if ($excludeReturnId) {
            $q->where('r.id', '!=', $excludeReturnId);
        }
        foreach ($q->get(['rd.product_id', 'rd.product_variant_id', 'rd.quantity', 'rd.purchase_unit_id']) as $d) {
            $k = $key($d->product_id, $d->product_variant_id);
            $returned[$k] = ($returned[$k] ?? 0) + (float) $d->quantity * $factor($d->purchase_unit_id, 1);
        }

        $lineSum = 0.0;
        $now = [];
        foreach ($details as $line) {
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty < 0) {
                throw new \InvalidArgumentException('Return quantity must not be negative.');
            }
            if ($qty == 0) {
                $lineSum += (float) ($line['subtotal'] ?? 0);

                continue;
            }
            $k = $key($line['product_id'] ?? 0, $line['product_variant_id'] ?? null);
            if (! isset($sold[$k])) {
                throw new \InvalidArgumentException('A returned product was not on the purchase.');
            }
            $base = $qty * $factor($line['purchase_unit_id'] ?? null, $line['pack_multiplier'] ?? 1);
            $now[$k] = ($now[$k] ?? 0) + $base;
            $available = $sold[$k]['base'] - ($returned[$k] ?? 0);
            if ($now[$k] > $available + self::QTY_EPS) {
                throw new \InvalidArgumentException(sprintf(
                    'Return quantity exceeds what can still be returned for this product (bought %.4f, already returned %.4f, this return %.4f).',
                    $sold[$k]['base'], $returned[$k] ?? 0, $now[$k]
                ));
            }
            // Credit per base unit may not exceed what was paid per base unit.
            $maxLine = $sold[$k]['base'] > 0 ? $sold[$k]['amount'] / $sold[$k]['base'] * $base : 0;
            $sub = (float) ($line['subtotal'] ?? 0);
            if ($sub > $maxLine + max(0.03, $maxLine * 0.001)) {
                throw new \InvalidArgumentException(sprintf(
                    'Return amount %.4f is more than was paid on the purchase for that quantity (%.4f).', $sub, $maxLine
                ));
            }
            $lineSum += $sub;
        }

        // Header: strip per-line tax fields (a return line's tax is prorated from
        // the purchase, already covered above) and check discount/tax_rate/shipping/total.
        SaleTotalsVerifier::verify(
            array_map(fn ($l) => ['subtotal' => $l['subtotal'] ?? 0], $details),
            $shipping, $discount, '2', $submittedGrandTotal, $submittedTaxNet, $taxRate
        );
    }
}
