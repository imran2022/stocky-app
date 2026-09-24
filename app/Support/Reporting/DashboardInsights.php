<?php

namespace App\Support\Reporting;

use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modern Dashboard: the extra numbers the Modern page shows on top of `dashboard_data`.
 *
 * Nothing here re-defines a figure the Classic dashboard already owns (sales, purchases, invoices, profit, stock
 * value, lists and charts stay in DashboardController). Every figure below reuses the definition of the report it
 * belongs to, and the definition is written next to it so a number can never be "almost" right:
 *
 *  - only COMPLETED sales, RECEIVED sale returns / purchases and COMPLETED purchase returns count;
 *  - Cash flow = the Cash Flow report itself (CashFlowFigures), including expenses as money out;
 *  - Sales due per invoice = GrandTotal - paid_amount, the same "Due" the Sales list shows;
 *  - a figure with nothing behind it is NULL (the page shows "-"), never a made-up zero percentage.
 *
 * $ctx: from, to (Y-m-d) | warehouse_id (0 = all the user may see) | warehouse_ids (array) | all_warehouses (bool)
 *       view_records (bool) | user_id (int) | today (Y-m-d)
 */
class DashboardInsights
{
    /** Days without a single sale before stock counts as dead stock. */
    public const DEAD_STOCK_DAYS = 60;

    public static function build(array $ctx): array
    {
        $ctx['today'] = $ctx['today'] ?? Carbon::today()->toDateString();
        [$prevFrom, $prevTo] = self::previousRange($ctx['from'], $ctx['to']);
        $ctx['scoped_ids'] = $ctx['warehouse_id'] ? [(int) $ctx['warehouse_id']] : array_map('intval', $ctx['warehouse_ids']);

        $aging = self::receivables($ctx);
        $cash = self::cashFlow($ctx, $ctx['from'], $ctx['to'], $prevFrom, $prevTo);
        $netByDay = $cash['_net_by_day'];
        unset($cash['_net_by_day']);
        $stock = self::stockHealth($ctx);

        return [
            'range' => ['from' => $ctx['from'], 'to' => $ctx['to'], 'prev_from' => $prevFrom, 'prev_to' => $prevTo],
            'previous' => self::previous($ctx, $prevFrom, $prevTo),
            'expenses' => ['value' => self::expenses($ctx, $ctx['from'], $ctx['to']), 'prev' => self::expenses($ctx, $prevFrom, $prevTo)],
            'cash_flow' => $cash,
            'daily' => self::daily($ctx, $netByDay),
            'returns' => [
                'sales' => self::returnsTotal($ctx, 'sale_returns', 'received', $ctx['from'], $ctx['to']),
                'purchases' => self::returnsTotal($ctx, 'purchase_returns', 'completed', $ctx['from'], $ctx['to']),
                'prev_sales' => self::returnsTotal($ctx, 'sale_returns', 'received', $prevFrom, $prevTo),
                'prev_purchases' => self::returnsTotal($ctx, 'purchase_returns', 'completed', $prevFrom, $prevTo),
            ],
            'collected' => self::collected($ctx),
            'receivables' => $aging,
            'stock_health' => $stock,
            'customer_mix' => self::customerMix($ctx),
            'attention' => self::attention($ctx, $aging, $stock),
            'geo' => self::geo($ctx),
        ];
    }

    /** The period of the same length that ends the day before $from ("vs previous period"). */
    public static function previousRange(string $from, string $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $days = $f->diffInDays(Carbon::parse($to)->startOfDay()) + 1;
        $prevTo = $f->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [$prevFrom->toDateString(), $prevTo->toDateString()];
    }

    /** Same visibility as every dashboard widget: own records unless allowed to view all, and the warehouse scope. */
    private static function scope($q, array $ctx, string $table, bool $viewRecordsOnUser = true)
    {
        if ($viewRecordsOnUser && ! $ctx['view_records']) {
            $q->where("{$table}.user_id", $ctx['user_id']);
        }
        $q->whereIn("{$table}.warehouse_id", $ctx['scoped_ids']);

        return $q;
    }

    /** The previous period's headline figures, defined exactly like the Classic cards (completed sales / received purchases, GrandTotal). */
    private static function previous(array $ctx, string $from, string $to): array
    {
        $sum = fn (string $table, string $status) => self::scope(
            DB::table($table)->whereNull('deleted_at')->where('statut', $status)->whereBetween('date', [$from, $to]), $ctx, $table
        )->selectRaw('COALESCE(SUM(GrandTotal),0) AS total, COUNT(*) AS n')->first();
        $sales = $sum('sales', SalesFigures::SALE_STATUS);
        $purchases = $sum('purchases', 'received');

        return [
            'sales' => round((float) $sales->total, 2), 'invoices' => (int) $sales->n, 'purchases' => round((float) $purchases->total, 2),
            'from' => $from, 'to' => $to,
        ];
    }

