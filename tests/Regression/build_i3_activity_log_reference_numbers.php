<?php

/**
 * Build I3 — Activity Log must describe entries by the human invoice/
 * reference number (the `Ref` column, e.g. "SL-104") shown on screen,
 * never the internal database id.
 *
 * Root cause found during the 2026-09-19 deep audit: Sale/Purchase read a
 * non-existent `->reference` attribute (the real column is `Ref`), which
 * is always null on an Eloquent model, so every entry silently fell back
 * to the raw id ("Sale #5" instead of "Sale SL-104"). Adjustment/Transfer
 * never attempted to use `Ref` at all — they used the raw id outright.
 *
 * This is a static source-contract check (see docs/CUSTOMIZATION_CHECKLIST.md
 * section 15 for what that does and doesn't catch); it was additionally
 * verified once against a real seeded database by creating a Sale and an
 * Adjustment and confirming the resulting activity_logs.description text
 * read "Sale SL-9001 created" / "Adjustment ADJ-9001 created", not the
 * database id.
 *
 * Run with: php tests/Regression/build_i3_activity_log_reference_numbers.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$provider = file_get_contents($root.'/app/Providers/ActivityLogServiceProvider.php');

$assert(
    ! str_contains($provider, '$sale->reference'),
    'Sale describer must not read the non-existent ->reference attribute.'
);
$assert(
    ! str_contains($provider, '$purchase->reference'),
    'Purchase describer must not read the non-existent ->reference attribute.'
);
$assert(
    str_contains($provider, "\$sale->Ref ?: '#'.\$sale->id"),
    'Sale describer must use the real Ref column, falling back to the id only when Ref is empty.'
);
$assert(
    str_contains($provider, "\$purchase->Ref ?: '#'.\$purchase->id"),
    'Purchase describer must use the real Ref column, falling back to the id only when Ref is empty.'
);
$assert(
    str_contains($provider, "\$adj->Ref ?: '#'.\$adj->id"),
    'Adjustment describer must use the real Ref column, falling back to the id only when Ref is empty.'
);
$assert(
    str_contains($provider, "\$t->Ref ?: '#'.\$t->id"),
    'Transfer describer must use the real Ref column, falling back to the id only when Ref is empty.'
);

if ($failures) {
    fwrite(STDERR, "Build I3 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build I3 regression gate: PASS (Activity Log describes Sale/Purchase/Adjustment/Transfer by their real Ref/invoice number).\n";
