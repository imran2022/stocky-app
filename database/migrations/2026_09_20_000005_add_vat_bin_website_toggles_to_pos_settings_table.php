<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User request: Receipt Settings should let the business show/hide their
// VAT/BIN and Website on the POS receipt — matching the existing
// "Show Address" / "Show Email" toggle pattern. The company's actual
// VAT/BIN and Website values already live on the `settings` table
// (vat_number / website — added for the earlier VAT/BIN + Website
// customization) and are already sent to the receipt in the `setting`
// payload; this migration only adds the two new per-field visibility
// toggles on `pos_settings`, following the exact same pattern as the
// 2025_12_11 "receipt section toggles" migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // Default ON: Layout 4 (Bilingual AR+EN / ZATCA) already shows the
            // VAT/BIN line unconditionally whenever `settings.vat_number` is
            // set, with no toggle at all. Wiring that display to this new
            // toggle (so every layout can control it) must default to 1, or
            // existing ZATCA-compliant receipts would silently stop showing
            // their VAT/BIN the moment this migration runs.
            $table->boolean('show_vat_bin')->default(1)->after('show_address');
            // Default OFF: Website was never shown on any receipt layout
            // before this, so introducing it hidden-by-default changes
            // nothing until the business opts in.
            $table->boolean('show_website')->default(0)->after('show_vat_bin');
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn(['show_vat_bin', 'show_website']);
        });
    }
};
