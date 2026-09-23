<?php

namespace App\Services\Custom;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only product stock movement ledger.
 *
 * Mirrors the stock-affecting rules used by the transaction controllers:
 * only final statuses are included, quantities are converted to base units,
 * and approved transfers are represented on the correct side(s).
 */
class ProductMovementLedgerService
{
    public static function build(
        int $productId,
        ?int $productVariantId = null,
        ?int $warehouseId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?array $allowedWarehouseIds = null
    ): array {
        $scope = self::warehouseScope($warehouseId, $allowedWarehouseIds);
        $warehouseNames = self::warehouseNames($scope);
        $variantMeta = DB::table('product_variants')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $opening = [];
        if ($dateFrom !== null) {
            $openingRows = self::movementRows(
                $productId,
                $productVariantId,
                $scope,
                null,
                Carbon::parse($dateFrom)->subDay()->toDateString()
            );
            $opening = self::balances($openingRows);
        }

        $rows = self::movementRows(
            $productId,
            $productVariantId,
            $scope,
            $dateFrom,
            $dateTo
        )->sortBy(fn (array $row) => sprintf(
            '%s-%02d-%012d',
            $row['occurred_at'],
            $row['sort_priority'],
            $row['detail_id']
        ))->values();

        $running = $opening;
        $movements = [];
        foreach ($rows as $row) {
            $wid = (int) $row['warehouse_id'];
            $running[$wid] = round(($running[$wid] ?? 0.0) + $row['qty_in'] - $row['qty_out'], 4);

            $variantId = $row['product_variant_id'];
            $variant = $variantId !== null ? $variantMeta->get($variantId) : null;

            unset($row['sort_priority'], $row['detail_id']);
            $row['running_balance'] = $running[$wid];
            $row['warehouse_name'] = $warehouseNames[$wid] ?? "Warehouse #{$wid}";
            $row['variant_name'] = $variant?->name;
            $row['variant_code'] = $variant?->code;
            $movements[] = $row;
        }

        // A bounded end date is a historical snapshot. Comparing it with the
        // live product_warehouse quantity would create a false mismatch.
        $canCompareLiveStock = $dateTo === null;

        $reconciliation = self::reconcile(
            $productId,
            $productVariantId,
            $scope,
            $running,
            $warehouseNames,
            $canCompareLiveStock
        );

        return [
            'movements' => $movements,
            'opening_balances' => collect($opening)->mapWithKeys(
                fn ($qty, $wid) => [(string) $wid => round((float) $qty, 4)]
            )->all(),
            'reconciliation_mode' => $canCompareLiveStock ? 'live' : 'historical',
            'reconciliation' => $reconciliation,
            'summary' => self::summary($rows, $opening, $running, $reconciliation, $canCompareLiveStock),
        ];
    }

