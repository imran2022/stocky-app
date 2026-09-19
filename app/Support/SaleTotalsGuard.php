<?php

namespace App\Support;

/**
 * Security fix (Build N1, audit finding C-01): "server trusts
 * client-supplied financial totals". The sale create/update endpoints
 * used to save GrandTotal, TaxNet, and every line's price/subtotal
 * exactly as submitted, with no server-side check at all — the audit
 * demonstrated submitting a line subtotal of 1 for a 100-unit-price line,
 * and a GrandTotal of 987,654.321 unrelated to the actual line items, and
 * both were persisted unchanged.
 *
 * IMPORTANT — what this class deliberately does NOT do: it does not force
 * Unit_price back to the product's catalog price, and it does not forbid
 * per-line discounts. Staff being able to type a negotiated price/discount
 * per line is this app's existing, intended behavior (there is no
 * "override price" permission gating it — it's just how the POS/Sales
 * form works), not a bug this build is meant to remove. Locking prices to
 * the catalog would be a real change to how the business works and needs
 * its own explicit decision, not something to bundle silently into a
 * security patch.
 *
 * What IS enforced: the numbers must be internally consistent.
 * - Every line's discount % and tax % must be within a sane 0–100 range
 *   (this alone rejects the audit's TaxNet=777.777-style input).
 * - Every line's submitted subtotal/total must fall within a generous,
 *   rounding-tolerant band around quantity × (price − discount) × (1 + tax%)
 *   — wide enough to cover exclusive vs. inclusive tax handling and normal
 *   float rounding, but not wide enough to let a submitted total be
 *   wildly disconnected from the quantity/price/discount/tax the SAME
 *   request also submitted.
 * - The header GrandTotal must equal the sum of the (now validated) line
 *   totals, minus the header discount, plus shipping — this part is exact
 *   arithmetic, not a tolerance band, since that relationship is a
 *   definition, not a pricing policy choice.
 * - TaxNet must be a plausible amount for that GrandTotal (0 ≤ TaxNet ≤
 *   GrandTotal), which is what actually catches a nonsense value like
 *   777.777 once GrandTotal itself has been brought back to a sane number.
 *
 * Any violation throws \InvalidArgumentException; callers should catch
 * that and turn it into a 422 response rather than letting the document
 * save.
 */
class SaleTotalsGuard
{
    private const TOLERANCE_ABS = 0.05;

    private const TOLERANCE_PCT = 0.02; // 2%

    /**
     * Validate one line: quantity/price/discount/tax must be sane, and the
     * submitted subtotal must be arithmetically consistent with them.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkLine(
        $quantity,
        $unitPrice,
        $discount,
        $discountMethod,
        $taxPercent,
        $submittedTotal
    ): void {
        $qty = (float) $quantity;
        $price = (float) $unitPrice;

        if ($qty < 0 || $price < 0) {
            throw new \InvalidArgumentException('Quantity and price must not be negative.');
        }

        $discount = max(0.0, (float) $discount);
        $taxPercent = (float) $taxPercent;

        if ($taxPercent < 0 || $taxPercent > 100) {
            throw new \InvalidArgumentException(
                sprintf('Tax percent %.4f is out of the allowed 0–100 range.', $taxPercent)
            );
        }

        $discountMethod = (string) $discountMethod;
        $discountAmountPerUnit = $discountMethod === '1'
            ? $price * (min($discount, 100) / 100)
            : min($discount, $price);

        if ($discountMethod !== '1' && $discount > $price + self::TOLERANCE_ABS) {
            throw new \InvalidArgumentException(
                sprintf('Fixed discount %.4f exceeds the unit price %.4f.', $discount, $price)
            );
        }

        $netPrice = max(0.0, $price - $discountAmountPerUnit);

        $low = $qty * $netPrice;
        $high = $qty * $netPrice * (1 + $taxPercent / 100);

        $lowBound = $low - self::tolerance($low);
        $highBound = $high + self::tolerance($high);

        $submitted = (float) $submittedTotal;

        if ($submitted < $lowBound - 0.01 || $submitted > $highBound + 0.01) {
            throw new \InvalidArgumentException(sprintf(
                'Line total %.4f is inconsistent with quantity (%.4f) x price (%.4f) after discount/tax '.
                '(expected between %.4f and %.4f).',
                $submitted,
                $qty,
                $price,
                $lowBound,
                $highBound
            ));
        }
    }

    /**
     * Validate the header GrandTotal against the (already-validated) sum
     * of line totals, the header discount, and shipping. This relationship
     * is exact — not a tolerance band beyond ordinary float rounding.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkGrandTotal(
        $lineTotalsSum,
        $shipping,
        $discount,
        $discountMethod,
        $submittedGrandTotal
    ): void {
        $sum = (float) $lineTotalsSum;
        $shipping = max(0.0, (float) $shipping);
        $discount = max(0.0, (float) $discount);
        $discountMethod = (string) $discountMethod;

        $discountAmount = $discountMethod === '1'
            ? $sum * (min($discount, 100) / 100)
            : min($discount, $sum);

        $expected = max(0.0, $sum - $discountAmount) + $shipping;
        $submitted = (float) $submittedGrandTotal;

        if (abs($submitted - $expected) > self::tolerance($expected) + 0.01) {
            throw new \InvalidArgumentException(sprintf(
                'Grand total %.4f does not match the sum of line totals minus discount plus shipping '.
                '(expected %.4f).',
                $submitted,
                $expected
            ));
        }
    }

    /**
     * Sanity-check TaxNet against the (already-validated) GrandTotal: tax
     * can't be negative and can't exceed the total it's part of.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkTaxNet($grandTotal, $submittedTaxNet): void
    {
        $grand = (float) $grandTotal;
        $tax = (float) $submittedTaxNet;

        if ($tax < -0.01 || $tax > $grand + self::tolerance($grand) + 0.01) {
            throw new \InvalidArgumentException(sprintf(
                'Tax amount %.4f is not plausible for a grand total of %.4f.',
                $tax,
                $grand
            ));
        }
    }

    private static function tolerance(float $value): float
    {
        return max(self::TOLERANCE_ABS, abs($value) * self::TOLERANCE_PCT);
    }
}
