<?php

namespace App\Support\Reporting;

use App\Models\Expense;
use App\Models\PaymentOpeningBalance;
use App\Models\PaymentPurchase;
use App\Models\PaymentPurchaseReturns;
use App\Models\PaymentSale;
use App\Models\PaymentSaleReturns;
use App\Models\Purchase;
use App\Models\ProviderPaymentOpeningBalance;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard "Today" view (Audit Batch 6): when the header range is ONE day, the Sales & Purchases line chart and the
 * Payment Sent & Received bar chart show the 24 hours of that day instead of a single dot.
 *
 * Every figure uses the same rows, scope and definitions as the day-by-day charts in DashboardController, only
 * grouped by hour instead of by day, so the 24 hourly values always add up to the one daily value:
 *  - sales:      completed sales, SUM(GrandTotal), hour = sales.time
 *  - purchases:  received purchases, SUM(GrandTotal), hour = purchases.time
 *  - received:   sale payments + purchase-return refunds + customer opening-balance payments, hour = created_at
 *  - sent:       purchase payments + sale-return refunds + expenses + supplier opening-balance payments
 * Scope: record_view (own rows only when the user lacks it) and the warehouse filter, exactly like the daily charts.
 */
class DashboardHourly
{
    /**
     * @return array{date:string,hours:int[],sales:float[],purchases:float[],received:float[],sent:float[]}
     */
    public static function build(int $warehouseId, array $warehouseIds, string $date): array
    {
        $user = Auth::user();
        $viewRecords = $user->hasRecordView();
        $userId = $user->id;

        $own = function ($q) use ($viewRecords, $userId) {
            if (! $viewRecords) {
                $q->where('user_id', '=', $userId);
            }
        };
        $wh = function ($q, ?string $relation = null) use ($warehouseId, $warehouseIds) {
            $apply = function ($x) use ($warehouseId, $warehouseIds) {
                $warehouseId !== 0 ? $x->where('warehouse_id', $warehouseId) : $x->whereIn('warehouse_id', $warehouseIds);
            };
            $relation ? $q->whereHas($relation, $apply) : $apply($q);
        };

        $sales = self::fold(
            Sale::where('date', $date)->whereNull('deleted_at')->where('statut', 'completed')
                ->tap($own)->tap(fn ($q) => $wh($q)),
            self::saleHour(), 'GrandTotal'
        );
        $purchases = self::fold(
            Purchase::where('date', $date)->whereNull('deleted_at')->where('statut', 'received')
                ->tap($own)->tap(fn ($q) => $wh($q)),
            self::saleHour(), 'GrandTotal'
        );

        $pay = fn ($model, ?string $relation) => self::fold(
            $model::where('date', $date)->tap($own)->tap(fn ($q) => $relation ? $wh($q, $relation) : null),
            self::createdHour(), 'montant'
        );
        $received = self::add(
            $pay(PaymentSale::class, 'sale'),
            $pay(PaymentPurchaseReturns::class, 'PurchaseReturn'),
            $pay(PaymentOpeningBalance::class, null)
        );
        $sent = self::add(
            $pay(PaymentPurchase::class, 'purchase'),
            $pay(PaymentSaleReturns::class, 'SaleReturn'),
            self::fold(Expense::where('date', $date)->whereNull('deleted_at')->tap($own)->tap(fn ($q) => $wh($q)), self::createdHour(), 'amount'),
            $pay(ProviderPaymentOpeningBalance::class, null)
        );

        return [
            'date' => $date,
            'hours' => range(0, 23),
            'sales' => $sales,
            'purchases' => $purchases,
            'received' => $received,
            'sent' => $sent,
        ];
    }

    /** hour from the document's own `time` column (sales and purchases both have one) */
    private static function saleHour(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', COALESCE(time, '00:00:00')) AS INTEGER)"
            : "HOUR(COALESCE(time, '00:00:00'))";
    }

    private static function createdHour(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';
    }

    /** @return float[] 24 values, index = hour of day */
    private static function fold($query, string $hourExpr, string $column): array
    {
        $out = array_fill(0, 24, 0.0);
        $rows = $query->selectRaw("{$hourExpr} AS h, SUM({$column}) AS v")->groupBy(DB::raw($hourExpr))->get();
        foreach ($rows as $r) {
            $h = (int) $r->h;
            if ($h >= 0 && $h <= 23) {
                $out[$h] += (float) $r->v;
            }
        }

        return $out;
    }

    /** @return float[] */
    private static function add(array ...$series): array
    {
        $out = array_fill(0, 24, 0.0);
        foreach ($series as $s) {
            foreach ($s as $h => $v) {
                $out[$h] += $v;
            }
        }

        return $out;
    }
}
