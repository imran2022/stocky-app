<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a GRN (an existing `purchases` row) back to the PO it was received
 * against, at both the header and line level. Both columns are nullable:
 * a GRN created without selecting a PO (the app's existing, unchanged
 * "Create Purchase" flow) simply has NULL here and behaves exactly as
 * before — this feature is additive, not a replacement for direct/no-PO
 * purchasing.
 *
 * purchase_order_detail_id (not just purchase_order_id) is required at the
 * line level because a single GRN can partially fulfil several different PO
 * lines at once, and PurchaseOrderController::applyReceipt() needs to know
 * exactly which PO line each received line corresponds to in order to
 * increment the correct received_quantity and detect a price mismatch
 * against that specific line's ordered cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'purchase_order_id')) {
                $table->integer('purchase_order_id')->nullable()->index('purchases_purchase_order_id');
            }
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_details', 'purchase_order_detail_id')) {
                $table->integer('purchase_order_detail_id')->nullable()->index('purchase_details_po_detail_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'purchase_order_id')) {
                $table->dropColumn('purchase_order_id');
            }
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_details', 'purchase_order_detail_id')) {
                $table->dropColumn('purchase_order_detail_id');
            }
        });
    }
};
