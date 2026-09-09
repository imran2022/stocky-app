<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'phone_number')) {
                // Delivery contact number — can differ from the customer's
                // account phone (e.g. gift delivery to someone else).
                $table->string('phone_number')->nullable()->after('delivered_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
};
