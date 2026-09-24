<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory costing (Moving Weighted Average), Phase 1.
 *
 * Purely additive and self-contained: NO vendor table is altered, so a vendor update cannot collide with it and
 * nothing existing can break. Every table here is derived from (or stamped next to) the existing documents:
 *
 *  - inventory_cost_ledger   one row per costed stock movement (purchase, sale, return, adjustment, transfer,
 *                            damage, seed) with the running quantity / value / average cost AFTER the movement.
 *                            A sale line's COGS is its ledger row, so history never depends on products.cost.
 *  - inventory_cost_balances current quantity / value / average cost per product, variant and warehouse.
 *  - inventory_cost_seeds    stock that exists in product_warehouse without a document explaining it (imports,
 *                            marketplace syncs, opening stock): written once, then treated like any document.
 *  - inventory_cost_stamps   write-once input costs for documents that carry no cost of their own (stock
 *                            adjustments that add stock): stamped from the master cost the first time they are seen.
 *  - inventory_cost_keys     per product/variant fingerprint of its source documents, to detect edits/deletes.
 *  - inventory_cost_corrections audit trail: every time a posted sale/return line cost changes because an older
 *                            document was edited, deleted or back-dated.
 *  - inventory_cost_meta     key/value: costing_method ('legacy' | 'moving_average'), timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_cost_ledger')) {
            Schema::create('inventory_cost_ledger', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_key')->default(0);           // product_variant_id, 0 = none
                $table->unsignedInteger('product_variant_id')->nullable();
                $table->unsignedInteger('warehouse_id');
                $table->dateTime('occurred_at');
                $table->string('source_type', 24);                             // purchase, purchase_return, sale, sale_return, adjustment, damage, transfer_out, transfer_in, seed
                $table->unsignedBigInteger('source_id');                       // the detail row id (seed id for seeds)
                $table->unsignedBigInteger('reference_id')->nullable();        // the document header id
                $table->decimal('qty_delta', 18, 4);                           // base units, signed
                $table->decimal('unit_cost', 20, 8)->default(0);               // cost of one base unit for this movement
                $table->decimal('value_delta', 20, 4);                         // signed; sale = -(COGS)
                $table->decimal('balance_qty', 18, 4);
                $table->decimal('balance_value', 20, 4);
                $table->decimal('avg_cost', 20, 8)->default(0);
                $table->boolean('is_estimated')->default(false);               // cost partly derived from a master-cost stamp/seed
                $table->timestamps();

                $table->unique(['source_type', 'source_id'], 'icl_source_unique');
                $table->index(['product_id', 'variant_key', 'warehouse_id', 'occurred_at', 'id'], 'icl_key_time');
                $table->index(['warehouse_id', 'occurred_at'], 'icl_wh_time');
                $table->index(['source_type', 'occurred_at'], 'icl_type_time');
            });
        }

        if (! Schema::hasTable('inventory_cost_balances')) {
            Schema::create('inventory_cost_balances', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_key')->default(0);
                $table->unsignedInteger('product_variant_id')->nullable();
                $table->unsignedInteger('warehouse_id');
                $table->decimal('qty', 18, 4)->default(0);
                $table->decimal('value', 20, 4)->default(0);
                $table->decimal('avg_cost', 20, 8)->default(0);
                $table->boolean('is_estimated')->default(false);
                $table->timestamps();

                $table->unique(['product_id', 'variant_key', 'warehouse_id'], 'icb_key_unique');
                $table->index('warehouse_id', 'icb_wh');
            });
        }

        if (! Schema::hasTable('inventory_cost_seeds')) {
            Schema::create('inventory_cost_seeds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_key')->default(0);
                $table->unsignedInteger('product_variant_id')->nullable();
                $table->unsignedInteger('warehouse_id');
                $table->dateTime('occurred_at');
                $table->decimal('qty_delta', 18, 4);
                $table->decimal('unit_cost', 20, 8)->nullable();               // NULL for a negative seed (costed at the running average)
                $table->string('reason', 32);                                  // opening_unexplained | unexplained_increase | unexplained_decrease
                $table->timestamps();

                $table->index(['product_id', 'variant_key', 'warehouse_id'], 'ics_key');
            });
        }

        if (! Schema::hasTable('inventory_cost_stamps')) {
            Schema::create('inventory_cost_stamps', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('source_type', 24);                             // adjustment
                $table->unsignedBigInteger('source_id');
                $table->decimal('unit_cost', 20, 8);
                $table->string('basis', 24);                                   // master_cost | manual
                $table->timestamps();

                $table->unique(['source_type', 'source_id'], 'icst_unique');
            });
        }

        if (! Schema::hasTable('inventory_cost_keys')) {
            Schema::create('inventory_cost_keys', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_key')->default(0);
                $table->string('signature', 64);
                $table->dateTime('synced_at');

                $table->unique(['product_id', 'variant_key'], 'ick_unique');
            });
        }

        if (! Schema::hasTable('inventory_cost_corrections')) {
            Schema::create('inventory_cost_corrections', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('source_type', 24);
                $table->unsignedBigInteger('source_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_key')->default(0);
                $table->unsignedInteger('warehouse_id');
                $table->decimal('old_value', 20, 4);
                $table->decimal('new_value', 20, 4);
                $table->string('reason', 64)->default('upstream_document_changed');
                $table->timestamp('created_at')->nullable();

                $table->index(['source_type', 'source_id'], 'icc_source');
                $table->index('created_at', 'icc_created');
            });
        }

        if (! Schema::hasTable('inventory_cost_meta')) {
            Schema::create('inventory_cost_meta', function (Blueprint $table) {
                $table->string('meta_key', 64)->primary();
                $table->text('meta_value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['inventory_cost_meta', 'inventory_cost_corrections', 'inventory_cost_keys', 'inventory_cost_stamps',
            'inventory_cost_seeds', 'inventory_cost_balances', 'inventory_cost_ledger'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
