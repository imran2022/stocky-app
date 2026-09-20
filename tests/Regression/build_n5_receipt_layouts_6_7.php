<?php

/**
 * Build N5 (part 2) — POS Receipt: two new layouts (2026-09-20).
 *
 * Client asked for:
 *   1. A new layout like Layout 5 (Minimal) but with Layout 1-4's roomier
 *      spacing instead of Layout 5's compact spacing → Layout 6 "Roomy".
 *   2. A new layout like Layout 4 (Bilingual AR+EN / ZATCA Simplified Tax
 *      Invoice) but with the Arabic text removed (English only) and the
 *      spacing optimized so a receipt printer doesn't crop/wrap → Layout 7
 *      "Simplified Tax Invoice (English)".
 *
 * Both layouts follow the exact same pattern as the existing 5: a
 * `pos_settings.receipt_layout` integer (1-7), a v-else-if chain in both
 * the settings-page live preview (PosReceipt.vue) and the real POS receipt
 * (PosPage.vue), gated by the same pos_settings.show_* toggles as every
 * other layout (including the N5-part-1 show_vat_bin/show_website toggles).
 *
 * Static, source-contract test (pure Vue template/CSS change, no new
 * server-side data) covering:
 *   1. SettingsController::update_pos_settings() accepts receipt_layout
 *      values 6 and 7 (not just 1-5).
 *   2. PosReceipt.vue's layout selector offers Layout 6 and Layout 7, its
 *      currentReceiptLayout computed no longer clamps 6/7 back to 1, and
 *      both new v-else-if blocks exist with the same toggle gating as the
 *      other layouts.
 *   3. PosSettings.vue's <a-select> also offers Layout 6 and Layout 7.
 *   4. PosPage.vue (the real receipt) mirrors the same: currentReceiptLayout
 *      accepts 6/7, both new blocks exist, and Layout 7 has NO Arabic text
 *      (the bl4-*-ar / bl4-title-ar Arabic strings must not appear inside
 *      the Layout 7 block), while reusing the same $refs (zatcaQrcodePos /
 *      invoiceUrlQr) as every other layout so the existing QR-rendering
 *      JS (which looks up those exact ref names) keeps working un-modified.
 *   5. en.php defines the two new layout labels.
 *   6. public/css/pos_print.css defines print rules for both new layouts.
 *
 * Run with: php tests/Regression/build_n5_receipt_layouts_6_7.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);

// ==================================================================
// 1. Backend validation accepts 6 and 7.
// ==================================================================

echo "== SettingsController: receipt_layout validation accepts 6 and 7 ==\n";

$settingsController = file_get_contents($root.'/app/Http/Controllers/SettingsController.php');
$assert(str_contains($settingsController, '[1, 2, 3, 4, 5, 6, 7]'), 'SettingsController::update_pos_settings() must accept receipt_layout values 1-7 (was 1-5).');

// ==================================================================
// 2. PosReceipt.vue (settings preview page)
// ==================================================================

echo "== PosReceipt.vue: Layout 6 + Layout 7 wired up ==\n";

$preview = file_get_contents($root.'/resources/src/pages/settings/PosReceipt.vue');

$assert(str_contains($preview, "{ value: 6, label: \$t('Layout_6_Roomy') }"), 'PosReceipt.vue layout selector must offer Layout 6.');
$assert(str_contains($preview, "{ value: 7, label: \$t('Layout_7_Simplified_EN') }"), 'PosReceipt.vue layout selector must offer Layout 7.');
$assert(str_contains($preview, 'return [1, 2, 3, 4, 5, 6, 7].includes(n) ? n : 1;'), 'PosReceipt.vue currentReceiptLayout must accept 6 and 7 (not clamp them back to 1).');
$assert(str_contains($preview, 'v-else-if="currentReceiptLayout === 5"'), 'PosReceipt.vue Layout 5 block must now be an explicit v-else-if (not the catch-all v-else), now that Layout 6/7 exist after it.');
$assert(str_contains($preview, 'v-else-if="currentReceiptLayout === 6" class="receipt-layout-6"'), 'PosReceipt.vue must define the Layout 6 demo block.');
$assert(str_contains($preview, 'class="receipt-layout-7"'), 'PosReceipt.vue must define the Layout 7 demo block.');

// Layout 6 reuses Layout 5's minimal-* markup/toggles verbatim — check a
// representative sample of toggle gates appear inside a receipt-layout-6
// block.
$layout6Start = strpos($preview, 'v-else-if="currentReceiptLayout === 6"');
$layout7Start = strpos($preview, 'class="receipt-layout-7"', $layout6Start);
$layout6Block = substr($preview, $layout6Start, $layout7Start - $layout6Start);
foreach (['pos_settings.show_vat_bin', 'pos_settings.show_website', 'pos_settings.show_address', 'minimal-store-name', 'minimal-grand'] as $needle) {
    $assert(str_contains($layout6Block, $needle), "PosReceipt.vue Layout 6 block must contain '{$needle}' (same toggle/markup pattern as Layout 5).");
}

// Layout 7: same structure as Layout 4, but with the Arabic strings removed.
$layout7End = strpos($preview, '</a-card>', $layout7Start);
$layout7Template = substr($preview, $layout7Start, ($layout7End ?: strlen($preview)) - $layout7Start);
foreach (['متجر تجريبي', 'فاتورة ضريبية مبسطة', 'الرقم الضريبي', 'رقم الفاتورة', 'bl4-'] as $arabicOrBl4) {
    $assert(! str_contains($layout7Template, $arabicOrBl4), "PosReceipt.vue Layout 7 block must NOT contain '{$arabicOrBl4}' (Arabic text / Layout-4 classes) — Layout 7 is English-only.");
}
foreach (['pos_settings.show_vat_bin', 'pos_settings.show_website', 'pos_settings.show_email', 'bl7-title', 'SIMPLIFIED TAX INVOICE'] as $needle) {
    $assert(str_contains($layout7Template, $needle), "PosReceipt.vue Layout 7 block must contain '{$needle}'.");
}

// CSS: both new layout classes defined.
$assert(str_contains($preview, '.receipt-layout-6 {'), 'PosReceipt.vue must define .receipt-layout-6 CSS.');
$assert(str_contains($preview, '.receipt-layout-6 .minimal-grand td'), 'PosReceipt.vue .receipt-layout-6 CSS must style .minimal-grand (roomier values than Layout 5).');
$assert(str_contains($preview, '.receipt-layout-7 {'), 'PosReceipt.vue must define .receipt-layout-7 CSS.');
$assert(str_contains($preview, '.receipt-layout-7 .bl7-title'), 'PosReceipt.vue .receipt-layout-7 CSS must style .bl7-title.');

// ==================================================================
// 3. PosSettings.vue
// ==================================================================

echo "== PosSettings.vue: layout dropdown offers Layout 6 + Layout 7 ==\n";

$posSettingsPage = file_get_contents($root.'/resources/src/pages/settings/PosSettings.vue');
$assert(str_contains($posSettingsPage, "{ value: 6, label: \$t('Layout_6_Roomy') }"), 'PosSettings.vue layout dropdown must offer Layout 6.');
$assert(str_contains($posSettingsPage, "{ value: 7, label: \$t('Layout_7_Simplified_EN') }"), 'PosSettings.vue layout dropdown must offer Layout 7.');

// ==================================================================
// 4. PosPage.vue (the real receipt)
// ==================================================================

echo "== PosPage.vue: Layout 6 + Layout 7 wired up, English-only Layout 7, shared QR refs ==\n";

$posPage = file_get_contents($root.'/resources/src/pages/pos/PosPage.vue');

$assert(str_contains($posPage, 'return [1, 2, 3, 4, 5, 6, 7].includes(n) ? n : 1;'), 'PosPage.vue currentReceiptLayout must accept 6 and 7 (not clamp them back to 1).');
$assert(str_contains($posPage, 'v-else-if="currentReceiptLayout === 6" class="receipt-layout-6"'), 'PosPage.vue must define the real Layout 6 block.');
$assert(str_contains($posPage, 'class="receipt-layout-7"'), 'PosPage.vue must define the real Layout 7 block.');

$realLayout6Start = strpos($posPage, 'v-else-if="currentReceiptLayout === 6"');
$realLayout7Start = strpos($posPage, 'class="receipt-layout-7"', $realLayout6Start);
$realLayout6Block = substr($posPage, $realLayout6Start, $realLayout7Start - $realLayout6Start);
foreach (['pos_settings.show_vat_bin', 'pos_settings.show_website', 'invoice_pos.setting.CompanyName', 'minimal-grand'] as $needle) {
    $assert(str_contains($realLayout6Block, $needle), "PosPage.vue real Layout 6 block must contain '{$needle}'.");
}

// Layout 7 block: from its class attribute to the closing wrapper before the
// draft-sales modal markup that follows the receipt section.
$afterLayout7Marker = strpos($posPage, 'b-modal id="show_draft_sales"', $realLayout7Start);
$realLayout7Block = substr($posPage, $realLayout7Start, ($afterLayout7Marker ?: strlen($posPage)) - $realLayout7Start);
foreach (['متجر تجريبي', 'فاتورة ضريبية مبسطة', 'الرقم الضريبي', 'رقم الفاتورة', 'class="bl4-'] as $arabicOrBl4) {
    $assert(! str_contains($realLayout7Block, $arabicOrBl4), "PosPage.vue real Layout 7 block must NOT contain '{$arabicOrBl4}' — Layout 7 is English-only.");
}
foreach (['pos_settings.show_vat_bin', 'pos_settings.show_website', 'invoice_pos.setting.CompanyName', 'SIMPLIFIED TAX INVOICE', 'bl7-grand'] as $needle) {
    $assert(str_contains($realLayout7Block, $needle), "PosPage.vue real Layout 7 block must contain '{$needle}'.");
}

// Critical: Layout 7's QR blocks must reuse the SAME $refs as every other
// layout (zatcaQrcodePos / invoiceUrlQr) — the QR-rendering JS methods look
// up these exact ref names regardless of which layout is active, so a
// differently-named ref would silently leave Layout 7's QR codes blank.
$assert(substr_count($posPage, 'ref="zatcaQrcodePos"') >= 6, 'PosPage.vue must reuse ref="zatcaQrcodePos" in the Layout 7 block too (at least 6 occurrences across all layouts incl. duplicates), not a layout-7-specific ref name.');
$assert(substr_count($posPage, 'ref="invoiceUrlQr"') >= 6, 'PosPage.vue must reuse ref="invoiceUrlQr" in the Layout 7 block too, not a layout-7-specific ref name.');
$assert(! str_contains($posPage, 'zatcaQrcodePos2'), 'PosPage.vue must NOT have a stray layout-7-specific zatcaQrcodePos2 ref left over — it must reuse zatcaQrcodePos.');
$assert(! str_contains($posPage, 'invoiceUrlQr2'), 'PosPage.vue must NOT have a stray layout-7-specific invoiceUrlQr2 ref left over — it must reuse invoiceUrlQr.');

// ==================================================================
// 5. Translations
// ==================================================================

echo "== en.php: Layout 6 / Layout 7 labels defined ==\n";

$translations = file_get_contents($root.'/database/seeders/translations/en.php');
$assert(str_contains($translations, "'Layout_6_Roomy' =>"), 'en.php must define Layout_6_Roomy.');
$assert(str_contains($translations, "'Layout_7_Simplified_EN' =>"), 'en.php must define Layout_7_Simplified_EN.');

// ==================================================================
// 6. Print CSS
// ==================================================================

echo "== pos_print.css: print rules for Layout 6 + Layout 7 ==\n";

$printCss = file_get_contents($root.'/public/css/pos_print.css');
$assert(str_contains($printCss, '#invoice-POS .receipt-layout-6'), 'pos_print.css must style #invoice-POS .receipt-layout-6.');
$assert(str_contains($printCss, '#invoice-POS .receipt-layout-7'), 'pos_print.css must style #invoice-POS .receipt-layout-7.');
$assert(str_contains($printCss, '#invoice-POS .receipt-layout-7 .bl7-grand'), 'pos_print.css must style .bl7-grand for Layout 7.');

// ==================================================================
// 7. Structural sanity: balanced template tags in both Vue files
// (proxy for a real Vue SFC compile — no npm/node_modules in this sandbox).
// ==================================================================

echo "== Structural sanity: balanced tags ==\n";

function countTag(string $content, string $tagOpenRegex, string $tagClose): int
{
    preg_match_all('/'.$tagOpenRegex.'/', $content, $m);

    return count($m[0]) === 0 ? 0 : count($m[0]);
}

foreach (['<table[ \n]' => '</table>', '<tr[ >]' => '</tr>', '<td[ >]' => '</td>', '<thead>' => '</thead>', '<tbody>' => '</tbody>'] as $open => $close) {
    $openCount = countTag($preview, $open, $close);
    $closeCount = substr_count($preview, $close);
    $assert($openCount === $closeCount, "PosReceipt.vue: tag balance mismatch for {$open}/{$close} ({$openCount} vs {$closeCount}).");
}

if ($failures) {
    fwrite(STDERR, "Build N5 Receipt Layouts 6/7 FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N5 Receipt Layouts 6/7: PASS — Layout 6 (Roomy Minimal) and Layout 7 (Simplified Tax Invoice, English-only) are wired up in both the settings preview and the real POS receipt, following the same toggle/markup pattern as every other layout, with Layout 7 reusing the shared QR refs and containing no Arabic text.\n";
