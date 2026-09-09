<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'consignment_id')) {
                // Courier-issued consignment/shipment number — distinct from our
                // own tracking_ref (which the user types themselves).
                $table->string('consignment_id')->nullable()->after('courier_id')->index();
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_box_qty')) {
                // Toggle for the "Box" quantity field on Create/Edit Sale, Sale
                // Detail, and the invoice PDF — a business-specific feature, not
                // every store needs it. Defaults to enabled so existing behavior
                // (already shipped) doesn't change until someone flips it off.
                $table->boolean('enable_box_qty')->default(true)->after('enable_multi_pack_selling');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('consignment_id');
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('enable_box_qty');
        });
    }
};
