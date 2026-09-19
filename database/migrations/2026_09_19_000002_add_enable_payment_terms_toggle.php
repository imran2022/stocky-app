<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_payment_terms')) {
                // Master on/off switch for the whole Payment Terms & Due Dates
                // feature (Build M1). Default true because the feature already
                // ships active; turning it off hides the Payment Term controls
                // on the Customer form, Sale form, Sale Detail page, and the
                // Invoice PDF's due-date line, and stops new/edited sales from
                // resolving or snapshotting a term/due date at all.
                $table->boolean('enable_payment_terms')->default(true)->after('default_payment_term_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('enable_payment_terms');
        });
    }
};
