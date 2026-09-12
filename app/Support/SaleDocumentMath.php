<?php

namespace App\Support;

/**
 * Pure monetary helpers for custom sale-document output.
 *
 * Stocky stores sale monetary values in the base currency and keeps a
 * document-currency exchange-rate snapshot on the Sale. These helpers are
 * deliberately side-effect free so custom PDF/label paths can share tested
 * arithmetic without changing the vendor's core sale/accounting engine.
 */
final class SaleDocumentMath
{
    public static function convert($amount, float $rate): float
    {
        return (float) $amount * $rate;
    }

    /** Percent discounts remain percentages; fixed discounts are money. */
    public static function discount($discount, string $method, float $rate): float
    {
        return $method === '1'
            ? (float) $discount
            : self::convert($discount, $rate);
    }

    /** COD is never more than the unpaid balance and never negative. */
    public static function outstanding($grandTotal, $paidAmount): float
    {
        return max((float) $grandTotal - (float) $paidAmount, 0.0);
    }
}
