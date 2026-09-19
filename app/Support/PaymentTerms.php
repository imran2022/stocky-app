<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Payment Terms hierarchy (Customer Ledger & Payment Terms feature, Phase A,
 * 2026-09-19).
 *
 * Three levels, each one falling back to the level above it when unset:
 *   1. System Default   — settings.default_payment_term_days
 *   2. Customer Default  — clients.payment_term_days (NULL = no override)
 *   3. Invoice Override  — chosen on the sale itself, wins when present
 *
 * A sale stores the RESOLVED term (sales.payment_term_days) and its derived
 * due date (sales.due_date) at the time it's created/edited — not a live
 * reference to the customer's or system's current setting — so changing a
 * customer's default term later never silently changes the due date on an
 * invoice already issued to them.
 */
final class PaymentTerms
{
    /**
     * Preset choices shown in the UI. Any other non-negative integer is a
     * valid "Custom" value — this list is only the labeled shortcuts.
     */
    public const PRESETS = [
        0 => 'Immediate',
        7 => '7 Days',
        15 => '15 Days',
        30 => '30 Days',
    ];

    /** Used only if a store somehow has no settings row at all yet. */
    public const FALLBACK_SYSTEM_DEFAULT_DAYS = 7;

    /**
     * Resolve the number of days to use, walking the hierarchy from the
     * most specific level that's actually set down to the system default.
     *
     * @param  int|null  $invoiceOverrideDays  Level 3 — explicit choice made on this sale, if any.
     * @param  int|null  $customerDefaultDays  Level 2 — clients.payment_term_days, if set.
     * @param  int|null  $systemDefaultDays    Level 1 — settings.default_payment_term_days.
     */
    public static function resolveDays(?int $invoiceOverrideDays, ?int $customerDefaultDays, ?int $systemDefaultDays): int
    {
        if ($invoiceOverrideDays !== null) {
            return max(0, $invoiceOverrideDays);
        }
        if ($customerDefaultDays !== null) {
            return max(0, $customerDefaultDays);
        }

        return max(0, $systemDefaultDays ?? self::FALLBACK_SYSTEM_DEFAULT_DAYS);
    }

    /**
     * Invoice Date + Payment Term = Due Date. $saleDate accepts anything
     * Carbon::parse understands (a 'Y-m-d' string, a Carbon instance, ...).
     */
    public static function dueDate($saleDate, int $days): string
    {
        return Carbon::parse($saleDate)->addDays($days)->toDateString();
    }

    /**
     * Current Date > Due Date AND Outstanding > 0 = Overdue. No due date
     * (legacy sales predating this feature) is never overdue.
     */
    public static function isOverdue(?string $dueDate, float $outstanding): bool
    {
        if (empty($dueDate) || $outstanding <= 0) {
            return false;
        }

        return Carbon::today()->gt(Carbon::parse($dueDate));
    }

    /** Human label for a term, falling back to "N Days" for a custom value. */
    public static function label(int $days): string
    {
        return self::PRESETS[$days] ?? "{$days} Days";
    }
}
