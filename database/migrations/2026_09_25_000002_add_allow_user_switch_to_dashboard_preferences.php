<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modern Dashboard: organisation setting "let users switch between Classic and Modern" (on the organisation row). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_preferences') && ! Schema::hasColumn('dashboard_preferences', 'allow_user_switch')) {
            Schema::table('dashboard_preferences', function (Blueprint $table) {
                $table->boolean('allow_user_switch')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dashboard_preferences', 'allow_user_switch')) {
            Schema::table('dashboard_preferences', function (Blueprint $table) {
                $table->dropColumn('allow_user_switch');
            });
        }
    }
};
