<?php

namespace App\Services\Costing;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only door reports and dashboards use to read costs.
 *
 * Every method is a no-op / pass-through while costing is OFF (legacy method), so wiring a report to this class
 * never changes its numbers until an admin switches Moving Average on.
 *
 * When ON, one source answers every question:
 *   COGS of a sale line        = the ledger row of that line          (inventory_cost_ledger, source_type 'sale')
 *   COGS reversed by a return  = the ledger row of that return line   (source_type 'sale_return')
 *   inventory value            = on-hand qty x running average        (inventory_cost_balances)
 *   inventory value on a date  = last ledger balance on/before it
 */
class CostingReader
{
    public static function active(): bool
    {
        return InventoryCostingService::isActive();
    }

    /** Make sure the ledger reflects the documents (cheap when nothing changed). Call before any cost read. */
    public static function prepare(): void
    {
        if (self::active()) {
            InventoryCostingService::ensureFresh();
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // COGS
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Net COGS for sales dated inside [$from, $to] (sales cost minus the cost of goods received back on returns),
     * honouring the same warehouse and "own records only" scoping the legacy COGS engine uses.
     */
    public static function cogsForWindow(string $from, string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords, ?int $userId = null): float
    {
        self::prepare();
        self::ensureWindowCovered($from, $to, $warehouseId, $warehouseIds, $viewRecords, $userId);

        return round(self::windowRows($from, $to, $warehouseId, $warehouseIds, $viewRecords, $userId)
            ->selectRaw("COALESCE(SUM(-l.value_delta), 0) AS cogs")
            ->value('cogs'), 4);
    }

    /**
     * Net COGS per product/variant for the window.
     *
     * @return array<string,float> "productId:variantId|0" => cogs
     */
    public static function cogsByProduct(string $from, string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords, ?int $userId = null): array
    {
        self::prepare();
        self::ensureWindowCovered($from, $to, $warehouseId, $warehouseIds, $viewRecords, $userId);
        $out = [];
        foreach (self::windowRows($from, $to, $warehouseId, $warehouseIds, $viewRecords, $userId)
            ->selectRaw('l.product_id, l.variant_key, SUM(-l.value_delta) AS cogs')->groupBy('l.product_id', 'l.variant_key')->get() as $r) {
            $out[$r->product_id.':'.$r->variant_key] = (float) $r->cogs;
        }

        return $out;
    }

    /** @return \Illuminate\Database\Query\Builder ledger rows of sales and sale returns inside the window (aliased l) */
    private static function windowRows(string $from, string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords, ?int $userId)
    {
        $q = DB::table('inventory_cost_ledger as l')
            ->whereIn('l.source_type', ['sale', 'sale_return'])
            ->where('l.occurred_at', '>=', $from.' 00:00:00')->where('l.occurred_at', '<=', $to.' 23:59:59')
            ->when($warehouseId, fn ($w) => $w->where('l.warehouse_id', $warehouseId), fn ($w) => $w->whereIn('l.warehouse_id', $warehouseIds));

        if (! $viewRecords && $userId !== null) {
            // own records only: user_id lives on the document header
            $q->where(function ($w) use ($userId) {
                $w->where(fn ($s) => $s->where('l.source_type', 'sale')->whereExists(fn ($e) => $e->select(DB::raw(1))
                    ->from('sale_details as d')->join('sales as h', 'h.id', '=', 'd.sale_id')->whereColumn('d.id', 'l.source_id')->where('h.user_id', $userId)))
                    ->orWhere(fn ($s) => $s->where('l.source_type', 'sale_return')->whereExists(fn ($e) => $e->select(DB::raw(1))
                        ->from('sale_return_details as d')->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')->whereColumn('d.id', 'l.source_id')->where('h.user_id', $userId)));
            });
        }

        return $q;
    }

    /**
     * A sale/return that changed status or was edited inside the window without a new id is not caught by the
     * high-water mark. Comparing the number of completed lines with the number of ledger rows for the same window is
     * a cheap exact check; on mismatch the products involved are re-costed before the number is read.
     */
    /** @var array<string,true> per-request cache so a report that calls this several times (count/rows/chart) doesn't re-scan the same window each time. */
    private static array $windowCheckedCache = [];

    public static function ensureWindowCovered(string $from, string $to, ?int $warehouseId, array $warehouseIds, bool $viewRecords, ?int $userId): void
    {
        $cacheKey = $from.'|'.$to.'|'.($warehouseId ?? 'null').'|'.implode(',', $warehouseIds);
        if (isset(self::$windowCheckedCache[$cacheKey])) {
            return;
        }
        self::$windowCheckedCache[$cacheKey] = true;

        $wh = fn ($q, string $col) => $q->when($warehouseId, fn ($w) => $w->where($col, $warehouseId), fn ($w) => $w->whereIn($col, $warehouseIds));

        $docSales = $wh(DB::table('sale_details as d')->join('sales as h', 'h.id', '=', 'd.sale_id')->join('products as p', 'p.id', '=', 'd.product_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'completed')->where('p.type', '!=', 'is_service')
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to), 'h.warehouse_id')->count();
        $docReturns = $wh(DB::table('sale_return_details as d')->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')->join('products as p', 'p.id', '=', 'd.product_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'received')->where('p.type', '!=', 'is_service')
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to), 'h.warehouse_id')->count();
        $ledger = $wh(DB::table('inventory_cost_ledger as l')->whereIn('l.source_type', ['sale', 'sale_return'])
            ->where('l.occurred_at', '>=', $from.' 00:00:00')->where('l.occurred_at', '<=', $to.' 23:59:59'), 'l.warehouse_id')->count();

        // zero-quantity lines never become movements, so only a shortfall/excess of real rows matters
        $zeroSales = $wh(DB::table('sale_details as d')->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'completed')->where('d.quantity', 0)
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to), 'h.warehouse_id')->count();
        $zeroReturns = $wh(DB::table('sale_return_details as d')->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'received')->where('d.quantity', 0)
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to), 'h.warehouse_id')->count();

        if ($docSales + $docReturns - $zeroSales - $zeroReturns === $ledger) {
            return;
        }

        $svc = new InventoryCostingService;
        $pids = DB::table('sale_details as d')->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to)->distinct()->pluck('d.product_id')->all();
        $pids = array_merge($pids, DB::table('sale_return_details as d')->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->where('h.date', '>=', $from)->where('h.date', '<=', $to)->distinct()->pluck('d.product_id')->all());
        $pids = array_merge($pids, DB::table('inventory_cost_ledger')->whereIn('source_type', ['sale', 'sale_return'])
            ->where('occurred_at', '>=', $from.' 00:00:00')->where('occurred_at', '<=', $to.' 23:59:59')->distinct()->pluck('product_id')->all());
        $svc->syncProducts(array_values(array_unique(array_map('intval', $pids))));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Inventory value
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Base query for the Profit report when costing is on: one row per completed SALE line (positive) and per
     * RECEIVED RETURN line (negative revenue, quantity and cost), each with its stored ledger cost as `line_cost`.
     * Aliased so the report's own joins and dimensions keep working: sd (the line), p (product), pv (variant).
     *
     * @param  int[]|null  $allowed  warehouses the user may see (null = all)
     * @param  bool  $viewRecords  when false, restrict to documents owned by $userId (own-records-only screens).
     *                             Default true preserves every existing caller's behaviour unchanged.
     * @param  int|null  $userId  required when $viewRecords is false.
     */
    public static function profitLinesBase(string $from, string $to, $warehouseId, ?array $allowed, bool $viewRecords = true, ?int $userId = null)
    {
        self::prepare();
        $scope = $allowed ?? DB::table('warehouses')->pluck('id')->map(fn ($v) => (int) $v)->all();
        self::ensureWindowCovered($from, $to, $warehouseId ? (int) $warehouseId : null, $scope, true, null);

        $wh = fn ($q, string $col) => $q->when($warehouseId, fn ($w) => $w->where($col, $warehouseId), fn ($w) => $w->whereIn($col, $scope));
        $own = fn ($q) => $q->when(! $viewRecords && $userId !== null, fn ($w) => $w->where('h.user_id', $userId));

        $sales = $own($wh(DB::table('sale_details as x')->join('sales as h', 'h.id', '=', 'x.sale_id')
            ->leftJoin('inventory_cost_ledger as l', fn ($j) => $j->on('l.source_id', '=', 'x.id')->where('l.source_type', '=', 'sale'))
            ->whereNull('h.deleted_at')->where('h.statut', 'completed')->whereBetween('h.date', [$from, $to]), 'h.warehouse_id'))
            ->selectRaw('x.id as line_id, x.sale_unit_id, x.product_id, x.product_variant_id, x.quantity, x.total, COALESCE(-l.value_delta, 0) as line_cost, h.date, h.warehouse_id, h.client_id');

        $returns = $own($wh(DB::table('sale_return_details as x')->join('sale_returns as h', 'h.id', '=', 'x.sale_return_id')
            ->leftJoin('inventory_cost_ledger as l', fn ($j) => $j->on('l.source_id', '=', 'x.id')->where('l.source_type', '=', 'sale_return'))
            ->whereNull('h.deleted_at')->where('h.statut', 'received')->whereBetween('h.date', [$from, $to]), 'h.warehouse_id'))
            ->selectRaw('-x.id as line_id, x.sale_unit_id, x.product_id, x.product_variant_id, -x.quantity as quantity, -x.total as total, COALESCE(-l.value_delta, 0) as line_cost, h.date, h.warehouse_id, h.client_id');

        return DB::query()->fromSub($sales->unionAll($returns), 'sd')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id');
    }

    /**
     * Same rows as profitLinesBase(), materialized once into a real (indexed) temporary table instead of a UNION
     * subquery. The Profit report groups the same window by several dimensions in one request (a count query, the
     * page of rows, and the chart series); re-running the UNION + ledger LEFT JOIN for each of those on a large
     * date range is the one place costing showed up as slower than legacy on a large dataset. A plain MySQL
     * TEMPORARY TABLE is connection-scoped and named uniquely per call, so it never survives past this request and
     * is never reused across requests — there is nothing here for a later request (or a later call with a
     * different window) to read stale.
     *
     * @param  int[]|null  $allowed
     * @param  bool  $viewRecords  see profitLinesBase(); default true preserves existing behaviour.
     * @param  int|null  $userId
     * @return string table name, already joined to products/variants — use as `CostingReader::profitLinesTemp(...) as sd` base
     */
    public static function profitLinesTemp(string $from, string $to, $warehouseId, ?array $allowed, bool $viewRecords = true, ?int $userId = null): string
    {
        $base = self::profitLinesBase($from, $to, $warehouseId, $allowed, $viewRecords, $userId)
            ->selectRaw('sd.line_id, sd.sale_unit_id, sd.product_id, sd.product_variant_id, sd.quantity, sd.total, sd.line_cost, sd.date, sd.warehouse_id, sd.client_id, p.category_id, p.name as product_name, COALESCE(sd.sale_unit_id, p.unit_sale_id, p.unit_id) as product_unit_id');
        $sql = $base->toSql();
        $bindings = $base->getBindings();

        // Indexes are declared up front (not added afterwards with ALTER TABLE, which on a table this size can mean
        // a full table copy) so building this costs one CREATE + one INSERT ... SELECT, both single passes.
        $table = 'tmp_profit_lines_'.bin2hex(random_bytes(6));
        DB::statement("CREATE TEMPORARY TABLE {$table} (
            line_id BIGINT, sale_unit_id INT NULL, product_id INT, product_variant_id INT NULL,
            quantity DECIMAL(20,4), total DECIMAL(20,4), line_cost DECIMAL(20,4),
            date DATE, warehouse_id INT, client_id INT, category_id INT NULL,
            product_name VARCHAR(255), product_unit_id INT NULL,
            INDEX (product_id), INDEX (warehouse_id), INDEX (client_id), INDEX (date), INDEX (category_id), INDEX (product_unit_id)
        ) ENGINE=InnoDB");
        DB::statement("INSERT INTO {$table} {$sql}", $bindings);

        return $table;
    }

    /**
     * Make on-hand quantity and ledger agree before stock value is read (imports, marketplace syncs and manual DB
     * edits change product_warehouse without a document).
     */
    public static function prepareStockValue(): void
    {
        if (! self::active()) {
            return;
        }
        self::prepare();
        $svc = new InventoryCostingService;
        $mismatch = $svc->quantityMismatchProducts();
        if ($mismatch !== []) {
            $svc->syncProducts($mismatch);
        }
    }

    /**
     * LEFT JOIN the running-average balance onto a query that already has product_warehouse aliased $pw.
     * No-op while costing is off.
     */
    public static function joinBalance($query, string $pw = 'product_warehouse', string $alias = 'icb')
    {
        if (! self::active()) {
            return $query;
        }

        return $query->leftJoin("inventory_cost_balances as {$alias}", function ($j) use ($pw, $alias) {
            $j->on("{$alias}.product_id", '=', "{$pw}.product_id")
                ->whereRaw("{$alias}.variant_key = IFNULL({$pw}.product_variant_id, 0)")
                ->on("{$alias}.warehouse_id", '=', "{$pw}.warehouse_id");
        });
    }

    /**
     * SQL for the cost of ONE unit of stock. Costing off: the report's own master-cost expression, untouched.
     * Costing on: the running average, falling back to the master cost only for a row with no balance yet.
     */
    public static function unitCostSql(string $legacySql, string $alias = 'icb'): string
    {
        return self::active() ? "COALESCE({$alias}.avg_cost, {$legacySql})" : $legacySql;
    }

    /**
     * On-hand quantity, value and weighted-average unit cost per product/variant across the given warehouses.
     * Callers keep their own per-row rules (services, deleted variants); this is only the cost source.
     *
     * @return array<string,array{qty: float, value: float, avg: float}>  key "productId:variantId|0"
     */
    public static function valueMap(array $warehouseIds, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('inventory_cost_balances')->whereIn('warehouse_id', $warehouseIds)->whereIn('product_id', $productIds)
            ->selectRaw('product_id, variant_key, SUM(qty) q, SUM(qty * avg_cost) v, MAX(avg_cost) a')->groupBy('product_id', 'variant_key')->get() as $r) {
            $q = (float) $r->q;
            $out[$r->product_id.':'.$r->variant_key] = ['qty' => $q, 'value' => (float) $r->v, 'avg' => abs($q) > 0.00005 ? (float) $r->v / $q : (float) $r->a];
        }

        return $out;
    }

    /**
     * Total stock value (qty x average) and how much of it rests on estimated costs.
     *
     * @return array{value: float, estimated_value: float, qty: float}
     */
    public static function stockValue(?int $warehouseId, array $warehouseIds, ?array $productIds = null): array
    {
        self::prepareStockValue();
        $q = DB::table('inventory_cost_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')->whereNull('p.deleted_at')
            ->where('b.qty', '>', 0)
            ->when($warehouseId, fn ($w) => $w->where('b.warehouse_id', $warehouseId), fn ($w) => $w->whereIn('b.warehouse_id', $warehouseIds))
            ->when($productIds !== null, fn ($w) => $w->whereIn('b.product_id', $productIds));
        $r = $q->selectRaw('COALESCE(SUM(b.qty * b.avg_cost),0) v, COALESCE(SUM(CASE WHEN b.is_estimated = 1 THEN b.qty * b.avg_cost ELSE 0 END),0) e, COALESCE(SUM(b.qty),0) q')->first();

        return ['value' => (float) $r->v, 'estimated_value' => (float) $r->e, 'qty' => (float) $r->q];
    }

    /** Sum of |cost| of stock adjustments dated inside the window (the "Total stock adjustment" figure). */
    public static function adjustmentAbsCost(string $from, string $to, ?int $warehouseId, array $warehouseIds): float
    {
        self::prepare();

        return (float) DB::table('inventory_cost_ledger')->where('source_type', 'adjustment')
            ->where('occurred_at', '>=', $from.' 00:00:00')->where('occurred_at', '<=', $to.' 23:59:59')
            ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId), fn ($w) => $w->whereIn('warehouse_id', $warehouseIds))
            ->sum(DB::raw('ABS(value_delta)'));
    }

    /**
     * Cost of stock that left without being sold: every Damage line, plus the DECREASE side only of Adjustments
     * (a count found less than expected). The INCREASE side is deliberately excluded — found stock corrects
     * inventory value but is never recognised as income (see App\Support\Reporting\InventoryWriteOffFigures, the
     * legacy-mode twin of this method).
     */
    public static function writeOffCost(string $from, string $to, ?int $warehouseId, array $warehouseIds): float
    {
        self::prepare();

        return (float) DB::table('inventory_cost_ledger')
            ->where(fn ($q) => $q->where('source_type', 'damage')
                ->orWhere(fn ($w) => $w->where('source_type', 'adjustment')->where('qty_delta', '<', 0)))
            ->where('occurred_at', '>=', $from.' 00:00:00')->where('occurred_at', '<=', $to.' 23:59:59')
            ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId), fn ($w) => $w->whereIn('warehouse_id', $warehouseIds))
            ->selectRaw('COALESCE(SUM(-value_delta), 0) as v')->value('v');
    }

    /**
     * Inventory value at the END of $date (ledger balance of the last movement on/before it), per warehouse scope.
     * Movements are stored in replay order per product, so MAX(id) is the last chronological row of a key.
     */
    public static function valueAsOf(string $date, ?int $warehouseId, array $warehouseIds, ?array $productIds = null): float
    {
        self::prepare();
        $end = Carbon::parse($date)->format('Y-m-d').' 23:59:59';
        $latest = DB::table('inventory_cost_ledger')
            ->where('occurred_at', '<=', $end)
            ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId), fn ($w) => $w->whereIn('warehouse_id', $warehouseIds))
            ->when($productIds !== null, fn ($w) => $w->whereIn('product_id', $productIds))
            ->selectRaw('MAX(id) as id')->groupBy('product_id', 'variant_key', 'warehouse_id');

        return (float) DB::table('inventory_cost_ledger as l')->joinSub($latest, 'x', 'x.id', '=', 'l.id')
            ->where('l.balance_qty', '>', 0)->sum('l.balance_value');
    }
}
