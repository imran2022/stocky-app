<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a dedicated PO numbering prefix, following the exact convention of
 * add_prefixes_to_settings_table.php (sale_prefix, purchase_prefix, etc).
 * Kept as its own migration/column rather than reusing purchase_prefix so a
 * PO's reference is visually distinct from a GRN's (e.g. PO_0001 vs
 * PR_0001) even though both are ultimately procurement documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'po_prefix')) {
                $table->string('po_prefix', 10)->nullable()->default('PO')->after('purchase_prefix');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'po_prefix')) {
                $table->dropColumn('po_prefix');
            }
        });
    }
};
