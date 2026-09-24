<?php

namespace App\Support;

/**
 * Audit fix (Batch 1, findings S2/S3): server-side recomputation of a sale's
 * money fields from the submitted quantity / price / discount / tax_rate,
 * instead of trusting client-supplied TaxNet and GrandTotal.
 *
 * Complements (does not replace) SaleTotalsGuard, whose per-line range checks
 * still run first. Formulas mirror resources/src/lib/lineCalc.js exactly:
 *   line   = qty * (price - discountNet) [+ tax% of that, exclusive only]
 *   after  = sum(lines) - orderDiscount - pointsDiscount - promotionDiscount
 *   tax    = after * tax_rate / 100
 *   total  = after + tax + shipping
 *
 * Tolerances are rounding-sized, not percentage-sized: the previous 2% band
 * (stacking to ~4%) let a cashier under-charge a sale.
 *  - line: max(0.03, 0.1% of the line)      (subtotal is rounded to 2 dp)
 *  - header: 0.51                           (POS rounds GrandTotal to the
 *                                            currency's price decimals, which
 *                                            can be 0)
 */
class SaleTotalsVerifier
{
    private const LINE_ABS = 0.03;

    private const LINE_PCT = 0.001;

    private const HEADER_TOL = 0.51;

    /**
     * @param  array  $details  request 'details' array
     *
     * @throws \InvalidArgumentException
     */
    public static function verify(
        array $details,
        $shipping,
        $discount,
        $discountMethod,
        $submittedGrandTotal,
        $submittedTaxNet,
        $taxRate,
        $pointsDiscount = 0,
        $promotionDiscount = 0
    ): void {
        $sum = 0.0;
        foreach ($details as $line) {
            $sum += self::checkLineExact($line);
        }

        $shipping = max(0.0, (float) $shipping);
        $discount = max(0.0, (float) $discount);
        $taxRate = (float) $taxRate;
        if ($taxRate < 0 || $taxRate > 100) {
            throw new \InvalidArgumentException('Order tax rate is out of the allowed 0-100 range.');
        }

        $discountAmount = (string) $discountMethod === '1'
            ? round($sum * (min($discount, 100) / 100), 2)
            : round(min($discount, $sum), 2);
        $remaining = max(0.0, $sum - $discountAmount);
        $points = round(min(max(0.0, (float) $pointsDiscount), $remaining), 2);
        $remaining = max(0.0, $remaining - $points);
        $promo = round(min(max(0.0, (float) $promotionDiscount), $remaining), 2);
        $after = round($remaining - $promo, 2);

        $expectedTax = round($after * $taxRate / 100, 2);
        if (abs((float) $submittedTaxNet - $expectedTax) > self::HEADER_TOL) {
            throw new \InvalidArgumentException(sprintf(
                'Order tax %.4f does not match tax rate %.4f%% of %.4f (expected %.4f).',
                (float) $submittedTaxNet, $taxRate, $after, $expectedTax
            ));
        }

        $expected = round($after + $expectedTax + $shipping, 2);
        if (abs((float) $submittedGrandTotal - $expected) > self::HEADER_TOL) {
            throw new \InvalidArgumentException(sprintf(
                'Grand total %.4f does not match the recomputed total %.4f.',
                (float) $submittedGrandTotal, $expected
            ));
        }
    }

    /**
     * Exact per-line check (only when tax_method is 1/2, otherwise the loose
     * SaleTotalsGuard::checkLine band already ran). Returns the submitted
     * subtotal so callers can sum it.
     */
    private static function checkLineExact(array $line): float
    {
        $submitted = (float) ($line['subtotal'] ?? 0);
        $method = (string) ($line['tax_method'] ?? '');
        if ($method !== '1' && $method !== '2') {
            return $submitted;
        }

        $qty = (float) ($line['quantity'] ?? 0);
        $price = (float) ($line['Unit_price'] ?? 0);
        $discount = max(0.0, (float) ($line['discount'] ?? 0));
        $taxPct = (float) ($line['tax_percent'] ?? 0);

        $discountNet = (string) ($line['discount_Method'] ?? '2') === '1'
            ? $price * min($discount, 100) / 100
            : min($discount, $price);
        $base = max(0.0, $price - $discountNet);
        $expected = $method === '1'
            ? $qty * ($base + $base * $taxPct / 100)
            : $qty * $base;

        if (abs($submitted - $expected) > max(self::LINE_ABS, abs($expected) * self::LINE_PCT)) {
            throw new \InvalidArgumentException(sprintf(
                'Line total %.4f does not match quantity %.4f x price %.4f after discount/tax (expected %.4f).',
                $submitted, $qty, $price, $expected
            ));
        }

        return $submitted;
    }
}
