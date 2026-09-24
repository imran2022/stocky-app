<?php

namespace App\Support\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Batch 4, findings C4 / H1 / H2 / M16): ONE definition of the sales money figures, shared by every
 * report that shows revenue, tax, discount or profit. Before, each report did its own arithmetic (Dashboard,
 * P&L, Analytics, Tax summary, Discount summary, Profit report) and no two agreed.
 *
 * Definitions (agreed with the business owner, Batch 4):
 *  - Only COMPLETED sales and RECEIVED sale returns count. Pending / ordered / draft documents are never revenue.
 *  - gross      = SUM(GrandTotal)               what customers were invoiced
 *  - order_tax  = SUM(TaxNet)                   header (order) tax
 *  - line_tax   = SUM of the tax inside every line (see lineTaxSql)
 *  - tax        = order_tax + line_tax
 *  - shipping   = SUM(shipping)
 *  - net        = gross - tax - shipping        NET SALES: the business's own income, no tax, no delivery charge.
 *                 Discounts are NOT subtracted again: GrandTotal is already after every discount.
 *  - discount   = header discount in MONEY: a percent discount is converted with the sale's own line total
 *                 (a "10%" is never added to taka amounts). Loyalty-points and promotion discounts are reported
 *                 separately because they have their own columns.
 *
 * Every method takes a base query on `sales` / `sale_returns` that already carries the caller's date, warehouse
 * and permission scope; the status and soft-delete rules are applied here so no report can forget them.
 */
class SalesFigures
{
    public const SALE_STATUS = 'completed';

    public const RETURN_STATUS = 'received';

    /**
     * Tax carried by one document line, mirroring resources/src/lib/lineCalc.js exactly:
     *  - exclusive (tax_method '1'): total = qty x base x (1 + t/100)   -> tax = total x t / (100 + t)
     *  - inclusive (anything else): tax = base x t / 100 per unit        -> tax = total x t / 100
     */
    public static function lineTaxSql(string $alias = 'd'): string
    {
        return "(CASE WHEN {$alias}.tax_method = '1'
                      THEN {$alias}.total * COALESCE({$alias}.TaxNet, 0) / (100 + COALESCE({$alias}.TaxNet, 0))
                      ELSE {$alias}.total * COALESCE({$alias}.TaxNet, 0) / 100 END)";
    }

    /**
     * @param  callable(Builder):mixed|null  $scope  adds date / warehouse / user scope to a query on `sales`
     * @return array{count:int,gross:float,order_tax:float,line_tax:float,tax:float,shipping:float,net:float,discount:float,points_discount:float,promotion_discount:float}
     */
    public static function sales(?callable $scope = null): array
    {
        return self::figures('sales', 'sale_details', 'sale_id', self::SALE_STATUS, $scope, true);
    }

    /** Received purchases: same shape (tax / shipping / net) so purchase-side reports use the same arithmetic. */
    public static function purchases(?callable $scope = null): array
    {
        return self::figures('purchases', 'purchase_details', 'purchase_id', 'received', $scope, false);
    }

    /** Completed purchase returns. */
    public static function purchaseReturns(?callable $scope = null): array
    {
        return self::figures('purchase_returns', 'purchase_return_details', 'purchase_return_id', 'completed', $scope, false);
    }

    /**
     * @return array{count:int,gross:float,order_tax:float,line_tax:float,tax:float,shipping:float,net:float,discount:float,points_discount:float,promotion_discount:float}
     */
    public static function saleReturns(?callable $scope = null): array
    {
        return self::figures('sale_returns', 'sale_return_details', 'sale_return_id', self::RETURN_STATUS, $scope, false);
    }

    private static function figures(string $table, string $detailTable, string $fk, string $status, ?callable $scope, bool $hasPromo): array
    {
        $base = function () use ($table, $status, $scope) {
            $q = DB::table($table)->whereNull("{$table}.deleted_at")->where("{$table}.statut", $status);
            if ($scope) {
                $scope($q);
            }

            return $q;
        };

        $head = $base()->selectRaw(
            'COUNT(*) AS n, COALESCE(SUM(GrandTotal),0) AS gross, COALESCE(SUM(TaxNet),0) AS order_tax, COALESCE(SUM(shipping),0) AS shipping'
        )->first();

        $lineTax = (float) DB::table("{$detailTable} as d")
            ->whereIn("d.{$fk}", $base()->select("{$table}.id"))
            ->selectRaw('COALESCE(SUM('.self::lineTaxSql('d').'),0) AS t')
            ->value('t');

        // Header discount in money. A percent discount is a % of the document's line total.
        $methodCol = $hasPromo ? "{$table}.discount_Method" : "'2'";
        $discount = (float) $base()
            ->joinSub(
                DB::table($detailTable)->selectRaw("{$fk} AS doc_id, SUM(total) AS line_sum")
                    ->whereIn($fk, $base()->select("{$table}.id"))->groupBy($fk),
                'dl', 'dl.doc_id', '=', "{$table}.id"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$methodCol} = '1' THEN dl.line_sum * LEAST(COALESCE({$table}.discount,0),100) / 100
                                   ELSE LEAST(COALESCE({$table}.discount,0), dl.line_sum) END),0) AS d"
            )->value('d');

        $extra = $hasPromo
            ? $base()->selectRaw('COALESCE(SUM(discount_from_points),0) AS pts, COALESCE(SUM(promotion_discount),0) AS promo')->first()
            : (object) ['pts' => 0, 'promo' => 0];

        $gross = (float) $head->gross;
        $orderTax = (float) $head->order_tax;
        $shipping = (float) $head->shipping;

        return [
            'count' => (int) $head->n,
            'gross' => $gross,
            'order_tax' => $orderTax,
            'line_tax' => $lineTax,
            'tax' => $orderTax + $lineTax,
            'shipping' => $shipping,
            'net' => $gross - $orderTax - $lineTax - $shipping,
            'discount' => $discount,
            'points_discount' => (float) $extra->pts,
            'promotion_discount' => (float) $extra->promo,
        ];
    }
}
