<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Batch 1, finding S1): a sale return used to be accepted for any
 * quantity, any product and any price - the same sold line could be returned
 * five times, a product that was never on the sale could be "returned" at an
 * arbitrary price, and every received return added stock and credited the
 * customer. This class enforces, inside the caller's transaction:
 *
 *  - the return belongs to a live, completed sale of the same customer;
 *  - every line is a product/variant that was actually on that sale;
 *  - cumulative returned quantity (all other non-deleted returns of that sale
 *    + this one), compared in base units, never exceeds the quantity sold;
 *  - a line is refunded at no more than the amount actually charged per unit
 *    on the sale (prorated line total, i.e. after that line's discount/tax);
 *  - the header GrandTotal is consistent with the lines, discount, tax_rate
 *    and shipping (SaleTotalsVerifier).
 *
 * Throws \InvalidArgumentException; callers turn it into a 422.
 */
class SaleReturnLimits
{
    private const QTY_EPS = 0.0005;

    /**
     * @param  int|string|null  $saleId
     * @param  int|null  $excludeReturnId  the return being edited (its old quantities do not count)
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(
        $saleId,
        $clientId,
        array $details,
        $shipping,
        $discount,
        $submittedGrandTotal,
        $submittedTaxNet,
        $taxRate,
        ?int $excludeReturnId = null
    ): void {
        if (empty($saleId)) {
            throw new \InvalidArgumentException('A sale return must reference the sale it returns.');
        }

        $sale = Sale::whereNull('deleted_at')->lockForUpdate()->find($saleId);
        if (! $sale) {
            throw new \InvalidArgumentException('The sale being returned does not exist or was deleted.');
        }
        if ($sale->statut !== 'completed') {
            throw new \InvalidArgumentException('Only a completed sale can be returned.');
        }
        if (! empty($clientId) && (int) $clientId !== (int) $sale->client_id) {
            throw new \InvalidArgumentException('The customer does not match the sale being returned.');
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

        // What was sold: base quantity, amount charged, per key.
        $sold = [];
        foreach (DB::table('sale_details')->where('sale_id', $sale->id)->get() as $d) {
            $k = $key($d->product_id, $d->product_variant_id);
            $base = (float) $d->quantity * $factor($d->sale_unit_id, $d->pack_multiplier ?? 1);
            $sold[$k]['base'] = ($sold[$k]['base'] ?? 0) + $base;
            $sold[$k]['amount'] = ($sold[$k]['amount'] ?? 0) + (float) $d->total;
        }

        // What was already returned by other returns of this sale.
        $returned = [];
        $q = DB::table('sale_return_details as rd')
            ->join('sale_returns as r', 'r.id', '=', 'rd.sale_return_id')
            ->where('r.sale_id', $sale->id)
            ->whereNull('r.deleted_at');
        if ($excludeReturnId) {
            $q->where('r.id', '!=', $excludeReturnId);
        }
        foreach ($q->get(['rd.product_id', 'rd.product_variant_id', 'rd.quantity', 'rd.sale_unit_id', 'rd.pack_multiplier']) as $d) {
            $k = $key($d->product_id, $d->product_variant_id);
            $returned[$k] = ($returned[$k] ?? 0) + (float) $d->quantity * $factor($d->sale_unit_id, $d->pack_multiplier ?? 1);
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
                throw new \InvalidArgumentException('A returned product was not on the sale.');
            }
            $base = $qty * $factor($line['sale_unit_id'] ?? null, $line['pack_multiplier'] ?? 1);
            $now[$k] = ($now[$k] ?? 0) + $base;
            $available = $sold[$k]['base'] - ($returned[$k] ?? 0);
            if ($now[$k] > $available + self::QTY_EPS) {
                throw new \InvalidArgumentException(sprintf(
                    'Return quantity exceeds what can still be returned for this product (sold %.4f, already returned %.4f, this return %.4f).',
                    $sold[$k]['base'], $returned[$k] ?? 0, $now[$k]
                ));
            }
            // Refund per base unit may not exceed what was charged per base unit.
            $maxLine = $sold[$k]['base'] > 0 ? $sold[$k]['amount'] / $sold[$k]['base'] * $base : 0;
            $sub = (float) ($line['subtotal'] ?? 0);
            if ($sub > $maxLine + max(0.03, $maxLine * 0.001)) {
                throw new \InvalidArgumentException(sprintf(
                    'Return amount %.4f is more than was charged on the sale for that quantity (%.4f).', $sub, $maxLine
                ));
            }
            $lineSum += $sub;
        }

        // Header: strip per-line tax fields (a return line's tax is prorated from
        // the sale, already covered above) and check discount/tax_rate/shipping/total.
        SaleTotalsVerifier::verify(
            array_map(fn ($l) => ['subtotal' => $l['subtotal'] ?? 0], $details),
            $shipping, $discount, '2', $submittedGrandTotal, $submittedTaxNet, $taxRate
        );
    }
}