    private static function movementRows(
        int $productId,
        ?int $variantId,
        ?array $warehouseScope,
        ?string $from,
        ?string $to
    ): Collection {
        return collect()
            ->merge(self::purchases($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::sales($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::transfersOut($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::transfersIn($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::adjustments($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::saleReturns($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::purchaseReturns($productId, $variantId, $warehouseScope, $from, $to))
            ->merge(self::damages($productId, $variantId, $warehouseScope, $from, $to))
            // Some return documents retain untouched detail lines with zero
            // quantity. They are not stock movements and must not become
            // duplicate-looking ledger rows or affect the summary/export.
            ->filter(fn (array $row) => abs($row['qty_in']) >= 0.0001 || abs($row['qty_out']) >= 0.0001)
            ->values();
    }

    private static function summary(
        Collection $rows,
        array $opening,
        array $running,
        array $reconciliation,
        bool $canCompareLiveStock
    ): array {
        $sum = fn (string $type, string $field): float => round((float) $rows
            ->where('type', $type)
            ->sum($field), 4);

        $currentStock = null;
        if ($canCompareLiveStock) {
            $available = collect($reconciliation)->where('comparison_available', true)->where('row_missing', false);
            if ($available->isNotEmpty()) {
                $currentStock = round((float) $available->sum('actual_qte'), 4);
            }
        }

        return [
            'purchase' => $sum('purchase', 'qty_in'),
            'opening_stock' => $sum('opening_stock', 'qty_in'),
            'sale_return' => $sum('sale_return', 'qty_in'),
            'transfer_in' => $sum('transfer_in', 'qty_in'),
            'adjustment_in' => $sum('adjustment', 'qty_in'),
            'sale' => $sum('sale', 'qty_out'),
            'purchase_return' => $sum('purchase_return', 'qty_out'),
            'transfer_out' => $sum('transfer_out', 'qty_out'),
            'damage' => $sum('damage', 'qty_out'),
            'adjustment_out' => $sum('adjustment', 'qty_out'),
            'opening_balance' => round((float) array_sum($opening), 4),
            'net_movement' => round((float) ($rows->sum('qty_in') - $rows->sum('qty_out')), 4),
            'calculated_closing' => round((float) array_sum($running), 4),
            'current_stock' => $currentStock,
            'stock_label' => $currentStock === null ? 'Calculated Closing' : 'Current Stock',
        ];
    }

    private static function balances(Collection $rows): array
    {
        $balances = [];
        foreach ($rows as $row) {
            $wid = (int) $row['warehouse_id'];
            $balances[$wid] = round(($balances[$wid] ?? 0.0) + $row['qty_in'] - $row['qty_out'], 4);
        }

        return $balances;
    }

    /** null = all warehouses; [] = no authorized warehouse; otherwise IDs. */
    private static function warehouseScope(?int $warehouseId, ?array $allowedWarehouseIds): ?array
    {
        if ($warehouseId !== null) {
            return [$warehouseId];
        }
        if ($allowedWarehouseIds === null) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $allowedWarehouseIds)));
    }

    private static function warehouseNames(?array $warehouseScope)
    {
        $query = DB::table('warehouses')->whereNull('deleted_at');
        self::applyWarehouseScope($query, 'id', $warehouseScope);

        return $query->pluck('name', 'id');
    }

    private static function reconcile(
        int $productId,
        ?int $variantId,
        ?array $warehouseScope,
        array $computed,
        $warehouseNames,
        bool $canCompareLiveStock
    ): array {
        $query = DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('warehouse_id', DB::raw('SUM(qte) as total'))
            ->groupBy('warehouse_id');

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        }
        self::applyWarehouseScope($query, 'warehouse_id', $warehouseScope);

        $actualRows = $query->pluck('total', 'warehouse_id');
        $warehouseIds = collect(array_keys($computed))
            ->merge($actualRows->keys())
            ->merge($warehouseScope ?? [])
            ->unique()
            ->sort()
            ->values();

        return $warehouseIds->map(function ($wid) use ($computed, $actualRows, $warehouseNames, $canCompareLiveStock) {
            $ledgerBalance = round((float) ($computed[$wid] ?? 0.0), 4);

            if (! $canCompareLiveStock) {
                return [
                    'warehouse_id' => (int) $wid,
                    'warehouse_name' => $warehouseNames[$wid] ?? "Warehouse #{$wid}",
                    'ledger_balance' => $ledgerBalance,
                    'actual_qte' => null,
                    'row_missing' => false,
                    'reconciled' => null,
                    'difference' => null,
                    'comparison_available' => false,
                ];
            }

            $actualQte = $actualRows->has($wid) ? round((float) $actualRows[$wid], 4) : null;

            return [
                'warehouse_id' => (int) $wid,
                'warehouse_name' => $warehouseNames[$wid] ?? "Warehouse #{$wid}",
                'ledger_balance' => $ledgerBalance,
                'actual_qte' => $actualQte,
                'row_missing' => $actualQte === null,
                'reconciled' => $actualQte !== null && abs($actualQte - $ledgerBalance) < 0.0001,
                'difference' => $actualQte === null ? null : round($actualQte - $ledgerBalance, 4),
                'comparison_available' => true,
            ];
        })->all();
    }

    private static function purchases(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu');
        $q = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('providers as party', 'party.id', '=', 'h.provider_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'received')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.cost', 'h.id as reference_id', 'h.Ref as reference', 'party.name as party_name')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r, 'purchase', (float) $r->base_quantity, 0.0, (float) $r->cost, 10))->all();
    }

