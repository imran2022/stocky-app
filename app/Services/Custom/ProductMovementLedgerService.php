<?php

namespace App\Services\Custom;

use Illuminate\Support\Facades\DB;

/**
 * Product Movement Ledger — Phase 1 (Build H1).
 *
 * Builds one chronological stock-movement timeline for a product (optionally
 * one variant, optionally one warehouse) by combining the seven places that
 * move product_warehouse.qte: Purchases, Sales, Transfers, Adjustments, Sale
 * Returns, Purchase Returns, and Damages — plus a reconciliation check
 * against the live product_warehouse.qte row.
 *
 * ---------------------------------------------------------------------
 * SCOPE NOTE — read before trusting this for a product that uses Units or
 * Multi-Pack Selling:
 *
 * Every figure here is the RAW quantity stored on the detail row
 * (purchase_details.quantity, sale_details.quantity, etc.) — the same
 * starting value each controller uses before applying its OWN unit /
 * pack-multiplier conversion when it updates product_warehouse.qte.
 *
 * For a product that never uses Units or Multi-Pack Selling, raw quantity
 * IS the base-unit quantity, so `running_balance` below will match the real
 * product_warehouse.qte exactly (barring the known stock-integrity bug —
 * see the reconciliation block).
 *
 * For a product where some lines DO carry a unit conversion, this Phase 1
 * version will NOT match qte exactly, because each of the seven controllers
 * resolves its own conversion slightly differently today (see
 * PurchasesController/PosController) and folding all seven into one shared
 * conversion is its own audit, not done here. `reconciled` will read false
 * for such a product/warehouse and the mismatch amount is reported rather
 * than hidden — it does not by itself mean the stock-integrity bug fired.
 *
 * Variant products viewed with no $productVariantId (the "all variants
 * combined" view) reconcile against the SUM of every variant's own
 * product_warehouse row for that warehouse. If only SOME variants are
 * missing their row there (a partial case of the same bug) while others
 * exist, the aggregate sum can still land on the right total by
 * coincidence and read as reconciled — pass a specific product_variant_id
 * to check one variant in isolation if that's a concern for a given
 * product.
 * ---------------------------------------------------------------------
 */
