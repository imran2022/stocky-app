<?php

namespace App\Services;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * Builds a supplier running-balance statement using the same seven-column
 * shape as ClientStatementService: Date / Type / Ref / Description / Debit /
 * Credit / Balance. Debits increase the amount payable to the supplier;
 * credits reduce it. A purchase-return refund is therefore a debit because
 * it settles an amount the supplier owed back to the business.
 */
class ProviderStatementService
{
    public function build(int $providerId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $provider = Provider::whereNull('deleted_at')->findOrFail($providerId);

        $purchases = DB::table('purchases')
            ->whereNull('deleted_at')
            ->where('provider_id', $providerId)
            ->where('statut', 'received')
            ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->select('date', 'Ref', 'GrandTotal')
            ->orderBy('date')->orderBy('id')->get();

        $payments = DB::table('payment_purchases')
            ->whereNull('payment_purchases.deleted_at')
            ->join('purchases', 'payment_purchases.purchase_id', '=', 'purchases.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.provider_id', $providerId)
            ->where('purchases.statut', 'received')
            ->when($fromDate, fn ($q) => $q->where('payment_purchases.date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('payment_purchases.date', '<=', $toDate))
            ->select('payment_purchases.date', 'payment_purchases.Ref', 'payment_purchases.montant', 'purchases.Ref as purchase_ref')
            ->orderBy('payment_purchases.date')->orderBy('payment_purchases.id')->get();

        // Keep the full opening-payment history, matching ClientStatementService.
        // The provider's current opening_balance has already been reduced by
        // these rows, so adding them back reconstructs the original balance.
        $openingPayments = DB::table('provider_opening_balance_payments')
            ->whereNull('deleted_at')
            ->where('provider_id', $providerId)
            ->select('date', 'Ref', 'montant')
            ->orderBy('date')->orderBy('id')->get();

        $returns = DB::table('purchase_returns')
            ->whereNull('deleted_at')
            ->where('provider_id', $providerId)
            ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->select('id', 'date', 'Ref', 'GrandTotal')
            ->orderBy('date')->orderBy('id')->get();

        $returnRefunds = DB::table('payment_purchase_returns')
            ->whereNull('payment_purchase_returns.deleted_at')
            ->join('purchase_returns', 'payment_purchase_returns.purchase_return_id', '=', 'purchase_returns.id')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.provider_id', $providerId)
            ->when($fromDate, fn ($q) => $q->where('payment_purchase_returns.date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('payment_purchase_returns.date', '<=', $toDate))
            ->select('payment_purchase_returns.date', 'payment_purchase_returns.Ref', 'payment_purchase_returns.montant', 'purchase_returns.Ref as return_ref')
            ->orderBy('payment_purchase_returns.date')->orderBy('payment_purchase_returns.id')->get();

        $currentOpeningBalance = (float) ($provider->opening_balance ?? 0);
        $totalOpeningBalancePaid = (float) $openingPayments->sum('montant');

        // Fold all activity before the selected period into the opening figure
        // so the filtered statement still closes to the supplier's real balance.
        $carryForward = 0.0;
        if ($fromDate) {
            $beforePurchases = (float) DB::table('purchases')
                ->whereNull('deleted_at')->where('provider_id', $providerId)->where('statut', 'received')
                ->where('date', '<', $fromDate)->sum('GrandTotal');
            $beforePayments = (float) DB::table('payment_purchases')
                ->whereNull('payment_purchases.deleted_at')
                ->join('purchases', 'payment_purchases.purchase_id', '=', 'purchases.id')
                ->whereNull('purchases.deleted_at')->where('purchases.provider_id', $providerId)
                ->where('purchases.statut', 'received')->where('payment_purchases.date', '<', $fromDate)
                ->sum('payment_purchases.montant');
            $beforeReturns = (float) DB::table('purchase_returns')
                ->whereNull('deleted_at')->where('provider_id', $providerId)
                ->where('date', '<', $fromDate)->sum('GrandTotal');
            $beforeReturnRefunds = (float) DB::table('payment_purchase_returns')
                ->whereNull('payment_purchase_returns.deleted_at')
                ->join('purchase_returns', 'payment_purchase_returns.purchase_return_id', '=', 'purchase_returns.id')
                ->whereNull('purchase_returns.deleted_at')->where('purchase_returns.provider_id', $providerId)
                ->where('payment_purchase_returns.date', '<', $fromDate)
                ->sum('payment_purchase_returns.montant');

            $carryForward = $beforePurchases - $beforePayments - $beforeReturns + $beforeReturnRefunds;
        }

        $originalOpeningBalance = $currentOpeningBalance + $totalOpeningBalancePaid + $carryForward;
        $entries = [];

        foreach ($purchases as $purchase) {
            $entries[] = [
                'date' => $purchase->date, 'type' => 'purchase', 'ref' => $purchase->Ref,
                'description' => 'Purchase', 'debit' => (float) $purchase->GrandTotal, 'credit' => 0,
            ];
        }
        foreach ($payments as $payment) {
            $entries[] = [
                'date' => $payment->date, 'type' => 'purchase_payment', 'ref' => $payment->Ref,
                'description' => 'Payment for '.($payment->purchase_ref ?: 'purchase'),
                'debit' => 0, 'credit' => (float) $payment->montant,
            ];
        }
        foreach ($openingPayments as $payment) {
            $entries[] = [
                'date' => $payment->date, 'type' => 'opening_payment', 'ref' => $payment->Ref,
                'description' => 'Opening balance payment', 'debit' => 0, 'credit' => (float) $payment->montant,
            ];
        }
        foreach ($returns as $return) {
            $entries[] = [
                'date' => $return->date, 'type' => 'purchase_return', 'ref' => $return->Ref,
                'description' => 'Purchase return', 'debit' => 0, 'credit' => (float) $return->GrandTotal,
            ];
        }
        foreach ($returnRefunds as $refund) {
            $entries[] = [
                'date' => $refund->date, 'type' => 'return_refund', 'ref' => $refund->Ref,
                'description' => 'Refund for '.($refund->return_ref ?: 'purchase return'),
                'debit' => (float) $refund->montant, 'credit' => 0,
            ];
        }

        usort($entries, function ($a, $b) {
            $dateOrder = strcmp($a['date'], $b['date']);
            if ($dateOrder !== 0) return $dateOrder;
            $rank = ['purchase' => 0, 'return_refund' => 1, 'opening_payment' => 2, 'purchase_payment' => 3, 'purchase_return' => 4];
            return ($rank[$a['type']] ?? 9) - ($rank[$b['type']] ?? 9);
        });

        if ($originalOpeningBalance != 0) {
            array_unshift($entries, [
                'date' => $fromDate ?: '', 'type' => 'opening', 'ref' => '',
                'description' => 'Opening balance',
                'debit' => $originalOpeningBalance > 0 ? round($originalOpeningBalance, 2) : 0,
                'credit' => $originalOpeningBalance < 0 ? round(abs($originalOpeningBalance), 2) : 0,
            ]);
        }

        $balance = 0.0;
        foreach ($entries as &$entry) {
            $balance += ($entry['debit'] ?? 0) - ($entry['credit'] ?? 0);
            $entry['balance'] = round($balance, 2);
        }
        unset($entry);

        return [
            'provider' => [
                'id' => $provider->id, 'name' => $provider->name, 'email' => $provider->email,
                'phone' => $provider->phone, 'adresse' => $provider->adresse, 'city' => $provider->city,
                'country' => $provider->country, 'code' => $provider->code,
                'opening_balance' => $currentOpeningBalance,
            ],
            'opening_balance' => $originalOpeningBalance,
            'current_opening_balance' => $currentOpeningBalance,
            'entries' => $entries,
            'closing_balance' => $balance,
        ];
    }
}
