<?php

/**
 * Build K.1 no-dependency regression gate.
 *
 * Adds "VAT/BIN ID" and "Website" to General Settings and surfaces both
 * across printed/shared documents (invoices, returns, payments, PO,
 * shipping label, public invoice link, quotation, reports, service docs).
 *
 * Run with: php tests/Regression/build_k1_company_vat_website.php
 */

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message);
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

// --- Migration ---
$migrationFiles = glob($root.'/database/migrations/*_add_website_field_to_settings_table.php');
$assert(count($migrationFiles) === 1, 'Exactly one migration must add the settings.website column.');
if ($migrationFiles) {
    $migration = file_get_contents($migrationFiles[0]);
    $contains($migration, "Schema::hasColumn('settings', 'website')", 'Migration must guard the website column with hasColumn.');
}

// --- Model ---
$model = $read('app/Models/Setting.php');
$contains($model, "'vat_number', 'website', 'company_name_ar'", 'Setting::$fillable must include website alongside vat_number.');

// --- Controller: save path + both read endpoints ---
$settingsController = $read('app/Http/Controllers/SettingsController.php');
$contains($settingsController, "'website' => \$request['website'] ?? \$setting->website,", 'Settings update() must persist website.');
$assert(
    substr_count($settingsController, "\$item['website'] = \$settings->website;") === 2,
    'Both settings read endpoints (get_Settings_data_api and getSettings) must return website.'
);

// --- General Settings UI ---
$systemSettingsVue = $read('resources/src/pages/settings/SystemSettings.vue');
$contains($systemSettingsVue, 'v-model:value="setting.vat_number"', 'General tab must expose a VAT/BIN ID input.');
$contains($systemSettingsVue, 'v-model:value="setting.website"', 'General tab must expose a Website input.');
$contains($systemSettingsVue, "fd.append('website', s.website || '');", 'Save payload must include website.');

// --- Lang keys ---
$contains($read('resources/lang/en/pdf.php'), "'vat_number' => 'VAT/BIN No'", 'English pdf lang file must define vat_number label.');
$contains($read('resources/lang/en/pdf.php'), "'website' => 'Website'", 'English pdf lang file must define website label.');
$contains($read('resources/lang/ar/pdf.php'), "'vat_number' =>", 'Arabic pdf lang file must define vat_number label.');

// --- Document templates: each must reference both fields ---
$docsUsingSettingArray = [
    'resources/views/pdf/Payment_Purchase_Return.blade.php',
    'resources/views/pdf/Payment_Sale_Return.blade.php',
    'resources/views/pdf/Purchase_Return_pdf.blade.php',
    'resources/views/pdf/Sales_Return_pdf.blade.php',
    'resources/views/pdf/booking_pdf.blade.php',
    'resources/views/pdf/payment_sale.blade.php',
    'resources/views/pdf/payments_purchase.blade.php',
    'resources/views/pdf/purchase_pdf.blade.php',
    'resources/views/pdf/quotation_pdf.blade.php',
    'resources/views/pdf/report_client_pdf.blade.php',
    'resources/views/pdf/report_provider_pdf.blade.php',
    'resources/views/pdf/sale_pdf.blade.php',
    'resources/views/pdf/service_job_pdf.blade.php',
    'resources/views/pdf/service_quote_pdf.blade.php',
];
foreach ($docsUsingSettingArray as $rel) {
    $body = $read($rel);
    $contains($body, "vat_number", "$rel must display VAT/BIN when set.");
    $contains($body, "website", "$rel must display Website when set.");
}

$contains($read('resources/views/invoice/public_invoice.blade.php'), '$setting->website', 'Public (ZATCA) invoice must show website.');
$contains($read('resources/views/pdf/shipping_label.blade.php'), "\$company['vat_number']", 'Shipping label must show VAT/BIN.');
$contains($read('resources/views/pdf/shipping_label.blade.php'), "\$company['website']", 'Shipping label must show website.');
$contains($read('resources/views/pdf/po_pdf.blade.php'), "\$company['vat_number']", 'Purchase Order PDF must show VAT/BIN.');
$contains($read('resources/views/pdf/online_order_invoice.blade.php'), '$companyVat', 'Online order invoice must show VAT/BIN.');
$contains($read('resources/views/pdf/online_order_invoice.blade.php'), '$companyWebsite', 'Online order invoice must show website.');

// --- Controllers that build a curated `company` array (not the full model) ---
$contains($read('app/Http/Controllers/PublicInvoiceController.php'), "'vat_number' => \$setting->vat_number ?? ''", 'Public invoice API must expose vat_number.');
$contains($read('app/Http/Controllers/PublicInvoiceController.php'), "'website' => \$setting->website ?? ''", 'Public invoice API must expose website.');
$contains($read('app/Http/Controllers/PurchaseOrderController.php'), "'vat_number' => \$settings->vat_number,", 'PO PDF controller must pass vat_number into the company array.');
$contains($read('app/Http/Controllers/ReportController.php'), "'vat_number' => \$settings->vat_number ?? ''", 'Day/Products-sold report must expose vat_number.');

// --- Frontend consumers of curated `company` payloads ---
$contains($read('resources/src/pages/public/PublicInvoice.vue'), 'data.company.vat_number', 'Public invoice page must render vat_number.');
$contains($read('resources/src/pages/public/PublicInvoice.vue'), 'data.company.website', 'Public invoice page must render website.');
$contains($read('resources/src/pages/reports/ProductsSoldSummaryReport.vue'), 'company.value.vat_number', 'Printed day report must render vat_number.');
$contains($read('resources/src/pages/reports/ProductsSoldSummaryReport.vue'), 'company.value.website', 'Printed day report must render website.');

if ($failures) {
    fwrite(STDERR, "Build K.1 regression gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build K.1 regression gate: PASS (VAT/BIN + Website wired through settings, save path, and all document templates).\n";
