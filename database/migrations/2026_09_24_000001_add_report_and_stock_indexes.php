<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Batch 5: every report, dashboard card and list filters on the document date, status and warehouse, and stock is
 * looked up by (product, warehouse, variant), but none of those columns were indexed together, so each query scanned the
 * whole table. Additive and idempotent: an index that already exists (by name) is left alone, nothing is dropped.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private function indexes(): array
    {
        return [
            'sales' => ['idx_b5_sales_date_statut' => ['date', 'statut'], 'idx_b5_sales_wh_date' => ['warehouse_id', 'date']],
            'sale_details' => ['idx_b5_sale_details_date' => ['date'], 'idx_b5_sale_details_product_date' => ['product_id', 'date']],
            'purchases' => ['idx_b5_purchases_date_statut' => ['date', 'statut'], 'idx_b5_purchases_wh_date' => ['warehouse_id', 'date']],
            'sale_returns' => ['idx_b5_sale_returns_date_statut' => ['date', 'statut']],
            'purchase_returns' => ['idx_b5_purchase_returns_date_statut' => ['date', 'statut']],
            'payment_sales' => ['idx_b5_payment_sales_date' => ['date']],
            'payment_purchases' => ['idx_b5_payment_purchases_date' => ['date']],
            'payment_sale_returns' => ['idx_b5_payment_sale_returns_date' => ['date']],
            'payment_purchase_returns' => ['idx_b5_payment_purchase_returns_date' => ['date']],
            'expenses' => ['idx_b5_expenses_date' => ['date']],
            'product_warehouse' => ['idx_b5_pw_product_wh_variant' => ['product_id', 'warehouse_id', 'product_variant_id']],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
