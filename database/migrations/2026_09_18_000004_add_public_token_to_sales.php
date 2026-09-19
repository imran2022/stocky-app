<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A random, unguessable token per sale for the public (no-login) invoice
 * link. Deliberately NOT the sale's own id or Ref — those are sequential/
 * predictable, and this token is meant to be shared outside the app
 * (emailed to a customer, put in a message), so guessing another
 * customer's invoice by trying nearby numbers must not be possible.
 *
 * Nullable: generated lazily on first request for a "Public Link" (see
 * PublicInvoiceLinkService), not backfilled for every existing sale —
 * there is no link to leak for a sale nobody has ever asked to share yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'public_token')) {
                $table->string('public_token', 64)->nullable()->unique()->after('Ref');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'public_token')) {
                $table->dropColumn('public_token');
            }
        });
    }
};
