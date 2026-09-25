<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Product;
use App\Models\Client;
use App\Models\PaymentSale;
use App\Traits\CalculatesCogsAndAverageCost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ReportQuestionService
{
    use CalculatesCogsAndAverageCost;

    /**
     * Execute daily sales summary report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @return array
     */
    public function dailySalesSummary(string $dateFrom, string $dateTo, ?int $warehouseId = null): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        // Get user's accessible warehouses
        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);

        // Build query
        $query = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereBetween('date', [$dateFrom, $dateTo]);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        } else {
            $query->whereIn('warehouse_id', $warehouseIds);
        }

        if (!$viewRecords) {
            $query->where('user_id', $user->id);
        }

        // Get aggregates
        $sales = $query->get();

        $transactions = $sales->count();
        $revenue = $sales->sum('GrandTotal');
        $tax = $sales->sum('TaxNet');
        $discount = $sales->sum('discount');

        // Calculate profit using COGS
        $cogsPack = $this->calcCogsAndAvgCostFast($dateFrom, $dateTo, $warehouseId, $warehouseIds);
        $cogsFIFO = $cogsPack['fifo'] ?? 0.0;

        // Get expenses for the period
        $expenses = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            }, function ($q) use ($warehouseIds) {
                return $q->whereIn('warehouse_id', $warehouseIds);
            })
            ->when(!$viewRecords, function ($q) use ($user) {
                return $q->where('user_id', $user->id);
            })
            ->sum('amount');

        $profit = $revenue - $cogsFIFO - $expenses;

        return [
            'transactions' => $transactions,
            'revenue' => (float) $revenue,
            'tax' => (float) $tax,
            'discount' => (float) $discount,
            'profit' => (float) $profit,
        ];
    }

    /**
     * Execute sales by product report
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @param int|null $warehouseId
     * @param array $filters
     * @return array
     */
    public function salesByProduct(string $dateFrom, string $dateTo, ?int $warehouseId = null, array $filters = []): array
    {
        $user = Auth::user();
        $viewRecords = $user ? $user->hasRecordView() : false;

        $warehouseIds = $this->getUserWarehouseIds($user, $warehouseId);
        $limit = $filters['limit'] ?? 10;
        $sortBy = $filters['sort_by'] ?? 'profit';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        // Audit fix (ReportQuestionService costing parity, follow-up to Inventory Costing update 5): this AI
        // "ask a report question" feature used to run its OWN, cruder legacy-COGS formula
        // (SUM(qty * today's flat master/variant cost), raw sale-unit quantity, sale returns never netted off) —
        // a third, untested formula reintroducing exactly the bug class `LegacyProfitLines`/`HistoricalCostAtDate`
        // were built to eliminate in the Profit Report. It also never netted received returns off revenue/qty in
        // EITHER costing mode. Now built on the same normalized sources the Profit Report uses — completed sales
        // plus received returns (netted), base-unit quantities, warehouse-specific date-anchored average cost in
        // legacy mode, the stored ledger cost in Moving Average mode — so this feature can never disagree with the
        // Profit Report again. The existing "own records only" restriction (hasRecordView) is preserved exactly.
        $costing = \App\Services\Costing\CostingReader::active();
        $allowed = $warehouseId ? null : $warehouseIds;
        $userId = $viewRecords ? null : ($user->id ?? null);

        $tmpTable = $costing
            ? \App\Services\Costing\CostingReader::profitLinesTemp($dateFrom, $dateTo, $warehouseId ?: null, $allowed, $viewRecords, $userId)
            : \App\Support\Reporting\LegacyProfitLines::temp($dateFrom, $dateTo, $warehouseId ?: null, $allowed, $viewRecords, $userId);

        $costExpr = 'sd.line_cost';
        $query = DB::table("{$tmpTable} as sd")->join('products as p', 'p.id', '=', 'sd.product_id');
        if ($costing) {
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id'); // unused in cost, kept for parity/name lookups
        } else {
            $soldProductIds = DB::table($tmpTable)->distinct()->pluck('product_id')->all();
            $histCostTable = \App\Support\Reporting\HistoricalCostAtDate::temp($dateTo, $warehouseId ?: null, $allowed, $soldProductIds);
            $query->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id')
                ->leftJoin("{$histCostTable} as hc", function ($j) {
                    $j->on('hc.product_id', '=', 'sd.product_id')
                        ->on('hc.warehouse_id', '=', 'sd.warehouse_id')
                        ->whereRaw('hc.product_variant_id <=> sd.product_variant_id');
                });
            $costExpr = 'sd.base_quantity * COALESCE(hc.avg_cost, pv.cost, p.cost, 0)';
        }

        // qty stays the raw (sale-unit) quantity in both modes — same column this feature has always displayed;
        // only the COST calculation needs the base-unit quantity (see LegacyProfitLines). Changing the Qty column
        // itself to base units is a separate report-presentation decision, deliberately out of scope here (same as
        // the Sale Return pack/unit fix).
        $results = $query
            ->select(
                'sd.product_id',
                DB::raw('COALESCE(NULLIF(TRIM(p.name), ""), CONCAT("Product #", sd.product_id)) as name'),
                DB::raw('SUM(sd.quantity) as qty'),
                DB::raw('SUM(sd.total) as revenue'),
                DB::raw('SUM('.$costExpr.') as cost')
            )
            ->groupBy('sd.product_id', 'p.name')
            ->get()
            ->map(function ($item) {
                $revenue = (float) $item->revenue;
                $cost = (float) $item->cost;
                $profit = $revenue - $cost;
                $marginPercent = $revenue > 0 ? (($profit / $revenue) * 100) : 0;
                $displayName = $item->name !== null && (string) $item->name !== '' ? (string) $item->name : 'Product #' . $item->product_id;

                return [
                    'product_id' => $item->product_id,
                    'name' => $displayName,
                    'qty' => (float) $item->qty,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin_percent' => round($marginPercent, 2),
                ];
            });

        // Sort
        $results = $results->sortBy(function ($item) use ($sortBy) {
            return $item[$sortBy];
        }, SORT_REGULAR, $sortDir === 'desc');

        // Limit
        return $results->take($limit)->values()->toArray();
    }

    /**
     * Execute late payments report
     *
     * @param array $filters
     * @return array
     */
    public function latePayments(array $filters = []): array
    {
        $minDaysOverdue = (int) ($filters['min_days_overdue'] ?? 30);
        $today = Carbon::today()->startOfDay();

        // Get sales with outstanding amounts (only invoice date on or before today)
        $sales = Sale::whereNull('deleted_at')
            ->where('statut', 'completed')
            ->whereRaw('(GrandTotal - paid_amount) > 0.01')
            ->whereDate('date', '<=', $today->toDateString())
            ->with('client')
            ->get();

        $customerData = [];

        foreach ($sales as $sale) {
            $dueAmount = $sale->GrandTotal - $sale->paid_amount;
            $saleDate = Carbon::parse($sale->date)->startOfDay();
            // Use absolute difference: past invoices => positive days overdue (we already filter date <= today in the query)
            $daysOverdue = (int) $today->diffInDays($saleDate, true);

            if ($daysOverdue >= $minDaysOverdue) {
                $customerId = $sale->client_id;
                $customerName = $sale->client ? $sale->client->name : 'Unknown';

                if (!isset($customerData[$customerId])) {
                    $customerData[$customerId] = [
                        'customer_id' => $customerId,
                        'name' => $customerName,
                        'invoices_count' => 0,
                        'outstanding_amount' => 0,
                        'max_days_overdue' => 0,
                    ];
                }

                $customerData[$customerId]['invoices_count']++;
                $customerData[$customerId]['outstanding_amount'] += $dueAmount;
                $customerData[$customerId]['max_days_overdue'] = max(
                    $customerData[$customerId]['max_days_overdue'],
                    $daysOverdue
                );
            }
        }

        // Sort by outstanding_amount descending
        usort($customerData, function ($a, $b) {
            return $b['outstanding_amount'] <=> $a['outstanding_amount'];
        });

        return array_values($customerData);
    }

    /**
     * Generate insights by comparing two periods
     *
     * @param array $currentData
     * @param array $compareData
     * @return string
     */
    public function generateInsights(array $currentData, array $compareData): string
    {
        // Placeholder for AI integration - simple comparison for now
        $currentProfit = $currentData['profit'] ?? 0;
        $compareProfit = $compareData['profit'] ?? 0;
        $profitDelta = $currentProfit - $compareProfit;
        $profitPercentChange = $compareProfit != 0 ? (($profitDelta / abs($compareProfit)) * 100) : 0;

        $currentRevenue = $currentData['revenue'] ?? 0;
        $compareRevenue = $compareData['revenue'] ?? 0;
        $revenueDelta = $currentRevenue - $compareRevenue;

        $currentDiscount = $currentData['discount'] ?? 0;
        $compareDiscount = $compareData['discount'] ?? 0;
        $discountDelta = $currentDiscount - $compareDiscount;

        $insights = [];
        
        if (abs($profitDelta) > 0.01) {
            $direction = $profitDelta > 0 ? 'increased' : 'decreased';
            $insights[] = sprintf(
                "Profit %s from %s to %s (change: %s, %.1f%%)",
                $direction,
                number_format($compareProfit, 2),
                number_format($currentProfit, 2),
                number_format($profitDelta, 2),
                abs($profitPercentChange)
            );
        }

        if (abs($revenueDelta) > 0.01) {
            $insights[] = sprintf(
                "Revenue changed by %s (from %s to %s)",
                number_format($revenueDelta, 2),
                number_format($compareRevenue, 2),
                number_format($currentRevenue, 2)
            );
        }

        if (abs($discountDelta) > 0.01) {
            $insights[] = sprintf(
                "Discount changed by %s (from %s to %s)",
                number_format($discountDelta, 2),
                number_format($compareDiscount, 2),
                number_format($currentDiscount, 2)
            );
        }

        if (empty($insights)) {
            return "No significant changes detected between periods.";
        }

        return implode('. ', $insights) . '.';
    }

    /**
     * Get user's accessible warehouse IDs
     *
     * @param mixed $user
     * @param int|null $warehouseId
     * @return array
     */
    private function getUserWarehouseIds($user, ?int $warehouseId = null): array
    {
        if ($warehouseId) {
            return [$warehouseId];
        }

        if ($user && $user->is_all_warehouses) {
            return \App\Models\Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->toArray();
        }

        if ($user) {
            return \App\Models\UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        return [];
    }
}
