<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modern Dashboard preferences (own table: no vendor table is touched, so a vendor update cannot collide).
 *
 * One row per user (user_id set) plus at most one organisation-default row (user_id NULL, kept by code).
 *  - style  : 'classic' | 'modern' | NULL (NULL = "not chosen, inherit")
 *  - layout : JSON {"order":[section ids],"hidden":[section ids]} | NULL (NULL = inherit)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_preferences')) {
            return;
        }
        Schema::create('dashboard_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->unique();
            $table->string('style', 16)->nullable();
            $table->longText('layout')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_preferences');
    }
};
