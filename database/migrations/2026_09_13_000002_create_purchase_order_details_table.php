<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order line items.
 *
 * `received_quantity` is a denormalized running total — the sum of every
 * linked GRN line's quantity received against this PO line so far. It is
 * maintained exclusively by PurchaseOrderController::applyReceipt() at the
 * moment a GRN is saved; nothing else should write to this column. Keeping
 * it denormalized (rather than always summing purchase_details on read)
 * keeps the PO list's Received % column and the "how much is left to
 * receive on this line" check in the GRN form both O(1) per line instead of
 * an aggregate query per row.
 *
 * Column shape otherwise mirrors purchase_details for the same reason the
 * header mirrors purchases: existing price/discount/tax helpers apply with
 * no special-casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_details', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->integer('purchase_order_id')->index('purchase_order_details_po_id');
            $table->integer('product_id')->index('purchase_order_details_product_id');
            $table->integer('product_variant_id')->nullable()->index('purchase_order_details_variant_id');
            $table->float('cost', 10, 0);
            $table->float('TaxNet', 10, 0)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->float('discount', 10, 0)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->float('quantity', 10, 0);
            $table->float('received_quantity', 10, 0)->default(0);
            $table->float('total', 10, 0);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_details');
    }
};
