<?php

namespace App\Support;

use App\Models\CashRegister;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Audit fix (Batch 3, finding R2): closing a register compared the counted cash with
 * "opening + cash in - cash out + SUM(GrandTotal of POS sales)". That counts card / transfer
 * sales and unpaid or part-paid sales as if the money were in the drawer, so every close
 * reported a false shortage or overage.
 *
 * What is physically in the drawer is what the register's cashier actually took in CASH
 * (payment method "Cash") for sales of this warehouse during the shift, less any cash
 * refunded to customers in the same period, less the change handed back.
 */
class CashRegisterCash
{
    /** payment_methods.id of "Cash" in the stock seed data. */
    public const CASH_METHOD_ID = 2;

    /** Cash received on sales during the shift (applied amount only; change is already excluded from montant). */
    public static function received(CashRegister $register, Carbon $to): float
    {
        return (float) DB::table('payment_sales as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->whereNull('p.deleted_at')
            ->whereNull('s.deleted_at')
            ->where('p.user_id', $register->user_id)
            ->where('p.payment_method_id', self::CASH_METHOD_ID)
            ->where('s.warehouse_id', $register->warehouse_id)
            ->whereBetween('p.created_at', [$register->opened_at, $to])
            ->sum('p.montant');
    }

    /** Cash paid back to customers on sale returns during the shift. */
    public static function refunded(CashRegister $register, Carbon $to): float
    {
        return (float) DB::table('payment_sale_returns as p')
            ->join('sale_returns as r', 'r.id', '=', 'p.sale_return_id')
            ->whereNull('p.deleted_at')
            ->whereNull('r.deleted_at')
            ->where('p.user_id', $register->user_id)
            ->where('p.payment_method_id', self::CASH_METHOD_ID)
            ->where('r.warehouse_id', $register->warehouse_id)
            ->whereBetween('p.created_at', [$register->opened_at, $to])
            ->sum('p.montant');
    }

    public static function expected(CashRegister $register, Carbon $to): float
    {
        return round(
            (float) ($register->opening_balance ?? 0)
            + (float) ($register->cash_in ?? 0)
            - (float) ($register->cash_out ?? 0)
            + self::received($register, $to)
            - self::refunded($register, $to),
            2
        );
    }
}
