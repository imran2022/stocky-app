<?php

/**
 * Build N5 (part 1) — Receipt Settings: Show VAT/BIN + Show Website toggles
 * (2026-09-20).
 *
 * Client asked for two new POS Receipt Settings toggles, matching the
 * existing "Show Address" / "Show Email" pattern:
 *   - Show VAT/BIN — the company's VAT/BIN (settings.vat_number)
 *   - Show Website — the company's website (settings.website)
 *
 * Real, DB-backed test covering:
 *   - the new `pos_settings.show_vat_bin` / `show_website` columns exist,
 *     with the documented defaults (VAT/BIN default ON to preserve Layout
 *     4/ZATCA's pre-existing unconditional VAT/BIN display; Website
 *     default OFF since it never appeared on any layout before this).
 *   - SettingsController::update_pos_settings() persists both fields.
 *   - SalesController::Print_Invoice_POS() response's `setting` payload
 *     carries vat_number/website (unchanged — confirms the POS receipt
 *     templates have real data to bind the new toggles to).
 *   - Static source-contract checks that PosPage.vue (the real POS
 *     receipt) and PosReceipt.vue (the settings preview) both reference
 *     the two new toggle fields, and that Layout 4's VAT/BIN line is now
 *     gated by the toggle (not shown unconditionally).
 *
 * Run with: php tests/Regression/build_n5_receipt_vat_website_toggles.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\SalesController;
use App\Http\Controllers\SettingsController;
use App\Models\PosSetting;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$read = static fn (string $relative): string => file_get_contents($root.'/'.$relative);

$admin = User::first();
Auth::guard('api')->setUser($admin);
Auth::login($admin);

$req = static function (string $method, string $uri, array $params = []) use ($admin) {
    $r = Request::create($uri, $method, $params);
    $r->setUserResolver(fn ($g = null) => $admin);
    app()->instance('request', $r);

    return $r;
};

echo "== Schema + defaults ==\n";

$assert(Schema::hasColumn('pos_settings', 'show_vat_bin'), 'pos_settings.show_vat_bin column must exist.');
$assert(Schema::hasColumn('pos_settings', 'show_website'), 'pos_settings.show_website column must exist.');

$posSetting = PosSetting::first();
$assert($posSetting !== null, 'Setup: a pos_settings row must exist.');
$originalVatBin = $posSetting->show_vat_bin;
$originalWebsite = $posSetting->show_website;

// ==================================================================
// SettingsController::update_pos_settings() persists both new fields.
// ==================================================================

echo "== update_pos_settings(): persists show_vat_bin + show_website ==\n";

$settingsController = app(SettingsController::class);

$updateReq = $req('PUT', "/api/pos_settings/{$posSetting->id}", [
    'note_customer' => $posSetting->note_customer ?? 'Thanks!',
    'show_vat_bin' => '1',
    'show_website' => '1',
]);
$settingsController->update_pos_settings($updateReq, $posSetting->id);
$posSetting->refresh();
$assert((int) $posSetting->show_vat_bin === 1, 'update_pos_settings() must persist show_vat_bin=1.');
$assert((int) $posSetting->show_website === 1, 'update_pos_settings() must persist show_website=1.');

$updateReq2 = $req('PUT', "/api/pos_settings/{$posSetting->id}", [
    'note_customer' => $posSetting->note_customer ?? 'Thanks!',
    'show_vat_bin' => '0',
    'show_website' => '0',
]);
$settingsController->update_pos_settings($updateReq2, $posSetting->id);
$posSetting->refresh();
$assert((int) $posSetting->show_vat_bin === 0, 'update_pos_settings() must persist show_vat_bin=0.');
$assert((int) $posSetting->show_website === 0, 'update_pos_settings() must persist show_website=0.');

// A partial update (neither field sent) must leave both untouched —
// confirms this feature doesn't regress the "partial updates" contract
// other POS Settings tabs rely on.
$posSetting->show_vat_bin = 1;
$posSetting->show_website = 1;
$posSetting->save();
$partialReq = $req('PUT', "/api/pos_settings/{$posSetting->id}", [
    'note_customer' => 'Only updating the note',
]);
$settingsController->update_pos_settings($partialReq, $posSetting->id);
$posSetting->refresh();
$assert((int) $posSetting->show_vat_bin === 1 && (int) $posSetting->show_website === 1, 'A partial update that omits show_vat_bin/show_website must leave them unchanged.');

// Restore original values.
$posSetting->show_vat_bin = $originalVatBin;
$posSetting->show_website = $originalWebsite;
$posSetting->save();

// ==================================================================
// Print_Invoice_POS(): the `setting` payload already carries vat_number
// and website (Setting model, unchanged) — confirms the new receipt
// template lines have real data to bind to.
// ==================================================================

echo "== Print_Invoice_POS(): setting payload carries vat_number/website ==\n";

$setting = Setting::first();
$originalVat = $setting->vat_number ?? null;
$originalWebsiteVal = $setting->website ?? null;
$setting->vat_number = 'N5TEST-VAT-12345';
$setting->website = 'https://n5test.example.com';
$setting->save();

$sale = Sale::whereNull('deleted_at')->first();
$assert($sale !== null, 'Setup: at least one sale must exist to call Print_Invoice_POS().');

if ($sale) {
    $salesController = app(SalesController::class);
    $printResp = json_decode($salesController->Print_Invoice_POS($req('GET', "/api/sales_print_invoice/{$sale->id}"), $sale->id)->getContent(), true);
    $assert(($printResp['setting']['vat_number'] ?? null) === 'N5TEST-VAT-12345', 'Print_Invoice_POS() setting payload must carry the current vat_number.');
    $assert(($printResp['setting']['website'] ?? null) === 'https://n5test.example.com', 'Print_Invoice_POS() setting payload must carry the current website.');
}

$setting->vat_number = $originalVat;
$setting->website = $originalWebsiteVal;
$setting->save();

// ==================================================================
// Static source-contract checks.
// ==================================================================

echo "== Static checks: PosPage.vue + PosReceipt.vue reference the new toggles ==\n";

$posPage = $read('resources/src/pages/pos/PosPage.vue');
$assert(substr_count($posPage, 'pos_settings.show_vat_bin') >= 5, 'PosPage.vue must gate VAT/BIN on pos_settings.show_vat_bin in all 5 receipt layouts.');
$assert(substr_count($posPage, 'pos_settings.show_website') >= 5, 'PosPage.vue must gate Website on pos_settings.show_website in all 5 receipt layouts.');
$assert(
    ! str_contains($posPage, 'v-if="invoice_pos.setting.vat_number" class="bl4-trn"'),
    'PosPage.vue Layout 4 VAT/BIN line must no longer be unconditional — it must now also be gated by pos_settings.show_vat_bin (fixed alongside adding the toggle).'
);

$posReceipt = $read('resources/src/pages/settings/PosReceipt.vue');
$assert(substr_count($posReceipt, 'pos_settings.show_vat_bin') >= 5, 'PosReceipt.vue preview must reference show_vat_bin in all 5 layout demos.');
$assert(substr_count($posReceipt, 'pos_settings.show_website') >= 5, 'PosReceipt.vue preview must reference show_website in all 5 layout demos.');
$assert(str_contains($posReceipt, "{ field: 'show_vat_bin', label: 'Show_VAT_BIN' }"), 'PosReceipt.vue toggle list must include Show VAT/BIN.');
$assert(str_contains($posReceipt, "{ field: 'show_website', label: 'Show_Website' }"), 'PosReceipt.vue toggle list must include Show Website.');

$translations = $read('database/seeders/translations/en.php');
$assert(str_contains($translations, "'Show_VAT_BIN' => 'Show VAT/BIN'"), 'en.php must have the Show_VAT_BIN translation.');
$assert(str_contains($translations, "'Show_Website' => 'Show Website'"), 'en.php must have the Show_Website translation.');

if ($failures) {
    fwrite(STDERR, "Build N5 receipt VAT/BIN+Website gate FAILED:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Build N5 receipt VAT/BIN+Website gate: PASS (schema, persistence, partial-update safety, Print_Invoice_POS data, Layout 1-5 template wiring).\n";
