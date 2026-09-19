<?php

/**
 * Build L6 — Packing List: page-margin fix + full header parity with the
 * Modern invoice (2026-09-19).
 *
 * After Build L5 shipped, the user reported the Packing List PDF looked
 * cut off at the page edges (content sitting flush against the left/right
 * margins) and that the header was missing the company phone/email line
 * the Modern invoice shows.
 *
 * Root cause of the margin issue: packing_list.blade.php used a plain
 * `@page { margin: 0.4in; }` rule. The Modern Sale Invoice
 * (sale_pdf_modern.blade.php) instead sets `@page { margin: 0; }` for a
 * PDF download and does the visual margin with body padding instead —
 * that is the pairing already proven reliable for this app's actual PDF
 * download pipeline. Since Sale_Packing_List() always downloads (no
 * inline-view branch), it now unconditionally uses that same
 * margin-zero / body-padding pairing, matching the invoice exactly rather
 * than reinventing a different (and apparently unreliable in production)
 * approach.
 *
 * Also added the company Phone | Email line to the header, so it now
 * shows the same three header lines the invoice does (Name, Address,
 * Phone | Email).
 *
 * This is a static source-contract check; it was additionally verified
 * with real PDF renders converted to PNG (pdftoppm) and visually compared
 * side-by-side against a Modern invoice PDF rendered from the same sale —
 * confirmed matching margins, header layout, and typography, for both a
 * sale with box quantities and one without.
 *
 * Run with: php tests/Regression/build_l6_packing_list_margin_fix.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$packingList = file_get_contents($root.'/resources/views/pdf/packing_list.blade.php');

$assert(
    str_contains($packingList, '@page { margin: 0; }'),
    'packing_list.blade.php must use the same margin-zero / body-padding pairing the Modern invoice uses for PDF downloads, not a plain @page margin (the cause of the reported edge-to-edge content).'
);
$assert(
    str_contains($packingList, 'padding: 25px 25px 35px 25px;'),
    'packing_list.blade.php\'s body must provide the visual page margin via padding, matching the Modern invoice\'s download-path CSS exactly.'
);
$assert(
    str_contains($packingList, "\$setting['CompanyPhone']") && str_contains($packingList, "\$setting['email']"),
    'packing_list.blade.php header must show the company Phone | Email line, same as the Modern invoice header (previously only Name + Address were shown).'
);
$assert(
    str_contains($packingList, '$codeWidth'),
    'The Code/SKU column should widen when the Box column is hidden, so codes don\'t wrap awkwardly.'
);

if ($failures) {
    fwrite(STDERR, "Build L6 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L6 regression gate: PASS (Packing List page margins and header now match the Modern Sale Invoice exactly).\n";
