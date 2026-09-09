<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Lookup table for the "Zone" dropdown (e.g. delivery zone / area).
        if (! Schema::hasTable('sale_zones')) {
            Schema::create('sale_zones', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->unique('name');
            });
        }

        // Lookup table for the "Courier" dropdown (e.g. Pathao, Steadfast, RedX...).
        if (! Schema::hasTable('sale_couriers')) {
            Schema::create('sale_couriers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
                $table->unique('name');
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'tracking_ref')) {
                $table->string('tracking_ref')->nullable()->after('Ref');
            }
            if (! Schema::hasColumn('sales', 'zone_id')) {
                $table->unsignedBigInteger('zone_id')->nullable()->after('tracking_ref')->index();
            }
            if (! Schema::hasColumn('sales', 'courier_id')) {
                $table->unsignedBigInteger('courier_id')->nullable()->after('zone_id')->index();
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('zone_id', 'sales_zone_id_foreign')
                ->references('id')->on('sale_zones')
                ->onUpdate('CASCADE')->onDelete('SET NULL');

            $table->foreign('courier_id', 'sales_courier_id_foreign')
                ->references('id')->on('sale_couriers')
                ->onUpdate('CASCADE')->onDelete('SET NULL');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign('sales_zone_id_foreign');
            $table->dropForeign('sales_courier_id_foreign');
            $table->dropColumn(['tracking_ref', 'zone_id', 'courier_id']);
        });

        Schema::dropIfExists('sale_couriers');
        Schema::dropIfExists('sale_zones');
    }
};
