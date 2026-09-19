<?php

namespace App\Support;

use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Centralized, safe access to a product's stock row for a given warehouse
 * (+ optional variant).
 *
 * Security/correctness fix (Build N1, audit findings C-02 and H-02):
 *
 * - C-02: previously, every stock-mutating call site did
 *   `product_warehouse::where(...)->first()` and then only touched it
 *   `if ($product_warehouse) { ... }` — when no row existed yet for that
 *   product/warehouse (e.g. a product never stocked in that warehouse
 *   before), the condition was silently false: no row was created, no
 *   quantity was deducted, and the sale/purchase/transfer/adjustment still
 *   completed normally. `lockOrCreate()` always returns a row — creating
 *   one at qte=0 first if necessary — so the caller can no longer skip the
 *   mutation by accident.
 *
 * - H-02: the old lookups had no row lock, so two concurrent requests
 *   touching the same stock row could both read the same quantity and
 *   overwrite each other's update (a classic lost-update race).
 *   `lockOrCreate()` always uses `lockForUpdate()`, and MUST be called
 *   inside an active DB transaction (every call site already wraps its
 *   work in `DB::transaction()`), so concurrent requests for the same row
 *   now serialize instead of racing.
 */
class StockMutator
{
    /**
     * Return the product_warehouse row for this product/warehouse(/variant),
     * row-locked for the duration of the current transaction. Creates the
     * row (qte = 0) first if it does not exist yet, instead of returning
     * null.
     *
     * @param  int  $warehouseId
     * @param  int  $productId
     * @param  int|null  $variantId
     */
    public static function lockOrCreate($warehouseId, $productId, $variantId = null): product_warehouse
    {
        $query = product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        $row = $query->lockForUpdate()->first();

        if ($row) {
            return $row;
        }

        // Nothing to lock yet — create the row (guard against a concurrent
        // request creating it first via a unique-key race by re-querying
        // inside a try/catch, since this table currently has no unique
        // constraint enforcing that at the DB level — see H-02 notes).
        try {
            $row = new product_warehouse();
            $row->product_id = $productId;
            $row->warehouse_id = $warehouseId;
            $row->product_variant_id = $variantId;
            $row->qte = 0;
            $row->manage_stock = 1;
            $row->save();
        } catch (\Throwable $e) {
            // Fall through to re-query below; if creation genuinely failed
            // for another reason the re-query will also come back empty and
            // we re-throw.
        }

        $row = $query->lockForUpdate()->first();

        if (! $row) {
            throw new \RuntimeException(
                'Could not create or lock the stock row for product '.$productId.
                ' in warehouse '.$warehouseId.'.'
            );
        }

        return $row;
    }
}
