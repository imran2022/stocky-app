<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build I1 — Activity Log (Phase 1: Sales, Purchases, Products, Customers).
 *
 * One row per create/update/delete on a tracked model. Login events are
 * NOT stored here — they already live in `user_login_sessions` (added
 * earlier for the self-service Login Activity Report / Login Device
 * Management pages) and the new admin-facing report reads that table
 * directly rather than duplicating it. Failed-login tracking is a
 * separate, later build — not in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Nullable: a system/console action (or a user later deleted)
            // should not be lost just because the actor can't be resolved.
            $table->integer('user_id')->nullable()->index();

            // 'Sale', 'Purchase', 'Product', 'Customer' — matches the report's
            // Module filter. Kept as a plain string rather than an enum so a
            // future module can be added without a schema change.
            $table->string('module', 40)->index();

            // 'created', 'updated', 'deleted'
            $table->string('action', 20)->index();

            // Polymorphic pointer to the actual record, so the report can
            // link back to it (and so old/new values are meaningful without
            // needing the module string parsed).
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);

            // Human-readable line for the table, e.g. "Sale #1042 updated" —
            // built once at write time so the report doesn't need to
            // reconstruct it (and remains readable even if the subject is
            // later hard-deleted).
            $table->string('description', 500);

            // Changed-fields snapshot only (not the full row) — see
            // ActivityLogger::diff(). Null for 'created' (new_values holds
            // the full row instead) and typically null for 'deleted'.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();
            // No updated_at — a log row is never edited after the fact.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
