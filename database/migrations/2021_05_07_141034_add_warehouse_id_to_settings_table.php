<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWarehouseIdToSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('settings', 'warehouse_id')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->integer('warehouse_id')->after('client_id')->nullable()->index('2021_05_07_141034_add_warehouse_id_to_settings_table_warehouse_id_t1');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            //
        });
    }
}
