<?php

namespace App\Http\Controllers;

use App\Models\UserWarehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TodaySummaryController extends Controller
{
    use \App\Traits\CalculatesCogsAndAverageCost; // Audit B5: same COGS as Dashboard / P&L

    /**
     * Everything the topbar "Today's summary" drawer shows, in one call.
     *
     * Two restrictions are applied everywhere the underlying row supports them:
     *  - warehouse: users without `is_all_warehouses` only see the warehouses
     *    assigned to them (sales, purchases, returns, payments, expenses, stock);
     *  - records: users without the `record_view` permission only see the rows
     *    they own (user_id), exactly like the list pages and reports do.
     * Rows with no such column (stock levels, new customers) stay global.
     */
    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $user = Auth::user();

        $warehouseIds = null; // null = unrestricted
        if ($user && ! $user->is_all_warehouses) {
            $warehouseIds = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
        }
        $wh = function ($q, $col = 'warehouse_id') use ($warehouseIds) {
            if ($warehouseIds !== null) {
                $q->whereIn($col, $warehouseIds);
            }

            return $q;
        };

        // Same record_view resolution as the rest of the app (user flag first,
        // role permission as the backward-compatible fallback).
        $viewRecords = $user ? $user->hasRecordView() : false;
        $userId = $user ? $user->id : 0;
        $own = function ($q, $col = 'user_id') use ($viewRecords, $userId) {
            if (! $viewRecords) {
                $q->where($col, $userId);
            }

            return $q;
        };

        /* ------------------------------------------------------------ sales */
        // Audit B5: same definitions as Dashboard / P&L (SalesFigures): completed sales only, line tax + order tax.
        $salesScope = function ($q) use ($today, $own, $wh) {
            $q->whereBetween('sales.date', [$today, $today]);
            $wh($q, 'sales.warehouse_id');
            $own($q, 'sales.user_id');
        };
        $sf = \App\Support\Reporting\SalesFigures::sales($salesScope);
        $s = (object) ['c' => $sf['count'], 'net' => $sf['gross'], 'tax' => $sf['tax'], 'ship' => $sf['shipping'],
                       'disc' => $sf['discount'] + $sf['points_discount'] + $sf['promotion_discount']];
        $itemsSold = (float) $own($wh(
            DB::table('sale_details')
                ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
                ->whereNull('sales.deleted_at')->where('sales.statut', 'completed')->where('sales.date', $today),
            'sales.warehouse_id'
        ), 'sales.user_id')->sum('sale_details.quantity');
        $saleReturns = (float) $own($wh(DB::table('sale_returns')->where('statut', 'received')->whereNull('deleted_at')->where('date', $today)))
            ->sum('GrandTotal');

        $taxableSales = $sf['net'];
        $sales = [
            'transactions' => (int) $s->c,
            'gross' => round($taxableSales + $s->disc, 2),
            'discounts' => round((float) $s->disc, 2),
            'taxable' => round($taxableSales, 2),
            'tax' => round((float) $s->tax, 2),
            'net' => round((float) $s->net, 2),
            'items' => round($itemsSold, 2),
            'returns' => round($saleReturns, 2),
            'average' => $s->c > 0 ? round($s->net / $s->c, 2) : 0,
        ];

        /* -------------------------------------------------------- purchases */
        $purchScope = function ($q) use ($today, $own, $wh) {
            $q->whereBetween('purchases.date', [$today, $today]);
            $wh($q, 'purchases.warehouse_id');
            $own($q, 'purchases.user_id');
        };
        $pf = \App\Support\Reporting\SalesFigures::purchases($purchScope);
        $p = (object) ['c' => $pf['count'], 'net' => $pf['gross'], 'tax' => $pf['tax'], 'ship' => $pf['shipping'],
                       'disc' => $pf['discount']];
        $itemsPurchased = (float) $own($wh(
            DB::table('purchase_details')
                ->join('purchases', 'purchases.id', '=', 'purchase_details.purchase_id')
                ->whereNull('purchases.deleted_at')->where('purchases.statut', 'received')->where('purchases.date', $today),
            'purchases.warehouse_id'
        ), 'purchases.user_id')->sum('purchase_details.quantity');
        $purchaseReturns = (float) $own($wh(DB::table('purchase_returns')->where('statut', 'completed')->whereNull('deleted_at')->where('date', $today)))
            ->sum('GrandTotal');

        $taxablePurch = $pf['net'];
        $purchases = [
            'transactions' => (int) $p->c,
            'gross' => round($taxablePurch + $p->disc, 2),
            'discounts' => round((float) $p->disc, 2),
            'taxable' => round($taxablePurch, 2),
            'tax' => round((float) $p->tax, 2),
            'net' => round((float) $p->net, 2),
            'items' => round($itemsPurchased, 2),
            'returns' => round($purchaseReturns, 2),
            'average' => $p->c > 0 ? round($p->net / $p->c, 2) : 0,
        ];

        /* ------------------------------------------------------------ stock */
        $stockRow = $wh(
            DB::table('product_warehouse')
                ->join('products', 'products.id', '=', 'product_warehouse.product_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'product_warehouse.product_variant_id')
                ->whereNull('product_warehouse.deleted_at')
                ->whereNull('products.deleted_at')
                ->where('products.type', '!=', 'is_service'),
            'product_warehouse.warehouse_id'
        )
            ->selectRaw('COALESCE(SUM(product_warehouse.qte * COALESCE(product_variants.cost, products.cost, 0)),0) at_cost,
                         COALESCE(SUM(product_warehouse.qte * COALESCE(product_variants.price, products.price, 0)),0) at_retail,
                         COALESCE(SUM(product_warehouse.qte),0) units')
            ->first();

        $outOfStock = (int) DB::table('products')
            ->whereNull('deleted_at')
            ->where('type', '!=', 'is_service')
            ->whereNotExists(function ($q) use ($warehouseIds) {
                $q->selectRaw(1)
                    ->from('product_warehouse')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereNull('product_warehouse.deleted_at')
                    ->where('product_warehouse.qte', '>', 0);
                if ($warehouseIds !== null) {
                    $q->whereIn('product_warehouse.warehouse_id', $warehouseIds);
                }
            })
            ->count();

        $stock = [
            'at_cost' => round((float) $stockRow->at_cost, 2),
            'at_retail' => round((float) $stockRow->at_retail, 2),
            'margin' => round($stockRow->at_retail - $stockRow->at_cost, 2),
            'units' => round((float) $stockRow->units, 2),
            'out_of_stock' => $outOfStock,
        ];

        /* --------------------------------------------------------- payments */
        // Payments carry no warehouse of their own, so the parent sale/purchase
        // is joined to apply the warehouse restriction (and to skip payments
        // left behind by a deleted document).
        $received = $own($wh(
            DB::table('payment_sales')
                ->join('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
                ->join('sales', 'sales.id', '=', 'payment_sales.sale_id')
                ->whereNull('payment_sales.deleted_at')
                ->whereNull('sales.deleted_at')
                ->whereDate('payment_sales.date', $today),
            'sales.warehouse_id'
        ), 'payment_sales.user_id')
            ->groupBy('payment_methods.name')
            ->selectRaw('payment_methods.name, COALESCE(SUM(payment_sales.montant),0) amount')
            ->pluck('amount', 'name');
        $given = $own($wh(
            DB::table('payment_purchases')
                ->join('payment_methods', 'payment_methods.id', '=', 'payment_purchases.payment_method_id')
                ->join('purchases', 'purchases.id', '=', 'payment_purchases.purchase_id')
                ->whereNull('payment_purchases.deleted_at')
                ->whereNull('purchases.deleted_at')
                ->whereDate('payment_purchases.date', $today),
            'purchases.warehouse_id'
        ), 'payment_purchases.user_id')
            ->groupBy('payment_methods.name')
            ->selectRaw('payment_methods.name, COALESCE(SUM(payment_purchases.montant),0) amount')
            ->pluck('amount', 'name');

        // Opening-balance settlements are cash movements too: client side counts as
        // received, supplier side as given.
        // Opening-balance settlements have no warehouse column; only the record
        // restriction can apply to them.
        $openingReceived = $own(
            DB::table('client_opening_balance_payments')
                ->join('payment_methods', 'payment_methods.id', '=', 'client_opening_balance_payments.payment_method_id')
                ->whereNull('client_opening_balance_payments.deleted_at')
                ->whereDate('client_opening_balance_payments.date', $today),
            'client_opening_balance_payments.user_id'
        )
            ->groupBy('payment_methods.name')
            ->selectRaw('payment_methods.name, COALESCE(SUM(client_opening_balance_payments.montant),0) amount')
            ->pluck('amount', 'name');
        foreach ($openingReceived as $name => $amount) {
            $received[$name] = (float) ($received[$name] ?? 0) + (float) $amount;
        }

        $openingGiven = $own(
            DB::table('provider_opening_balance_payments')
                ->join('payment_methods', 'payment_methods.id', '=', 'provider_opening_balance_payments.payment_method_id')
                ->whereNull('provider_opening_balance_payments.deleted_at')
                ->whereDate('provider_opening_balance_payments.date', $today),
            'provider_opening_balance_payments.user_id'
        )
            ->groupBy('payment_methods.name')
            ->selectRaw('payment_methods.name, COALESCE(SUM(provider_opening_balance_payments.montant),0) amount')
            ->pluck('amount', 'name');
        foreach ($openingGiven as $name => $amount) {
            $given[$name] = (float) ($given[$name] ?? 0) + (float) $amount;
        }

        $methods = collect($received->keys())->merge($given->keys())->unique()->values();
        $payments = [
            'methods' => $methods,
            'received' => $methods->mapWithKeys(fn ($m) => [$m => round((float) ($received[$m] ?? 0), 2)]),
            'given' => $methods->mapWithKeys(fn ($m) => [$m => round((float) ($given[$m] ?? 0), 2)]),
            'received_total' => round((float) $received->sum(), 2),
            'given_total' => round((float) $given->sum(), 2),
        ];

        /* ----------------------------------------------------------- profit */
        // Same COGS as Dashboard / P&L (FIFO, base-unit quantities, received returns netted off).
        $cogsWarehouses = $warehouseIds ?? DB::table('warehouses')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $cogs = $cogsWarehouses ? (float) ($this->calcCogsAndAvgCostFast($today, $today, null, $cogsWarehouses)['fifo'] ?? 0.0) : 0.0;

        // Net sales after net returns, exactly like the Dashboard profit figure.
        $retScope = function ($q) use ($today, $own, $wh) {
            $q->whereBetween('sale_returns.date', [$today, $today]);
            $wh($q, 'sale_returns.warehouse_id');
            $own($q, 'sale_returns.user_id');
        };
        $profitBase = $taxableSales - \App\Support\Reporting\SalesFigures::saleReturns($retScope)['net'];

        $expenses = (float) $own($wh(
            DB::table('expenses')->whereNull('deleted_at')->whereDate('date', $today)
        ))->sum('amount');

        $grossProfit = $profitBase - $cogs;
        $netProfit = $grossProfit - $expenses;
        $profit = [
            'cogs' => round($cogs, 2),
            'gross' => round($grossProfit, 2),
            'gross_pct' => $profitBase > 0 ? round($grossProfit / $profitBase * 100) : 0,
            'net' => round($netProfit, 2),
            'net_pct' => $profitBase > 0 ? round($netProfit / $profitBase * 100) : 0,
            'tax_collected' => $sales['tax'],
            'tax_paid' => $purchases['tax'],
            'expenses' => round($expenses, 2),
        ];

        /* ------------------------------------------------------------ tiles */
        // Clients carry neither a warehouse nor an owner column, so this tile
        // stays global for every user.
        $newCustomers = (int) DB::table('clients')->whereNull('deleted_at')->whereDate('created_at', $today)->count();

        $lowStock = (int) $wh(
            DB::table('product_warehouse')
                ->join('products', 'products.id', '=', 'product_warehouse.product_id')
                ->whereNull('product_warehouse.deleted_at')
                ->whereNull('products.deleted_at')
                ->where('products.stock_alert', '>', 0)
                ->whereColumn('product_warehouse.qte', '<=', 'products.stock_alert'),
            'product_warehouse.warehouse_id'
        )->distinct()->count('product_warehouse.product_id');

        $onShift = 0;
        if (Schema::hasTable('attendances')) {
            // Same record restriction the attendance report applies.
            $onShift = (int) $own(
                DB::table('attendances')
                    ->whereNull('deleted_at')
                    ->whereDate('date', $today)
                    ->where(function ($q) {
                        $q->whereNull('clock_out')->orWhere('clock_out', '');
                    })
            )->count();
        }

        return response()->json([
            'date' => $today,
            'sales' => $sales,
            'purchases' => $purchases,
            'stock' => $stock,
            'payments' => $payments,
            'profit' => $profit,
            'tiles' => [
                'new_customers' => $newCustomers,
                'low_stock' => $lowStock,
                'on_shift' => $onShift,
            ],
        ]);
    }
}
