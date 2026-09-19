<?php

/**
 * Build L5 — Packing List restyled to match the Modern Sale Invoice (2026-09-19).
 *
 * The user asked for the Packing List PDF to follow the same visual
 * language as their Modern Sale Invoice template (Build L3/L4): same
 * color palette, header layout with company name/logo, product-table
 * look, uppercase micro-labels, and the same "dynamic column" philosophy
 * — the Box column now only appears when at least one line item actually
 * has a box quantity, and honors the enable_box_qty company setting,
 * same as the invoice and Public Invoice page.
 *
 * SalesController::Sale_Packing_List() now also passes the company
 * settings row ('setting') to the view so the header can show the
 * company name/logo (it previously passed none — the old design had no
 * company header at all).
 *
 * A real bug was found and fixed while verifying this: the restyled
 * packing_list.blade.php (and, on inspection, the Build L4 shipping_label
 * .blade.php) opened with a raw HTML `<!-- -->` documentation comment
 * containing em-dash characters. A raw HTML comment is NOT stripped at
 * compile time — it reaches the rendered output ahead of the
 * `<meta charset="UTF-8">` tag, and DomPDF's encoding auto-detection got
 * confused by the early non-ASCII bytes, garbling every em-dash later in
 * the document. Fixed by using a Blade `@php`-style comment block
 * (stripped at compile time) instead — verified via a real PDF render +
 * pdftotext that the em-dash in "Total Boxes: —" (no box quantity on
 * that line) and in the footer note renders correctly. While fixing this,
 * a second, sharper bug was caught: the replacement comment's own
 * explanatory text originally contained the literal Blade comment tokens
 * as an example, which closed the comment block early and leaked the
 * rest of the comment text into the rendered PDF — fixed by describing
 * the tokens in prose instead of spelling them out literally.
 *
 * This is a static source-contract check; it was additionally verified
 * against a real seeded database: rendered the actual Packing List PDF
 * (via SalesController::Sale_Packing_List()) for a sale with a box
 * quantity on one line and not the other — confirmed via pdftotext that
 * the Box column appears with the right per-line values, the company
 * header shows, and no comment text or mis-encoded characters leak into
 * the output. Re-rendered for a sale with no box quantities on any line
 * — confirmed the Box column and "Total Boxes" row are both absent
 * entirely (not just dashed out).
 *
 * Run with: php tests/Regression/build_l5_packing_list_modern_style.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$packingList = $read('resources/views/pdf/packing_list.blade.php');
$controller = $read('app/Http/Controllers/SalesController.php');

$assert(
    str_contains($packingList, "\$hasBoxQty = \$totalBoxes !== null && (bool) (\$setting['enable_box_qty'] ?? true);"),
    'packing_list.blade.php must show the Box column only when a line has a box quantity AND the enable_box_qty setting is on, same as the invoice templates.'
);
$assert(
    str_contains($packingList, "product-table") && str_contains($packingList, 'class="label"'),
    'packing_list.blade.php must reuse the Modern invoice\'s product-table / label CSS classes for visual consistency.'
);
$assert(
    str_contains($packingList, "\$setting['CompanyName']"),
    'packing_list.blade.php must show the company header (name/logo), which the old design lacked entirely.'
);

// The exact bug class that was found and fixed: a raw HTML comment at the
// top of a Blade PDF template is not stripped at compile time. Guard
// against it recurring in either template touched this build.
foreach (['resources/views/pdf/packing_list.blade.php', 'resources/views/pdf/shipping_label.blade.php'] as $view) {
    $contents = $read($view);
    $trimmed = ltrim($contents);
    $assert(
        ! str_starts_with($trimmed, '<!--'),
        "{$view} must not open with a raw HTML comment — it leaks into the rendered PDF ahead of the charset meta tag and can corrupt DomPDF's encoding detection. Use a Blade comment block instead."
    );
    $assert(
        str_starts_with($trimmed, '{{--'),
        "{$view} should open with a Blade comment block (stripped at compile time) documenting its provenance."
    );
}

$assert(
    str_contains($controller, "'setting' => \$settings,") && str_contains($controller, 'function Sale_Packing_List'),
    'SalesController::Sale_Packing_List() must pass the company settings row to the view.'
);

if ($failures) {
    fwrite(STDERR, "Build L5 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L5 regression gate: PASS (Packing List PDF restyled to match the Modern Sale Invoice; a real DomPDF encoding-corruption bug caught and fixed along the way).\n";
