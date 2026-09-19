<?php

/**
 * Build L3 — Sales Invoice: multiple selectable PDF templates (2026-09-19).
 *
 * Adds a second Blade layout for the Sales Invoice PDF — "Modern (with
 * Shipping Label)", supplied by the user as a ready-made design — alongside
 * the existing "Classic" one, selectable from Settings → Invoice PDF →
 * Sales Invoice → Template. `PdfTemplate::LAYOUTS['sale']` lists the
 * choices; `SalesController::saleInvoiceViewName()` resolves the saved
 * choice to a Blade view name, falling back to Classic for any
 * unrecognized value. All three call sites that used to hardcode
 * `view('pdf.sale_pdf', ...)` (Sale_PDF, Sale_PDF_Inline, and the bulk
 * renderSaleInvoiceHtml helper) now go through that one method, so the
 * chosen template applies everywhere the Sales Invoice PDF is produced —
 * including the public invoice page's Download button, which delegates to
 * Sale_PDF(). The Modern layout comes fully self-styled (its own colors/
 * fonts) and does not read the Colors/Typography/Layout/Items-table panels
 * of the Invoice PDF customizer — only the Previous Dues/Net Balance
 * toggle (Build L2) was added to it, so that setting stays in sync across
 * both layouts.
 *
 * This is a static source-contract check (see docs/CUSTOMIZATION_CHECKLIST.md
 * section 15 for what that does and doesn't catch); it was additionally
 * verified once against a real seeded database: rendered the actual PDF
 * (via SalesController::Sale_PDF()) with the setting on 'modern' and
 * confirmed — via pdftotext on the real output bytes — the Modern layout's
 * distinctive "DELIVERY INFORMATION" / "CASH ON DELIVERY" shipping-label
 * section appears, and that Previous Dues / Net Balance honor the Build L2
 * toggle inside this layout too; then switched back to 'classic' and
 * confirmed the shipping-label section is absent and Previous Dues/Net
 * Balance still render correctly (no regression).
 *
 * Run with: php tests/Regression/build_l3_multi_template_sale_pdf.php
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

$model = $read('app/Models/PdfTemplate.php');
$assert(str_contains($model, "'layout' => 'classic'"), "PdfTemplate::DEFAULTS must default 'layout' to 'classic' (no behavior change for existing installs).");
$assert(str_contains($model, 'LAYOUTS'), 'PdfTemplate must expose a LAYOUTS map for the UI to list choices from.');
$assert(str_contains($model, "'modern' => "), "PdfTemplate::LAYOUTS['sale'] must include the 'modern' option.");

$assert($exists('resources/views/pdf/sale_pdf_modern.blade.php'), 'The Modern Sale Invoice Blade template must exist.');
$modern = $read('resources/views/pdf/sale_pdf_modern.blade.php');
$assert(
    str_contains($modern, "PdfTemplate::settingsFor('sale')") && str_contains($modern, "show_previous_dues") && str_contains($modern, 'show_net_balance'),
    'sale_pdf_modern.blade.php must honor the Build L2 Previous Dues / Net Balance toggle, same as the Classic layout.'
);

$controller = $read('app/Http/Controllers/SalesController.php');
$assert(
    str_contains($controller, 'function saleInvoiceViewName'),
    'SalesController must have a single saleInvoiceViewName() resolving the saved layout choice.'
);
$assert(
    substr_count($controller, 'view($this->saleInvoiceViewName(),') === 3,
    'All three Sale Invoice PDF/HTML render call sites (Sale_PDF, Sale_PDF_Inline, renderSaleInvoiceHtml) must go through saleInvoiceViewName() — a hardcoded view(\'pdf.sale_pdf\', ...) left anywhere would silently ignore the Template setting there.'
);
$assert(
    ! str_contains($controller, "view('pdf.sale_pdf',"),
    'No call site should still hardcode the Classic view name directly.'
);

$pdfTemplateController = $read('app/Http/Controllers/PdfTemplateController.php');
$assert(
    str_contains($pdfTemplateController, "'layouts' => PdfTemplate::LAYOUTS[\$type]"),
    'PdfTemplateController::show() must expose the available layouts for the chosen doc type so the UI can list them.'
);

$settingsPage = $read('resources/src/pages/settings/InvoicePdfSettings.vue');
$assert(
    str_contains($settingsPage, 'layoutOptions') && str_contains($settingsPage, 'form.layout'),
    'InvoicePdfSettings.vue must offer a Template selector bound to form.layout.'
);
$assert(
    str_contains($settingsPage, "form.layout !== 'classic'"),
    'InvoicePdfSettings.vue must not show its (Classic-only) live preview as if it applied to a non-classic layout.'
);

if ($failures) {
    fwrite(STDERR, "Build L3 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build L3 regression gate: PASS (Sales Invoice PDF now supports multiple selectable templates — Classic and Modern — applied consistently everywhere the PDF is generated).\n";
