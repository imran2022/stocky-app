<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order (PO) header table.
 *
 * A PO is the "we intend to buy this" document — placing one does not move
 * stock. Stock only moves when a GRN (an existing `purchases` row, unchanged
 * by this migration) is received, optionally against this PO. See
 * add_purchase_order_link_to_purchases_table for that link.
 *
 * `status` lifecycle:
 *   draft              -> not yet sent to the supplier, freely editable
 *   ordered            -> sent/confirmed with the supplier (default on create)
 *   partially_received -> at least one linked GRN exists, but not every line
 *                         has reached its ordered quantity yet
 *   received           -> every line's total received quantity has reached
 *                         (or exceeded) its ordered quantity
 *   cancelled           -> the order was called off; no further GRNs expected
 * partially_received/received are computed automatically by
 * PurchaseOrderController::refreshStatusFromReceipts() whenever a GRN is
 * saved against this PO — see that method's docblock for the exact rule.
 * draft/ordered/cancelled are the only values a user sets directly.
 *
 * Column shape deliberately mirrors the existing `purchases` table (same
 * money/tax/discount/shipping columns, same Multi-Currency snapshot
 * columns, same soft-delete convention) so the rest of the app's document
 * currency, price, and reporting helpers work against this table with no
 * special-casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id', true);
            $table->integer('user_id')->index('purchase_orders_user_id');
            $table->string('Ref', 192)->unique();
            $table->date('date');
            $table->date('expected_delivery_date')->nullable();
            $table->integer('provider_id')->index('purchase_orders_provider_id');
            $table->integer('warehouse_id')->index('purchase_orders_warehouse_id');
            $table->string('status', 32)->default('draft');
            $table->float('tax_rate', 10, 0)->nullable()->default(0);
            $table->float('TaxNet', 10, 0)->nullable()->default(0);
            $table->float('discount', 10, 0)->nullable()->default(0);
            $table->float('shipping', 10, 0)->nullable()->default(0);
            $table->float('GrandTotal', 10, 0)->default(0);
            $table->text('notes')->nullable();
            // Multi-Currency snapshot — same convention as
            // add_currency_to_transaction_tables.php: NULL means base
            // currency, rate 1.
            $table->integer('currency_id')->nullable()->index();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
