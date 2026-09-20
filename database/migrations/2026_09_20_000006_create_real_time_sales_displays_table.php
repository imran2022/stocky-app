<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('real_time_sales_displays')) {
            return;
        }

        Schema::create('real_time_sales_displays', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->integer('warehouse_id')->nullable()->index();
            // Permission/warehouse snapshot used by the public read-only link.
            $table->text('warehouse_ids');
            $table->unsignedSmallInteger('refresh_seconds')->default(30);
            $table->boolean('show_customer_names')->default(false);
            $table->integer('created_by')->index();
            $table->integer('scope_user_id')->index();
            $table->boolean('view_all_records')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_time_sales_displays');
    }
};
