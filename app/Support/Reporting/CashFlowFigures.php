<?php

namespace App\Support\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source for the Cash Flow report: every movement of money is read once, as
 * (date, group, direction, amount) entries, and both the grouped table and the day-by-day chart
 * are folded from those same entries, so the two can never disagree.
 *
 * Cash IN : sale payments, client opening-balance receipts, deposits, refunds received on purchase returns.
 * Cash OUT: purchase payments, supplier opening-balance payments, expenses, refunds paid on sale returns.
 *
 * Scoping (identical for every source that has the column):
 *  - record_view off  -> only the user's own payment / expense rows;
 *  - warehouse        -> payments follow their document's warehouse, expenses their own warehouse;
 *    deposits and opening-balance payments carry no warehouse (company-level cash), so they are
 *    shown whenever no single warehouse is requested and the user may see all records.
 * A payment method filter hides deposits (they have no method); an account filter applies everywhere.
 */
class CashFlowFigures
{
    /**
     * @param  array{from:string,to:string,group_by:string,account_id:?int,method_id:?int,warehouse_id:?int,
     *              allowed_warehouses:?array,view_records:bool,user_id:int}  $o  allowed_warehouses null = all
     * @return Collection<int, array{d:string,g:string,type:string,amount:float}>
     */
    public static function entries(array $o): Collection
    {
        $byMethod = $o['group_by'] === 'method';
        $out = collect();

        $push = function ($rows, string $type) use ($out) {
            foreach ($rows as $r) {
                $out->push(['d' => (string) $r->d, 'g' => $r->g ?? '---', 'type' => $type, 'amount' => (float) $r->t]);
            }
        };

        // document-based payments: [payment table, document table, fk, direction]
        $documents = [
            ['payment_sales', 'sales', 'sale_id', 'in'],
            ['payment_purchase_returns', 'purchase_returns', 'purchase_return_id', 'in'],
            ['payment_purchases', 'purchases', 'purchase_id', 'out'],
            ['payment_sale_returns', 'sale_returns', 'sale_return_id', 'out'],
        ];
        foreach ($documents as [$pay, $doc, $fk, $dir]) {
            $q = DB::table($pay)->leftJoin($doc, "$pay.$fk", '=', "$doc.id")
                ->whereNull("$pay.deleted_at")
                ->whereBetween("$pay.date", [$o['from'], $o['to']]);
            self::joinGroup($q, $pay, $byMethod);
            self::scopePayment($q, $pay, $o);
            if ($o['allowed_warehouses'] !== null) {
                $q->whereIn("$doc.warehouse_id", $o['allowed_warehouses']);
            }
            if ($o['warehouse_id']) {
                $q->where("$doc.warehouse_id", $o['warehouse_id']);
            }
            $push(self::fold($q, "$pay.date", "$pay.montant"), $dir);
        }

        // company-level payments (no warehouse on the row)
        if (! $o['warehouse_id']) {
            foreach ([['client_opening_balance_payments', 'in'], ['provider_opening_balance_payments', 'out']] as [$pay, $dir]) {
                $q = DB::table($pay)->whereNull("$pay.deleted_at")->whereBetween("$pay.date", [$o['from'], $o['to']]);
                self::joinGroup($q, $pay, $byMethod);
                self::scopePayment($q, $pay, $o);
                $push(self::fold($q, "$pay.date", "$pay.montant"), $dir);
            }

            // deposits: an account, but never a payment method
            if (! $o['method_id']) {
                $q = DB::table('deposits')->whereNull('deposits.deleted_at')->whereBetween('deposits.date', [$o['from'], $o['to']]);
                if ($byMethod) {
                    $q->selectRaw("'Deposit' as g");
                } else {
                    $q->leftJoin('accounts', 'deposits.account_id', '=', 'accounts.id')->selectRaw('accounts.account_name as g');
                }
                if ($o['account_id']) {
                    $q->where('deposits.account_id', $o['account_id']);
                }
                if (! $o['view_records']) {
                    $q->where('deposits.user_id', $o['user_id']);
                }
                $push(self::fold($q, 'deposits.date', 'deposits.amount'), 'in');
            }
        }

        // expenses carry their own warehouse
        $q = DB::table('expenses')->whereNull('expenses.deleted_at')->whereBetween('expenses.date', [$o['from'], $o['to']]);
        self::joinGroup($q, 'expenses', $byMethod);
        if ($o['account_id']) {
            $q->where('expenses.account_id', $o['account_id']);
        }
        if ($o['method_id']) {
            $q->where('expenses.payment_method_id', $o['method_id']);
        }
        if (! $o['view_records']) {
            $q->where('expenses.user_id', $o['user_id']);
        }
        if ($o['allowed_warehouses'] !== null) {
            $q->whereIn('expenses.warehouse_id', $o['allowed_warehouses']);
        }
        if ($o['warehouse_id']) {
            $q->where('expenses.warehouse_id', $o['warehouse_id']);
        }
        $push(self::fold($q, 'expenses.date', 'expenses.amount'), 'out');

        return $out;
    }

    /**
     * Grouped table rows + totals + day-by-day series from the same entries.
     */
    public static function summarise(Collection $entries): array
    {
        $groups = [];
        $days = [];
        foreach ($entries as $e) {
            $k = $e['type'] === 'in' ? 'inflow' : 'outflow';
            $groups[$e['g']][$k] = ($groups[$e['g']][$k] ?? 0.0) + $e['amount'];
            $days[$e['d']][$k] = ($days[$e['d']][$k] ?? 0.0) + $e['amount'];
        }
        ksort($groups);
        ksort($days);

        $shape = fn ($label, $key, $io) => [
            $key => $label,
            'inflow' => round($io['inflow'] ?? 0.0, 2),
            'outflow' => round($io['outflow'] ?? 0.0, 2),
            'net' => round(($io['inflow'] ?? 0.0) - ($io['outflow'] ?? 0.0), 2),
        ];

        $in = array_sum(array_map(fn ($g) => $g['inflow'] ?? 0.0, $groups));
        $outTotal = array_sum(array_map(fn ($g) => $g['outflow'] ?? 0.0, $groups));

        return [
            'rows' => array_map(fn ($n, $io) => $shape($n, 'group', $io), array_keys($groups), $groups),
            'timeseries' => array_map(fn ($d, $io) => $shape($d, 'd', $io), array_keys($days), $days),
            'total_inflow' => round($in, 2),
            'total_outflow' => round($outTotal, 2),
            'net_cash_flow' => round($in - $outTotal, 2),
        ];
    }

    private static function joinGroup($q, string $table, bool $byMethod): void
    {
        if ($byMethod) {
            $q->leftJoin('payment_methods as gm', "$table.payment_method_id", '=', 'gm.id')->selectRaw('gm.name as g');
        } else {
            $q->leftJoin('accounts as ga', "$table.account_id", '=', 'ga.id')->selectRaw('ga.account_name as g');
        }
    }

    private static function scopePayment($q, string $table, array $o): void
    {
        if ($o['account_id']) {
            $q->where("$table.account_id", $o['account_id']);
        }
        if ($o['method_id']) {
            $q->where("$table.payment_method_id", $o['method_id']);
        }
        if (! $o['view_records']) {
            $q->where("$table.user_id", $o['user_id']);
        }
    }

    private static function fold($q, string $dateCol, string $amountCol)
    {
        return $q->selectRaw("$dateCol as d, SUM($amountCol) as t")->groupBy('d', 'g')->get();
    }
}
