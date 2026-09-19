<?php

/**
 * Build L4 — Modern Sale Invoice: percent-discount fix, Box Qty, Discount
 * from Points, and shipping label restyle (2026-09-19).
 *
 * The user asked for a review of their own uploaded Modern template
 * (sale_pdf_modern.blade.php, added in Build L3) and found:
 *
 * 1. A genuine calculation bug: when the order discount is percent-based
 *    (discount_Method === '1'), $sale['discount'] holds the raw PERCENT
 *    NUMBER (e.g. 10, meaning 10%), not a dollar amount. The template used
 *    it directly as a dollar figure, understating both the Discount line
 *    and the backward-derived Subtotal. Fixed using the same
 *    percent-vs-fixed branching the Classic layout already uses, with the
 *    dollar amount solved algebraically from GrandTotal (see the
 *    documentation comment in the @php block of sale_pdf_modern.blade.php).
 *    Confirmed via a real PDF render + pdftotext: a 10% discount on a
 *    USD 350 subtotal now shows "Discount - 10.00% (USD 35.00)" and
 *    "Subtotal USD 350.00" (previously understated both).
 *
 * 2. Box Qty was entirely absent from the Modern template. Added a
 *    conditional "Box" column (shown only when the enable_box_qty setting
 *    is on AND at least one line actually has a box_qty value — same
 *    dynamic-column philosophy the template's author already used for the
 *    Disc/VAT columns), with the item table's description-column width
 *    still adjusting dynamically around it.
 *
 * 3. Discount from Points was computed (needed for the corrected subtotal
 *    math) but never displayed. Added a "Discount from Points" row to the
 *    totals box, shown only when discount_from_points > 0.
 *
 * 4. Answering "can Modern be customized too?": the Sections and Text &
 *    labels panels of the Invoice PDF customizer were only partly wired
 *    into Modern (only Previous Dues/Net Balance, from Build L2) even
 *    though the settings page always showed those panels for both
 *    layouts. Wired in the rest: Customer block, Sales Status line,
 *    Notes, Thank-you line (+ its text override), Footer text override,
 *    and the Document title override — same controls Classic already
 *    honors. The Colors/Typography/Layout & logo/Items table panels
 *    remain Classic-only, as the settings page already tells the user.
 *
 * 5. The separate standalone Shipping Label PDF (shipping_label.blade.php,
 *    used by the "Print Shipping Label" button, independent of the Sale
 *    Invoice PDF) was restyled to match the visual language of the Modern
 *    invoice's embedded "DELIVERY INFORMATION" section (uppercase
 *    micro-labels, sender/receiver block layout, COD vs PAID badge)
 *    instead of its previous unrelated design, per the user's explicit
 *    request. Its own $sale/$company/$symbol inputs from
 *    SalesController::Sale_Shipping_Label() were not changed.
 *
 * This is a static source-contract check; it was additionally verified
 * against a real seeded database: rendered the actual Modern Sale Invoice
 * PDF (via SalesController::Sale_PDF()) for a sale with a 10% order
 * discount, a $5 points discount, $15 shipping, one line item with a
 * box_qty and one without — confirmed via pdftotext -layout that Subtotal,
 * Discount (percent + dollar), Discount from Points, Shipping, and GRAND
 * TOTAL are all internally consistent, that the Box column shows the value
 * on the line that has one and "—" on the line that doesn't, and that
 * turning enable_box_qty off removes the column entirely. Also re-rendered
 * a flat-dollar-discount sale (regression: still shows a plain dollar
 * amount, no stray percent text) and re-rendered with layout='classic' to
 * confirm the Classic template's own (already-correct) discount math and
 * output are unaffected by any of this.
 *
 * Run with: php tests/Regression/build_l4_modern_invoice_fixes_and_shipping_label.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);
$exists = static fn (string $relative): bool => file_exists($root.'/'.$relative);

$modern = $read('resources/views/pdf/sale_pdf_modern.blade.php');

$assert(
    str_contains($modern, "\$discountMethod = \$sale['discount_Method'] ?? '2';") && str_contains($modern, '$discountRaw'),
    'sale_pdf_modern.blade.php must branch on discount_Method, not treat $sale[\'discount\'] as a dollar amount directly.'
);
$assert(
    str_contains($modern, "\$discountMethod === '1'") && substr_count($modern, "\$discountMethod === '1'") >= 2,
    'The fix must be used both when computing $discountAmount and when rendering the Discount row (percent display).'
);
$assert(
    str_contains($modern, "number_format(\$discountRaw, 2)}}%"),
    'The Discount row must show the percent value (matching the Classic layout\'s pattern) when the order discount is percent-based.'
);
$assert(
    str_contains($modern, 'Discount from Points') && str_contains($modern, '$discountFromPoints'),
    'The totals box must show a Discount from Points row when discount_from_points > 0.'
);
$assert(
    str_contains($modern, '$anyLineHasBoxQty') && str_contains($modern, "\$hasBoxQty = \$anyLineHasBoxQty"),
    'Box Qty column must be data-driven (only shown when a line actually has a box_qty) and gated by the enable_box_qty setting, same as the Classic layout and Sale Detail page.'
);
$assert(
    str_contains($modern, "enable_box_qty"),
    'Box Qty column must respect the enable_box_qty company setting.'
);

foreach (['show_customer', 'show_status', 'show_notes', 'show_thank_you', 'show_footer_text'] as $sectionKey) {
    $assert(
        str_contains($modern, "\$pdfT['{$sectionKey}']"),
        "sale_pdf_modern.blade.php must honor the Sections panel's {$sectionKey} toggle, same as the Classic layout."
    );
}
$assert(
    str_contains($modern, "\$pdfT['labels']['title']") && str_contains($modern, "\$pdfT['labels']['thank_you']"),
    'sale_pdf_modern.blade.php must honor the Text & labels panel\'s title and thank_you overrides, same as the Classic layout.'
);

$assert($exists('resources/views/pdf/shipping_label.blade.php'), 'The standalone Shipping Label Blade template must exist.');
$shippingLabel = $read('resources/views/pdf/shipping_label.blade.php');
$assert(
    stripos($shippingLabel, 'Delivery Information') !== false && stripos($shippingLabel, 'Sender (From)') !== false && stripos($shippingLabel, 'Ship To (Receiver)') !== false,
    'shipping_label.blade.php must be restyled to the Modern invoice\'s DELIVERY INFORMATION visual language (sender/receiver block layout).'
);
$assert(
    stripos($shippingLabel, 'Cash On Delivery') !== false || str_contains($shippingLabel, 'COD'),
    'shipping_label.blade.php must keep a COD vs PAID indicator, matching the Modern invoice\'s badge.'
);
$assert(
    str_contains($shippingLabel, "\$sale['Ref']") && str_contains($shippingLabel, "\$sale['client_name']") && str_contains($shippingLabel, '$company'),
    'shipping_label.blade.php must keep using the same $sale/$company inputs already supplied by SalesController::Sale_Shipping_Label() (no controller change required).'
);

if ($failures) {
    fwrite(STDERR, "Build L4 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L4 regression gate: PASS (Modern Sale Invoice: percent-discount math fixed, Box Qty and Discount from Points added; standalone Shipping Label restyled to match).\n";
