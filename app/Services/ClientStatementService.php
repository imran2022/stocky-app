<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\DB;

/**
 * Builds the unified, running-balance "account statement" ledger for one
 * client — Date / Type / Ref / Description / Debit / Credit / Balance.
 *
 * Single source of truth for this ledger shape: the customer portal
 * (Api\Portal\PortalStatementController) and the new admin-side Customer
 * Statement page/PDF/Excel export (ClientStatementController) both call
 * build() rather than each re-deriving the same 7-source query set. Before
 * this class existed the portal alone had this logic; extracting it here
 * avoids adding a THIRD independent copy (the admin "Customer Ledger" page
 * already has its own, differently-shaped KPI+tabs query set, which this
 * does not touch or replace).
 */
class ClientStatementService
{
    /**
     * @return array{
     *     client: array{id:int, name:string, email:?string, opening_balance:float},
     *     opening_balance: float,
     *     current_opening_balance: float,
     *     entries: array<int, array{date:string,type:string,ref:string,description:string,debit:float,credit:float,balance:float}>,
     *     closing_balance: float,
     * }
     */
    public function build(int $clientId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $client = Client::whereNull('deleted_at')->findOrFail($clientId);

        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'completed')
            ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->select('date', 'Ref', 'GrandTotal')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $payments = DB::table('payment_sales')
            ->whereNull('payment_sales.deleted_at')
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->where('sales.client_id', $clientId)
            ->when($fromDate, fn ($q) => $q->where('payment_sales.date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('payment_sales.date', '<=', $toDate))
            ->select('payment_sales.date', 'payment_sales.Ref', 'payment_sales.montant', 'sales.Ref as sale_ref')
            ->orderBy('payment_sales.date')
            ->orderBy('payment_sales.id')
            ->get();

        // Opening balance payments: always include the full history so the running
        // balance reconciles to the customer's current opening_balance regardless
        // of date filter.
        $openingPayments = DB::table('client_opening_balance_payments')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->select('date', 'Ref', 'montant')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $saleReturns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'received')
            ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('date', '<=', $toDate))
            ->select('id', 'date', 'Ref', 'GrandTotal')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $refunds = DB::table('payment_sale_returns')
            ->whereNull('payment_sale_returns.deleted_at')
            ->join('sale_returns', 'payment_sale_returns.sale_return_id', '=', 'sale_returns.id')
            ->where('sale_returns.client_id', $clientId)
            ->when($fromDate, fn ($q) => $q->where('payment_sale_returns.date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('payment_sale_returns.date', '<=', $toDate))
            ->select('payment_sale_returns.date', 'payment_sale_returns.Ref', 'payment_sale_returns.montant', 'sale_returns.Ref as return_ref')
            ->orderBy('payment_sale_returns.date')
            ->orderBy('payment_sale_returns.id')
            ->get();

        // Service jobs the customer owes (accepted / in progress / delivered),
        // dated by creation. Payments on those jobs credit the account like sale
        // payments.
        $serviceJobs = DB::table('service_jobs')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->whereIn('status', ServiceJob::DUE_STATUSES)
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->select('id', 'Ref', 'created_at', 'total_amount', 'service_item')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $servicePayments = DB::table('service_job_payments')
            ->whereNull('service_job_payments.deleted_at')
            ->join('service_jobs', 'service_job_payments.service_job_id', '=', 'service_jobs.id')
            ->whereNull('service_jobs.deleted_at')
            ->where('service_jobs.client_id', $clientId)
            ->whereIn('service_jobs.status', ServiceJob::DUE_STATUSES)
            ->when($fromDate, fn ($q) => $q->where('service_job_payments.date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('service_job_payments.date', '<=', $toDate))
            ->select('service_job_payments.date', 'service_job_payments.Ref', 'service_job_payments.montant', 'service_jobs.Ref as job_ref')
            ->orderBy('service_job_payments.date')
            ->orderBy('service_job_payments.id')
            ->get();

        $currentOpeningBalance = (float) ($client->opening_balance ?? 0);
        $totalOpeningBalancePaid = (float) $openingPayments->sum('montant');
        $originalOpeningBalance = $currentOpeningBalance + $totalOpeningBalancePaid;

        $entries = [];
        foreach ($sales as $sale) {
            $entries[] = [
                'date' => $sale->date,
                'type' => 'invoice',
                'ref' => $sale->Ref,
                'description' => __('portal.stmt_invoice'),
                'debit' => (float) $sale->GrandTotal,
                'credit' => 0,
            ];
        }
        foreach ($payments as $pmt) {
            $entries[] = [
                'date' => $pmt->date,
                'type' => 'payment',
                'ref' => $pmt->Ref,
                'description' => __('portal.stmt_payment_for', ['ref' => $pmt->sale_ref ?? '']),
                'debit' => 0,
                'credit' => (float) $pmt->montant,
            ];
        }
        foreach ($openingPayments as $opPmt) {
            $entries[] = [
                'date' => $opPmt->date,
                'type' => 'opening_payment',
                'ref' => $opPmt->Ref,
                'description' => __('portal.stmt_opening_payment'),
                'debit' => 0,
                'credit' => (float) $opPmt->montant,
            ];
        }
        foreach ($saleReturns as $ret) {
            $entries[] = [
                'date' => $ret->date,
                'type' => 'return',
                'ref' => $ret->Ref,
                'description' => __('portal.stmt_sale_return'),
                'debit' => 0,
                'credit' => (float) $ret->GrandTotal,
            ];
        }
        foreach ($refunds as $ref) {
            $entries[] = [
                'date' => $ref->date,
                'type' => 'refund',
                'ref' => $ref->Ref,
                'description' => __('portal.stmt_refund_for', ['ref' => $ref->return_ref ?? '']),
                'debit' => (float) $ref->montant,
                'credit' => 0,
            ];
        }
        foreach ($serviceJobs as $job) {
            $entries[] = [
                'date' => substr((string) $job->created_at, 0, 10),
                'type' => 'service',
                'ref' => $job->Ref,
                'description' => trim(__('portal.stmt_service').($job->service_item ? ' - '.$job->service_item : '')),
                'debit' => (float) $job->total_amount,
                'credit' => 0,
            ];
        }
        foreach ($servicePayments as $pmt) {
            $entries[] = [
                'date' => $pmt->date,
                'type' => 'service_payment',
                'ref' => $pmt->Ref,
                'description' => __('portal.stmt_service_payment_for', ['ref' => $pmt->job_ref ?? '']),
                'debit' => 0,
                'credit' => (float) $pmt->montant,
            ];
        }

        usort($entries, function ($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            if ($d !== 0) {
                return $d;
            }
            // Same-date ordering: debits (invoices, refunds) before credits (returns, payments).
            $rank = [
                'invoice' => 0,
                'service' => 0,
                'refund' => 1,
                'opening_payment' => 2,
                'payment' => 3,
                'service_payment' => 3,
                'return' => 4,
            ];

            return ($rank[$a['type']] ?? 9) - ($rank[$b['type']] ?? 9);
        });

        if ($originalOpeningBalance != 0) {
            array_unshift($entries, [
                'date' => $fromDate ?: '',
                'type' => 'opening',
                'ref' => '',
                'description' => __('portal.stmt_opening'),
                'debit' => $originalOpeningBalance > 0 ? round($originalOpeningBalance, 2) : 0,
                'credit' => $originalOpeningBalance < 0 ? round(abs($originalOpeningBalance), 2) : 0,
            ]);
        }

        $balance = 0;
        foreach ($entries as &$e) {
            $balance += ($e['debit'] ?? 0) - ($e['credit'] ?? 0);
            $e['balance'] = round($balance, 2);
        }
        unset($e);

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                'adresse' => $client->adresse,
                'city' => $client->city,
                'country' => $client->country,
                'code' => $client->code,
                'opening_balance' => $currentOpeningBalance,
            ],
            'opening_balance' => $originalOpeningBalance,
            'current_opening_balance' => $currentOpeningBalance,
            'entries' => $entries,
            'closing_balance' => $balance,
        ];
    }
}
