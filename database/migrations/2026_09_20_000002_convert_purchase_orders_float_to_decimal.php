<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build N2 (audit finding H-07): purchase_orders / purchase_order_details
 * were created (2026-09-13) using FLOAT for every money/tax/quantity
 * column — the exact problem `purchases`/`purchase_details` themselves had
 * already been fixed for, 7 months earlier, in
 * 2026_02_11_000002_convert_purchases_and_purchase_details_float_to_decimal.php.
 * Binary floating point cannot exactly represent most decimal currency
 * values, so repeated arithmetic (discounts, tax, totals) on FLOAT columns
 * can silently drift by fractions of a cent — unacceptable for an
 * auditable financial document. This migration applies the exact same
 * DECIMAL precision already used on the sibling `purchases` table, so a
 * PO's totals and a GRN's totals for the same items are computed with
 * identical precision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('tax_rate', 15, 2)->nullable()->default(0)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 2)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 2)->default(0)->change();
        });

        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->decimal('cost', 15, 2)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('quantity', 12, 3)->change();
            $table->decimal('received_quantity', 12, 3)->default(0)->change();
            $table->decimal('total', 15, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->float('tax_rate', 10, 0)->nullable()->default(0)->change();
            $table->float('TaxNet', 10, 0)->nullable()->default(0)->change();
            $table->float('discount', 10, 0)->nullable()->default(0)->change();
            $table->float('shipping', 10, 0)->nullable()->default(0)->change();
            $table->float('GrandTotal', 10, 0)->default(0)->change();
        });

        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->float('cost', 10, 0)->change();
            $table->float('TaxNet', 10, 0)->nullable()->default(0)->change();
            $table->float('discount', 10, 0)->nullable()->default(0)->change();
            $table->float('quantity', 10, 0)->change();
            $table->float('received_quantity', 10, 0)->default(0)->change();
            $table->float('total', 10, 0)->change();
        });
    }
};
