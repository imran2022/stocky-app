<?php

/**
 * Build L2 — Previous Dues / Net Balance shown-or-hidden toggle (2026-09-19).
 *
 * Reuses the existing "Invoice PDF" customizer (Settings → Invoice PDF,
 * `PdfTemplate` model, one settings row per doc_type) instead of building a
 * new settings mechanism — added two boolean keys, 'show_previous_dues' and
 * 'show_net_balance', that now gate those two lines on all three places a
 * sale's Previous Dues / Net Balance appear: the Sale PDF
 * (resources/views/pdf/sale_pdf.blade.php), the authenticated Sale Detail
 * page (SaleDetails.vue via SalesController::show()), and the public,
 * no-login invoice page (PublicInvoice.vue via PublicInvoiceController::
 * show()). Sale-only — quotation and purchase PDFs never had these lines.
 *
 * This is a static source-contract check (see docs/CUSTOMIZATION_CHECKLIST.md
 * section 15 for what that does and doesn't catch); it was additionally
 * verified once against a real seeded database, in separate PHP process
 * invocations per case (PdfTemplate::settingsFor() caches per-process, so a
 * single script re-reading it after an update would see stale data — not a
 * real issue in a normal PHP-FPM request, but it does mean a real check
 * needs fresh processes): confirmed SalesController::show(),
 * PublicInvoiceController::show(), and the actual rendered Sale_PDF (text
 * extracted with pdftotext) all agree with each of three toggle
 * combinations (both on, both off, only Previous Dues on).
 *
 * Run with: php tests/Regression/build_l2_previous_dues_toggle.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$model = $read('app/Models/PdfTemplate.php');
$assert(
    str_contains($model, "'show_previous_dues' => true") && str_contains($model, "'show_net_balance' => true"),
    'PdfTemplate::DEFAULTS must include show_previous_dues and show_net_balance (default on, matching prior unconditional behavior).'
);

$controller = $read('app/Http/Controllers/PdfTemplateController.php');
$assert(
    str_contains($controller, "'show_previous_dues' => 'required|boolean'") && str_contains($controller, "'show_net_balance' => 'required|boolean'"),
    'PdfTemplateController::update() must validate the two new keys.'
);

$blade = $read('resources/views/pdf/sale_pdf.blade.php');
$assert(
    substr_count($blade, "!empty(\$pdfT['show_previous_dues'])") === 2,
    'sale_pdf.blade.php must gate BOTH the RTL and LTR Previous Dues row on show_previous_dues.'
);
$assert(
    substr_count($blade, "!empty(\$pdfT['show_net_balance'])") === 2,
    'sale_pdf.blade.php must gate BOTH the RTL and LTR Net Balance row on show_net_balance, independently of show_previous_dues.'
);

$salesController = $read('app/Http/Controllers/SalesController.php');
$assert(
    str_contains($salesController, "PdfTemplate::settingsFor('sale')") && str_contains($salesController, "'show_previous_dues'"),
    'SalesController::show() must expose show_previous_dues/show_net_balance so SaleDetails.vue can gate on them.'
);

$saleDetails = $read('resources/src/pages/sales/SaleDetails.vue');
$assert(
    str_contains($saleDetails, "sale.show_previous_dues !== false") && str_contains($saleDetails, "sale.show_net_balance !== false"),
    'SaleDetails.vue must gate its Previous Dues / Net Balance rows on the flags from the API (defaulting to shown if absent).'
);

$publicController = $read('app/Http/Controllers/PublicInvoiceController.php');
$assert(
    str_contains($publicController, "PdfTemplate::settingsFor('sale')") && str_contains($publicController, "'show_previous_dues' => (bool) \$pdfSettings['show_previous_dues']"),
    'PublicInvoiceController::show() must expose the same two flags.'
);

$publicPage = $read('resources/src/pages/public/PublicInvoice.vue');
$assert(
    str_contains($publicPage, 'data.totals.show_previous_dues !== false') && str_contains($publicPage, 'data.totals.show_net_balance !== false'),
    'PublicInvoice.vue must gate its Previous Dues / Net Balance rows on the flags from the API.'
);

$settingsPage = $read('resources/src/pages/settings/InvoicePdfSettings.vue');
$assert(
    str_contains($settingsPage, "key: 'show_previous_dues'") && str_contains($settingsPage, "key: 'show_net_balance'"),
    'InvoicePdfSettings.vue must offer the two new toggles in its Sections panel (sale doc type only).'
);
$assert(
    str_contains($settingsPage, "docType.value === 'sale'"),
    'InvoicePdfSettings.vue must only show the two new toggles for the sale doc type (quotation/purchase have no previous-dues concept).'
);

if ($failures) {
    fwrite(STDERR, "Build L2 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L2 regression gate: PASS (Previous Dues / Net Balance now independently togglable from Settings → Invoice PDF, applied consistently to Sale PDF, Sale Detail, and the public invoice page).\n";
