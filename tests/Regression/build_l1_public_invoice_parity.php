<?php

/**
 * Build L1 — Public Invoice page brought to parity with the authenticated
 * Sale Detail page (2026-09-19). Adds: per-line Box Qty (gated by the
 * company's enable_box_qty setting), per-line Discount/Tax, IMEI/batch
 * numbers, pack quantity breakdown, order-level Discount from Points,
 * Previous Dues, Net Balance. Removes the customer's own email from the
 * public page. Reformats the company header block to:
 *   Name / Address / VAT/BIN / Phone / Mail / Website
 *
 * This is a static source-contract check (see docs/CUSTOMIZATION_CHECKLIST.md
 * section 15 for what that does and doesn't catch); it was additionally
 * verified once against a real seeded database — created a sale with a
 * percent order discount, discount-from-points, per-line fixed discount,
 * per-line tax, box_qty, and a second completed/unpaid sale for the same
 * client to produce real previous-dues — and confirmed every number in
 * the JSON response (including Previous Dues / Net Balance) matched hand
 * computation, that box_qty is null in the payload when enable_box_qty is
 * off, and that the client object carries no email key.
 *
 * Run with: php tests/Regression/build_l1_public_invoice_parity.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$controller = $read('app/Http/Controllers/PublicInvoiceController.php');

$assert(
    ! str_contains($controller, "'email' => \$sale->client->email"),
    'PublicInvoiceController::show() must not expose the customer\'s own email in the client block.'
);
$assert(
    str_contains($controller, "'box_qty' => \$enableBoxQty"),
    'PublicInvoiceController::show() must gate per-line box_qty behind the enable_box_qty setting.'
);
foreach (['discount', 'tax', 'is_imei', 'imei_number', 'is_batch_tracked', 'batches', 'pack_name', 'pack_multiplier'] as $field) {
    $assert(
        str_contains($controller, "'{$field}' =>"),
        "PublicInvoiceController::show() items must include '{$field}' (Sale Detail parity)."
    );
}
foreach (['discount_from_points', 'previous_dues', 'net_balance', 'discount_method', 'discount_percent'] as $field) {
    $assert(
        str_contains($controller, "'{$field}' =>"),
        "PublicInvoiceController::show() totals must include '{$field}' (Sale Detail parity)."
    );
}
$assert(
    str_contains($controller, 'function clientPreviousDues'),
    'PublicInvoiceController must have its own clientPreviousDues() (SalesController\'s is private, not shared).'
);

$page = $read('resources/src/pages/public/PublicInvoice.vue');

$assert(
    ! str_contains($page, 'data.client.email'),
    'PublicInvoice.vue must not render the customer\'s own email.'
);
$assert(
    str_contains($page, 'v-if="data.enable_box_qty"'),
    'PublicInvoice.vue must show the Box column only when enable_box_qty is on.'
);
foreach (['item.discount', 'item.tax', 'item.box_qty', 'item.imei_number', 'item.batches', 'item.pack_multiplier'] as $ref) {
    $assert(
        str_contains($page, $ref),
        "PublicInvoice.vue must render {$ref}."
    );
}
foreach (['data.totals.discount_from_points', 'data.totals.previous_dues', 'data.totals.net_balance'] as $ref) {
    $assert(
        str_contains($page, $ref),
        "PublicInvoice.vue must render {$ref}."
    );
}

// Company header block must be in the requested order: Name (brand-name,
// checked separately), Address, VAT/BIN, Phone, Mail, Website — and must
// not combine phone+email onto one line the way the old layout did.
$headerBlock = substr($page, strpos($page, 'class="brand-text"'), 700);
$order = ['company.address', 'VAT/BIN:', 'Phone:', 'Mail:', 'Website:'];
$lastPos = -1;
foreach ($order as $marker) {
    $pos = strpos($headerBlock, $marker);
    $assert($pos !== false, "PublicInvoice.vue company header must contain '{$marker}'.");
    $assert($pos > $lastPos, "PublicInvoice.vue company header order is wrong at '{$marker}' (expected Address, VAT/BIN, Phone, Mail, Website).");
    $lastPos = $pos;
}
$assert(
    ! str_contains($headerBlock, 'data.company.phone && data.company.email'),
    'PublicInvoice.vue must not combine company phone+email onto one line anymore.'
);

if ($failures) {
    fwrite(STDERR, "Build L1 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L1 regression gate: PASS (Public Invoice page now at parity with Sale Detail: box qty, discount, tax, previous dues, net balance; customer email removed; company header reformatted).\n";
