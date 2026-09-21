<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('real_time_sales_displays')) {
            return;
        }

        Schema::table('real_time_sales_displays', function (Blueprint $table) {
            $table->string('layout_profile', 20)->default('standard')->after('show_customer_names');
            $table->timestamp('last_successful_sync_at')->nullable()->after('last_seen_at')->index();
            $table->unsignedSmallInteger('last_failure_count')->default(0)->after('last_successful_sync_at');
            $table->timestamp('last_recovered_at')->nullable()->after('last_failure_count');
            $table->timestamp('archived_at')->nullable()->after('revoked_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('real_time_sales_displays')) {
            return;
        }

        Schema::table('real_time_sales_displays', function (Blueprint $table) {
            $table->dropColumn([
                'layout_profile',
                'last_successful_sync_at',
                'last_failure_count',
                'last_recovered_at',
                'archived_at',
            ]);
        });
    }
};