    private static function expenses(array $ctx, string $from, string $to): float
    {
        return round((float) self::scope(
            DB::table('expenses')->whereNull('deleted_at')->whereBetween('date', [$from, $to]), $ctx, 'expenses'
        )->sum('amount'), 2);
    }

    /** One read of the Cash Flow report's entries for previous + current period, split by day. Same numbers as that report. */
    private static function cashFlow(array $ctx, string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $entries = CashFlowFigures::entries([
            'from' => $prevFrom, 'to' => $to, 'group_by' => 'account', 'account_id' => null, 'method_id' => null,
            'warehouse_id' => $ctx['warehouse_id'] ?: null,
            'allowed_warehouses' => $ctx['all_warehouses'] ? null : array_map('intval', $ctx['warehouse_ids']),
            'view_records' => (bool) $ctx['view_records'], 'user_id' => (int) $ctx['user_id'],
        ]);
        $now = CashFlowFigures::summarise($entries->filter(fn ($e) => substr($e['d'], 0, 10) >= $from));
        $prev = CashFlowFigures::summarise($entries->filter(fn ($e) => substr($e['d'], 0, 10) <= $prevTo));

        return [
            'inflow' => (float) $now['total_inflow'], 'outflow' => (float) $now['total_outflow'], 'net' => (float) $now['net_cash_flow'],
            'prev_net' => (float) $prev['net_cash_flow'],
            '_net_by_day' => collect($now['timeseries'])->mapWithKeys(fn ($r) => [substr((string) $r['d'], 0, 10) => (float) $r['net']])->all(),
        ];
    }

    /**
     * Day-by-day expenses and net cash flow for the KPI mini-charts. Every day of the range is present (a day with no
     * movement is a true 0). Not offered for a single day or for very long ranges, where a trend line says nothing.
     */
    private static function daily(array $ctx, array $netByDay): ?array
    {
        $from = Carbon::parse($ctx['from'])->startOfDay();
        $days = $from->diffInDays(Carbon::parse($ctx['to'])->startOfDay()) + 1;
        if ($days < 2 || $days > 400) {
            return null;
        }
        $exp = self::scope(DB::table('expenses')->whereNull('deleted_at')->whereBetween('date', [$ctx['from'], $ctx['to']]), $ctx, 'expenses')
            ->selectRaw('DATE(date) AS d, SUM(amount) AS t')->groupBy(DB::raw('DATE(date)'))->pluck('t', 'd')->all();
        $out = ['days' => [], 'expenses' => [], 'net_cash' => []];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $out['days'][] = $d;
            $out['expenses'][] = round((float) ($exp[$d] ?? 0), 2);
            $out['net_cash'][] = round((float) ($netByDay[$d] ?? 0), 2);
        }