    private static function sales(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu', 'd.pack_multiplier');
        $q = DB::table('sale_details as d')
            ->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.sale_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_sale_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'completed')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.price', 'h.id as reference_id', 'h.Ref as reference', 'party.name as party_name')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r, 'sale', 0.0, (float) $r->base_quantity, (float) $r->price, 20))->all();
    }

    private static function transfersOut(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu');
        $q = self::transferQuery($productId)
            ->whereIn('h.statut', ['completed', 'sent'])
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.from_warehouse_id as warehouse_id', 'h.id as reference_id', 'h.Ref as reference')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to, 'from_warehouse_id');

        return $q->get()->map(fn ($r) => self::row($r, 'transfer_out', 0.0, (float) $r->base_quantity, null, 30, 'out'))->all();
    }

    private static function transfersIn(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu');
        $q = self::transferQuery($productId)
            ->where('h.statut', 'completed')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.to_warehouse_id as warehouse_id', 'h.id as reference_id', 'h.Ref as reference')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to, 'to_warehouse_id');

        return $q->get()->map(fn ($r) => self::row($r, 'transfer_in', (float) $r->base_quantity, 0.0, null, 40, 'in'))->all();
    }

    private static function transferQuery(int $productId)
    {
        return DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->where(function ($approved) {
                $approved->where('h.approval_status', 'approved')->orWhereNull('h.approval_status');
            });
    }

    private static function adjustments(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $q = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.quantity as base_quantity', 'd.type', 'h.notes', 'h.id as reference_id', 'h.Ref as reference');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(function ($r) {
            $isAdd = $r->type === 'add';
            $isOpening = str_starts_with(strtolower(trim((string) $r->notes)), 'opening stock');
            return self::row($r, $isOpening ? 'opening_stock' : 'adjustment', $isAdd ? (float) $r->base_quantity : 0.0, $isAdd ? 0.0 : (float) $r->base_quantity, null, 50);
        })->all();
    }

    private static function saleReturns(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu', 'd.pack_multiplier');
        $q = DB::table('sale_return_details as d')
            ->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.sale_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_sale_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'received')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.price', 'h.id as reference_id', 'h.Ref as reference', 'party.name as party_name')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r, 'sale_return', (float) $r->base_quantity, 0.0, (float) $r->price, 60))->all();
    }

    private static function purchaseReturns(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $quantity = self::convertedQuantity('d.quantity', 'du', 'pu');
        $q = DB::table('purchase_return_details as d')
            ->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('providers as party', 'party.id', '=', 'h.provider_id')
            ->leftJoin('units as du', 'du.id', '=', 'd.purchase_unit_id')
            ->leftJoin('units as pu', 'pu.id', '=', 'p.unit_purchase_id')
            ->where('d.product_id', $productId)
            ->whereNull('d.deleted_at')
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'completed')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.cost', 'h.id as reference_id', 'h.Ref as reference', 'party.name as party_name')
            ->selectRaw("{$quantity} as base_quantity");

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r, 'purchase_return', 0.0, (float) $r->base_quantity, (float) $r->cost, 70))->all();
    }

    private static function damages(int $productId, ?int $variantId, ?array $scope, ?string $from, ?string $to): array
    {
        $q = DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->where('d.product_id', $productId)
            ->whereNull('h.deleted_at')
            ->select('d.id', 'd.product_variant_id', 'h.date', 'h.time', 'h.created_at', 'h.warehouse_id', 'd.quantity as base_quantity', 'h.id as reference_id', 'h.Ref as reference');

        self::applyCommonFilters($q, 'd', 'h', $variantId, $scope, $from, $to);

        return $q->get()->map(fn ($r) => self::row($r, 'damage', 0.0, (float) $r->base_quantity, null, 80))->all();
    }

    private static function applyCommonFilters(
        $query,
        string $detailAlias,
        string $headerAlias,
        ?int $variantId,
        ?array $warehouseScope,
        ?string $from,
        ?string $to,
        string $warehouseColumn = 'warehouse_id'
    ): void {
        if ($variantId !== null) {
            $query->where("{$detailAlias}.product_variant_id", $variantId);
        }
        self::applyWarehouseScope($query, "{$headerAlias}.{$warehouseColumn}", $warehouseScope);

        if ($from !== null) {
            $query->where("{$headerAlias}.date", '>=', $from);
        }
        if ($to !== null) {
            $query->where("{$headerAlias}.date", '<=', $to);
        }
    }

    private static function applyWarehouseScope($query, string $column, ?array $warehouseScope): void
    {
        if ($warehouseScope === null) {
            return;
        }
        if ($warehouseScope === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($column, $warehouseScope);
    }

    /** Convert a detail quantity using its line unit, falling back to product unit. */
    private static function convertedQuantity(string $quantity, string $detailUnit, string $productUnit, ?string $pack = null): string
    {
        $packExpression = $pack === null ? '1' : "COALESCE(NULLIF({$pack}, 0), 1)";
        $operator = "COALESCE({$detailUnit}.operator, {$productUnit}.operator)";
        $value = "COALESCE(NULLIF({$detailUnit}.operator_value, 0), NULLIF({$productUnit}.operator_value, 0), 1)";

        return "({$quantity} * {$packExpression} * CASE "
            ."WHEN {$operator} = '/' THEN 1.0 / {$value} "
            ."WHEN {$operator} = '*' THEN {$value} ELSE 1 END)";
    }

    private static function row(
        $record,
        string $type,
        float $qtyIn,
        float $qtyOut,
        ?float $unitAmount,
        int $sortPriority,
        ?string $suffix = null
    ): array {
        $time = $record->time ?: Carbon::parse($record->created_at)->format('H:i:s');
        $occurredAt = trim((string) $record->date.' '.$time);
        $id = $type.'-'.$record->id.($suffix ? '-'.$suffix : '');

        return [
            'id' => $id,
            'detail_id' => (int) $record->id,
            'date' => (string) $record->date,
            'time' => (string) $time,
            'occurred_at' => $occurredAt,
            'sort_priority' => $sortPriority,
            'type' => $type,
            'reference_id' => (int) $record->reference_id,
            'reference' => (string) $record->reference,
            'party_name' => isset($record->party_name) ? (string) $record->party_name : null,
            'party_type' => in_array($type, ['purchase', 'purchase_return'], true)
                ? 'supplier'
                : (in_array($type, ['sale', 'sale_return'], true) ? 'customer' : null),
            'product_variant_id' => isset($record->product_variant_id) ? (int) $record->product_variant_id : null,
            'warehouse_id' => (int) $record->warehouse_id,
            'qty_in' => round($qtyIn, 4),
            'qty_out' => round($qtyOut, 4),
            'unit_amount' => $unitAmount,
        ];
    }
}
