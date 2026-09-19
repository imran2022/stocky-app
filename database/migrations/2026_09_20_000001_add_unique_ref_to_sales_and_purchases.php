<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build N2 (audit finding H-06): `sales.Ref` and `purchases.Ref` were
 * generated only at the application level ("last Ref + 1") with NO
 * database-level uniqueness — a real race between two near-simultaneous
 * requests could (and, per the audit's reproduction, does) save two
 * documents with the identical Ref.
 *
 * Deliberately a PLAIN unique index on `Ref` (not a composite with
 * `deleted_at`, unlike the earlier payment_sales fix): both
 * SalesController::getNumberOrder() and PurchasesController::getNumberOrder()
 * already compute "last Ref" from `DB::table(...)->latest('id')->first()`
 * with NO `deleted_at` filter — i.e. this app's own numbering logic never
 * intended to reuse a soft-deleted document's Ref. A composite
 * (Ref, deleted_at) index would also reopen exactly the flaw the audit
 * itself pointed out for payment_sales: MySQL permits unlimited NULLs in a
 * unique index, and `deleted_at` is NULL for every active row, so a
 * composite index would NOT actually stop two active rows from sharing a
 * Ref. A plain unique index has no such gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addUniqueRef('sales', 'sales_ref_unique');
        $this->addUniqueRef('purchases', 'purchases_ref_unique');
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                try {
                    $table->dropUnique('sales_ref_unique');
                } catch (\Throwable $e) {
                    // ignore
                }
            });
        }

        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                try {
                    $table->dropUnique('purchases_ref_unique');
                } catch (\Throwable $e) {
                    // ignore
                }
            });
        }
    }

    private function addUniqueRef(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // Safety: fail fast (and explain exactly what to do) if a duplicate
        // Ref already exists — adding the index would otherwise fail with
        // an opaque DB error.
        $duplicate = DB::table($table)
            ->select('Ref', DB::raw('COUNT(*) as c'))
            ->groupBy('Ref')
            ->having('c', '>', 1)
            ->limit(1)
            ->first();

        if ($duplicate) {
            throw new \RuntimeException(
                "Cannot add unique index on {$table}.Ref; duplicate Ref detected: ".
                "'{$duplicate->Ref}'. Please rename one of the duplicate {$table} records' ".
                'Ref to a unique value before running this migration.'
            );
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            try {
                $blueprint->unique('Ref', $indexName);
            } catch (\Throwable $e) {
                // Already exists (e.g. migration re-run) — ignore.
            }
        });
    }
};