        return $out;
    }

    private static function returnsTotal(array $ctx, string $table, string $status, string $from, string $to): float
    {
        return round((float) self::scope(
            DB::table($table)->whereNull('deleted_at')->where('statut', $status)->whereBetween('date', [$from, $to]), $ctx, $table
        )->sum('GrandTotal'), 2);
    }

    /** How much of the period's invoiced sales has been paid. Same numbers as the Classic "Sales due" card. */
    private static function collected(array $ctx): array
    {
        $r = self::scope(
            DB::table('sales')->whereNull('deleted_at')->where('statut', SalesFigures::SALE_STATUS)->whereBetween('date', [$ctx['from'], $ctx['to']]),
            $ctx, 'sales'
        )->selectRaw('COALESCE(SUM(GrandTotal),0) AS total, COALESCE(SUM(paid_amount),0) AS paid, COUNT(*) AS n')->first();

        $total = (float) $r->total;
        $paid = (float) $r->paid;

        return [
            'total' => round($total, 2), 'paid' => round($paid, 2), 'due' => round($total - $paid, 2), 'invoices' => (int) $r->n,
            'pct' => $total > 0.005 ? round(max(0, min(100, $paid / $total * 100)), 1) : null,
        ];
    }

    /**
     * Money customers still owe, as of TODAY and across all dates (not just the selected period), split by the age of
     * the invoice. Due per invoice = GrandTotal - paid_amount (the Sales list's "Due").
     */
    private static function receivables(array $ctx): array
    {
        $today = Carbon::parse($ctx['today']);
        $d7 = $today->copy()->subDays(7)->toDateString();
        $d8 = $today->copy()->subDays(8)->toDateString();
        $d30 = $today->copy()->subDays(30)->toDateString();
        $d31 = $today->copy()->subDays(31)->toDateString();

        // One pass over the unpaid invoices (three separate scans were 3x slower on a large sales table).
        $due = '(GrandTotal - paid_amount)';
        $r = self::scope(
            DB::table('sales')->whereNull('deleted_at')->where('statut', SalesFigures::SALE_STATUS)->whereRaw("$due > 0.005"),
            $ctx, 'sales'
        )->selectRaw(
            "COALESCE(SUM(CASE WHEN date >= ? THEN $due ELSE 0 END),0) AS a1, COALESCE(SUM(CASE WHEN date >= ? THEN 1 ELSE 0 END),0) AS n1,
             COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN $due ELSE 0 END),0) AS a2, COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN 1 ELSE 0 END),0) AS n2,
             COALESCE(SUM(CASE WHEN date <= ? THEN $due ELSE 0 END),0) AS a3, COALESCE(SUM(CASE WHEN date <= ? THEN 1 ELSE 0 END),0) AS n3",
            [$d7, $d7, $d30, $d8, $d30, $d8, $d31, $d31]
        )->first();

        $b = [
            'd0_7' => ['amount' => round((float) $r->a1, 2), 'count' => (int) $r->n1],      // invoiced within the last 7 days (incl. today)
            'd8_30' => ['amount' => round((float) $r->a2, 2), 'count' => (int) $r->n2],     // 8 to 30 days old
            'd31_plus' => ['amount' => round((float) $r->a3, 2), 'count' => (int) $r->n3],  // more than 30 days old
        ];

        return [
            'buckets' => $b,
            'total' => round(array_sum(array_column($b, 'amount')), 2),
            'count' => array_sum(array_column($b, 'count')),
            'as_of' => $ctx['today'],
        ];
    }

    /** Stock rows use the Classic "Stock Alert" rule (manage_stock, qty <= alert) so the count matches that list. */
    private static function stockHealth(array $ctx): array
    {
        \App\Services\Costing\CostingReader::prepareStockValue();   // moving-average costing (no-op while off)
        $base = fn () => DB::table('product_warehouse')
            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->whereNull('product_warehouse.deleted_at')->whereNull('products.deleted_at')
            ->whereIn('product_warehouse.warehouse_id', $ctx['scoped_ids']);

        $low = (int) $base()->where('product_warehouse.manage_stock', true)->whereRaw('product_warehouse.qte <= products.stock_alert')->count();
        $out = (int) $base()->where('product_warehouse.manage_stock', true)->whereRaw('product_warehouse.qte <= products.stock_alert')->where('product_warehouse.qte', '<=', 0)->count();

        // Dead stock: stock on hand, at cost, of products that were created before the cut-off and have not sold at all since it.
        $cut = Carbon::parse($ctx['today'])->subDays(self::DEAD_STOCK_DAYS)->toDateString();
        $dead = $base()
            ->leftJoin('product_variants', function ($j) {
                $j->on('product_warehouse.product_variant_id', '=', 'product_variants.id')->where('products.is_variant', '=', 1);
            })
            ->tap(fn ($q) => \App\Services\Costing\CostingReader::joinBalance($q))
            ->where('product_warehouse.qte', '>', 0)
            ->whereDate('products.created_at', '<=', $cut)
            ->whereNotExists(function ($q) use ($cut) {
                $q->select(DB::raw(1))->from('sale_details as d')->join('sales as s', 's.id', '=', 'd.sale_id')
                    ->whereColumn('d.product_id', 'product_warehouse.product_id')
                    ->whereNull('s.deleted_at')->where('s.statut', SalesFigures::SALE_STATUS)->where('s.date', '>=', $cut);
            })
            ->selectRaw('COALESCE(SUM(product_warehouse.qte * '.\App\Services\Costing\CostingReader::unitCostSql(
                'CASE WHEN products.is_variant = 1 AND product_variants.id IS NOT NULL THEN COALESCE(product_variants.cost, 0) ELSE COALESCE(products.cost, 0) END'
            ).'),0) AS value, COUNT(DISTINCT product_warehouse.product_id) AS n')
            ->first();

        return [
            'low_count' => $low, 'out_count' => $out,
            'dead_value' => round((float) $dead->value, 2), 'dead_products' => (int) $dead->n, 'dead_days' => self::DEAD_STOCK_DAYS,
        ];
    }

    /** Who the period's sales came from (completed sales, GrandTotal). Share is of the period's total. */
    private static function customerMix(array $ctx): array
    {
        $base = fn () => self::scope(
            DB::table('sales')->whereNull('sales.deleted_at')->where('sales.statut', SalesFigures::SALE_STATUS)->whereBetween('sales.date', [$ctx['from'], $ctx['to']]),
            $ctx, 'sales'
        );
        $total = (float) $base()->sum('GrandTotal');
        $rows = $base()->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->groupBy('sales.client_id', 'clients.name')->orderByDesc(DB::raw('SUM(sales.GrandTotal)'))->limit(5)
            ->selectRaw('sales.client_id AS id, clients.name AS name, COALESCE(SUM(sales.GrandTotal),0) AS amount, COUNT(*) AS invoices')->get();

        $top = $rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name ?? '', 'amount' => round((float) $r->amount, 2), 'invoices' => (int) $r->invoices,
            'share_pct' => $total > 0.005 ? round((float) $r->amount / $total * 100, 1) : null,
        ])->values()->all();

        return ['total' => round($total, 2), 'top' => $top];
    }

    private static function attention(array $ctx, array $aging, array $stock): array
    {
        $docs = function (string $table, array $statuses) use ($ctx) {
            $r = self::scope(DB::table($table)->whereNull('deleted_at')->whereIn('statut', $statuses), $ctx, $table)
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(GrandTotal),0) AS amount')->first();

            return ['count' => (int) $r->n, 'amount' => round((float) $r->amount, 2)];
        };
        $registers = DB::table('cash_registers')->where('status', 'open')->whereIn('warehouse_id', $ctx['scoped_ids']);
        if (! $ctx['view_records']) {
            $registers->where('user_id', $ctx['user_id']);
        }

        return [
            'low_stock' => ['count' => $stock['low_count'], 'out' => $stock['out_count']],
            'overdue_30' => $aging['buckets']['d31_plus'],
            'purchases_to_receive' => $docs('purchases', ['pending', 'ordered']),
            'pending_sales' => $docs('sales', ['pending']),
            'open_registers' => (int) $registers->count(),
        ];
    }

    /**
     * Completed sales of the period by WHERE they went: the sale's own Zone/Area when it has one (that is where the goods were
     * delivered), otherwise the customer's city. Neither set = "unlocated", reported as such and never guessed.
     */
    private static function geo(array $ctx): array
    {
        $rows = self::scope(
            DB::table('sales')->whereNull('sales.deleted_at')->where('sales.statut', SalesFigures::SALE_STATUS)->whereBetween('sales.date', [$ctx['from'], $ctx['to']]),
            $ctx, 'sales'
        )->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoin('sale_zones', function ($j) {
                $j->on('sale_zones.id', '=', 'sales.zone_id')->whereNull('sale_zones.deleted_at');
            })
            // place = zone name if the sale has one, else the customer's city; a zone carries no country (it is a local delivery zone)
            ->selectRaw("CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN 'zone' ELSE 'city' END AS source,
                         MIN(CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN TRIM(sale_zones.name) ELSE TRIM(COALESCE(clients.city,'')) END) AS city,
                         MIN(CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN '' ELSE TRIM(COALESCE(clients.country,'')) END) AS country,
                         COALESCE(SUM(sales.GrandTotal),0) AS amount, COUNT(*) AS invoices")
            ->groupBy(DB::raw("CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN 'zone' ELSE 'city' END,
                               LOWER(CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN TRIM(sale_zones.name) ELSE TRIM(COALESCE(clients.city,'')) END),
                               LOWER(CASE WHEN TRIM(COALESCE(sale_zones.name,'')) <> '' THEN '' ELSE TRIM(COALESCE(clients.country,'')) END)"))
            ->orderByDesc(DB::raw('SUM(sales.GrandTotal)'))->get();

        $places = [];
        $unlocated = ['amount' => 0.0, 'invoices' => 0];
        foreach ($rows as $r) {
            if ($r->city === '') {
                $unlocated['amount'] += (float) $r->amount;
                $unlocated['invoices'] += (int) $r->invoices;

                continue;
            }
            $places[] = ['city' => $r->city, 'country' => $r->country, 'source' => $r->source, 'amount' => round((float) $r->amount, 2), 'invoices' => (int) $r->invoices];
        }

        return [
            'places' => array_slice($places, 0, 60),
            'unlocated' => ['amount' => round($unlocated['amount'], 2), 'invoices' => $unlocated['invoices']],
            'total' => round(array_sum(array_column($places, 'amount')) + $unlocated['amount'], 2),
        ];
    }

    /** Latest recorded changes (Sale / Purchase / Product / Customer), same rows as the Activity Log report. */
    public static function activity(int $limit = 30): array
    {
        return ActivityLog::query()->with('user:id,firstname,lastname')->orderByDesc('id')->limit($limit)->get()->map(fn ($a) => [
            'id' => $a->id, 'module' => $a->module, 'action' => $a->action, 'description' => $a->description,
            'user' => $a->user ? trim($a->user->firstname.' '.$a->user->lastname) : null,
            'subject_type' => $a->subject_type, 'subject_id' => $a->subject_id,
            'at' => optional($a->created_at)->toIso8601String(),
        ])->all();
    }
}