class ProductMovementLedgerService
{
    public static function build(
        int $productId,
        ?int $productVariantId = null,
        ?int $warehouseId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $rows = collect()
            ->merge(self::purchases($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::sales($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::transfersOut($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::transfersIn($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::adjustments($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::saleReturns($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::purchaseReturns($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo))
            ->merge(self::damages($productId, $productVariantId, $warehouseId, $dateFrom, $dateTo));

        // Stable chronological order: date, then the row's own id as a
        // same-day tiebreaker (id order approximates creation order, since
        // none of these tables are ever renumbered).
        $rows = $rows->sortBy(fn ($r) => $r['date'].'-'.str_pad($r['id'], 12, '0', STR_PAD_LEFT))
            ->values();

        $warehouseNames = DB::table('warehouses')->whereNull('deleted_at')->pluck('name', 'id');

        $running = [];
        $out = [];
        foreach ($rows as $row) {
            $wid = $row['warehouse_id'];
            $running[$wid] = round(($running[$wid] ?? 0) + $row['qty_in'] - $row['qty_out'], 4);
            $row['running_balance'] = $running[$wid];
            $row['warehouse_name'] = $warehouseNames[$wid] ?? "Warehouse #{$wid}";
            $out[] = $row;
        }

        return [
            'movements' => $out,
            'reconciliation' => self::reconcile($productId, $productVariantId, $warehouseId, $running, $warehouseNames),
        ];
    }

    /**
     * Compares the ledger's own computed ending balance per warehouse
     * against the live product_warehouse.qte row. A mismatch here is either
     * the known stock-integrity bug (row never created / qty silently
     * skipped) or a unit-conversion product per the scope note above —
     * this method does not try to tell those two apart, it only surfaces
     * the gap for a human to check.
     */
    private static function reconcile(int $productId, ?int $productVariantId, ?int $warehouseId, array $computed, $warehouseNames): array
    {
        // SUM(qte) per warehouse rather than reading a single row: for a
        // variant product with no $productVariantId given (the "all
        // variants combined" view), the real stock for that warehouse is
        // the sum of every variant's own product_warehouse row, not a
        // single row that doesn't exist. For a simple product, or a
        // specific variant, the SUM collapses to that one row's own qte —
        // same result as before, just expressed as a sum of one.
        $query = DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('warehouse_id', DB::raw('SUM(qte) as total'))
            ->groupBy('warehouse_id');

        if ($productVariantId !== null) {
            $query->where('product_variant_id', $productVariantId);
        }

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $actualRows = $query->pluck('total', 'warehouse_id');

        $warehouseIds = collect(array_keys($computed))
            ->merge($actualRows->keys())
            ->unique()
            ->values();

        return $warehouseIds->map(function ($wid) use ($computed, $actualRows, $warehouseNames) {
            $ledgerBalance = $computed[$wid] ?? 0.0;
            $actualQte = $actualRows->has($wid) ? (float) $actualRows[$wid] : null;

            return [
                'warehouse_id' => (int) $wid,
                'warehouse_name' => $warehouseNames[$wid] ?? "Warehouse #{$wid}",
                'ledger_balance' => $ledgerBalance,
                'actual_qte' => $actualQte,
                // null actual_qte (no product_warehouse row at all) is itself
                // the stock-integrity bug's signature — flagged explicitly
                // rather than folded into a numeric diff of 0.
                'row_missing' => $actualQte === null,
                'reconciled' => $actualQte !== null && abs($actualQte - $ledgerBalance) < 0.0001,
                'difference' => $actualQte === null ? null : round($actualQte - $ledgerBalance, 4),
            ];
        })->values()->all();
    }

    // -----------------------------------------------------------------
    // Per-source queries. Each returns plain arrays with a common shape:
    // id, date, type, reference_id, warehouse_id, qty_in, qty_out,
    // unit_amount (cost or price, for display only — not used in the
    // balance math).
    // -----------------------------------------------------------------

    private static function purchases(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'd.cost', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r->id, $r->date, 'purchase', $r->reference_id, $r->warehouse_id, (float) $r->quantity, 0.0, (float) $r->cost))->all();
    }

    private static function sales(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('sale_details as d')
            ->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'd.price', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r->id, $r->date, 'sale', $r->reference_id, $r->warehouse_id, 0.0, (float) $r->quantity, (float) $r->price))->all();
    }

    private static function transfersOut(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.from_warehouse_id as warehouse_id', 'd.quantity', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to, 'from_warehouse_id');

        return $q->get()->map(fn ($r) => self::row($r->id.'-out', $r->date, 'transfer_out', $r->reference_id, $r->warehouse_id, 0.0, (float) $r->quantity, null))->all();
    }

    private static function transfersIn(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.to_warehouse_id as warehouse_id', 'd.quantity', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to, 'to_warehouse_id');

        return $q->get()->map(fn ($r) => self::row($r->id.'-in', $r->date, 'transfer_in', $r->reference_id, $r->warehouse_id, (float) $r->quantity, 0.0, null))->all();
    }

    private static function adjustments(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'd.type', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(function ($r) {
            $isAdd = $r->type === 'add';

            return self::row($r->id, $r->date, 'adjustment', $r->reference_id, $r->warehouse_id, $isAdd ? (float) $r->quantity : 0.0, $isAdd ? 0.0 : (float) $r->quantity, null);
        })->all();
    }

    private static function saleReturns(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('sale_return_details as d')
            ->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'd.price', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r->id, $r->date, 'sale_return', $r->reference_id, $r->warehouse_id, (float) $r->quantity, 0.0, (float) $r->price))->all();
    }

    private static function purchaseReturns(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('purchase_return_details as d')
            ->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'd.cost', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r->id, $r->date, 'purchase_return', $r->reference_id, $r->warehouse_id, 0.0, (float) $r->quantity, (float) $r->cost))->all();
    }

    private static function damages(int $productId, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $q = DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')->whereNull('h.deleted_at')
            ->select('d.id', 'h.date', 'h.warehouse_id', 'd.quantity', 'h.id as reference_id');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $warehouseId, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r->id, $r->date, 'damage', $r->reference_id, $r->warehouse_id, 0.0, (float) $r->quantity, null))->all();
    }

    private static function applyCommonFilters($query, string $detailAlias, string $headerAlias, ?int $variantId, ?int $warehouseId, ?string $from, ?string $to, string $warehouseColumn = 'warehouse_id'): void
    {
        // null $variantId means "every variant of this product" (a simple
        // product has none, so this is a no-op for it) — NOT "non-variant
        // rows only". A variant product's detail rows always carry a
        // product_variant_id, so filtering to NULL here would silently show
        // zero movement for every variant product unless the caller happens
        // to pass a specific variant id. Pass a variant id explicitly to
        // narrow to one variant's own history.
        if ($variantId !== null) {
            $query->where("{$detailAlias}.product_variant_id", $variantId);
        }

        if ($warehouseId !== null) {
            $query->where("{$headerAlias}.{$warehouseColumn}", $warehouseId);
        }

        if ($from !== null) {
            $query->where("{$headerAlias}.date", '>=', $from);
        }

        if ($to !== null) {
            $query->where("{$headerAlias}.date", '<=', $to);
        }
    }

    private static function row($id, $date, string $type, int $referenceId, int $warehouseId, float $qtyIn, float $qtyOut, ?float $unitAmount): array
    {
        return [
            'id' => $id,
            'date' => (string) $date,
            'type' => $type,
            'reference_id' => $referenceId,
            'warehouse_id' => $warehouseId,
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'unit_amount' => $unitAmount,
        ];
    }
}
