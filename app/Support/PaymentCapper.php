<?php

namespace App\Support;

/**
 * Caps a document's stored `paid_amount` so it can never exceed its
 * `GrandTotal` (Build N2, audit finding H-01).
 *
 * The bug: payment creation (Sale and Purchase, both the inline
 * payment-on-checkout flow and the standalone payment endpoints) summed
 * the tendered amount straight into `paid_amount` with no cap. When a
 * customer/supplier is tendered more than what's due (completely normal —
 * e.g. paying with a note larger than the total and getting change back),
 * the FULL tendered amount was saved as `paid_amount`, even though
 * `change` was recorded separately and never subtracted. A sale/purchase
 * could show `paid_amount` far above its own `GrandTotal`.
 *
 * The fix is deliberately minimal and does not change what a payment
 * "means": it does not require distinguishing tendered-vs-applied amounts,
 * does not touch `change` (still recorded exactly as before, and account/
 * cash-drawer balance logic is untouched — this is purely about what the
 * document's own `paid_amount` is allowed to say). It just makes sure the
 * NUMBER WRITTEN TO THE DOCUMENT never claims more was applied than the
 * document is actually worth, and never goes negative (a defensive floor
 * for the payment-delete/edit paths, which subtract).
 */
class PaymentCapper
{
    public static function capPaid(float $grandTotal, float $rawPaidAmount): float
    {
        $capped = min($grandTotal, $rawPaidAmount);

        return max(0.0, $capped);
    }
}
