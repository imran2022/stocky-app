<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Audit fix (Batch 3, findings S4/P4): `paid_amount` and `payment_statut` on
 * sales, purchases and their returns were maintained by adding/subtracting the
 * delta of whichever payment was just touched. Any earlier drift (a capped
 * overpayment, an edit that changed the amount, a payment moved to another
 * document) therefore compounded: paying 60 then 80 on a 100 sale stored 100,
 * deleting the 80 stored 20 while the remaining payment rows summed to 60.
 *
 * The payment rows are the source of truth. After every payment create /
 * update / delete the callers now call sync*() which recomputes
 *   paid_amount    = min(GrandTotal, SUM(montant of live payment rows))
 *   payment_statut = paid | partial | unpaid
 * inside the same transaction. assertWithinDue() rejects a payment that would
 * push the rows past the document total (a genuine overpayment is recorded as
 * `change`, not as extra `montant`).
 */
class PaymentReconciler
{
    private const EPS = 0.005;

    public static function syncSale(int $id): void
    {
        self::sync('sales', $id, 'payment_sales', 'sale_id');
    }

    public static function syncPurchase(int $id): void
    {
        self::sync('purchases', $id, 'payment_purchases', 'purchase_id');
    }

    public static function syncSaleReturn(int $id): void
    {
        self::sync('sale_returns', $id, 'payment_sale_returns', 'sale_return_id');
    }

    public static function syncPurchaseReturn(int $id): void
    {
        self::sync('purchase_returns', $id, 'payment_purchase_returns', 'purchase_return_id');
    }

    /**
     * @param  float  $grandTotal  document total
     * @param  float  $otherPaid   sum of the document's OTHER live payment rows
     * @param  float  $adding      amount being created / the new amount of the row being edited
     *
     * @throws ValidationException
     */
    public static function assertWithinDue(float $grandTotal, float $otherPaid, float $adding): void
    {
        if ($otherPaid + $adding > $grandTotal + 0.01) {
            throw ValidationException::withMessages(['montant' => [sprintf(
                'Payment %.2f exceeds the remaining amount due %.2f.', $adding, max(0.0, $grandTotal - $otherPaid)
            )]]);
        }
    }

    /**
     * An edit may not shrink a document below what has already been paid on it: the surplus would sit as a
     * negative due with no payment row to correct. The user removes / reduces the payment first, then edits.
     *
     * @param  string  $docTable  sales | purchases | sale_returns | purchase_returns
     *
     * @throws ValidationException
     */
    public static function assertTotalCoversPayments(string $docTable, int $id, float $newGrandTotal): void
    {
        [$paymentTable, $fk] = [
            'sales' => ['payment_sales', 'sale_id'],
            'purchases' => ['payment_purchases', 'purchase_id'],
            'sale_returns' => ['payment_sale_returns', 'sale_return_id'],
            'purchase_returns' => ['payment_purchase_returns', 'purchase_return_id'],
        ][$docTable];

        $paid = self::paidSum($paymentTable, $fk, $id);
        if ($paid > $newGrandTotal + 0.01) {
            throw ValidationException::withMessages(['GrandTotal' => [sprintf(
                'The new total %.2f is below the %.2f already paid on this document. Remove or reduce the payment first.', $newGrandTotal, $paid
            )]]);
        }
    }

    /** Sum of live payment rows for a document, optionally excluding one payment row. */
    public static function paidSum(string $paymentTable, string $fk, int $docId, ?int $exceptPaymentId = null): float
    {
        $q = DB::table($paymentTable)->where($fk, $docId)->whereNull('deleted_at');
        if ($exceptPaymentId) {
            $q->where('id', '!=', $exceptPaymentId);
        }

        return (float) $q->sum('montant');
    }

    private static function sync(string $docTable, int $id, string $paymentTable, string $fk): void
    {
        $gt = (float) DB::table($docTable)->where('id', $id)->value('GrandTotal');
        $paid = PaymentCapper::capPaid($gt, self::paidSum($paymentTable, $fk, $id));

        if ($gt - $paid <= self::EPS) {
            $status = 'paid';
        } elseif ($paid <= self::EPS) {
            $status = 'unpaid';
        } else {
            $status = 'partial';
        }

        DB::table($docTable)->where('id', $id)->update(['paid_amount' => $paid, 'payment_statut' => $status]);
    }
}
