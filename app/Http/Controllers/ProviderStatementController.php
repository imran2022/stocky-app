<?php

namespace App\Http\Controllers;

use App\Exports\ClientStatementExport;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Services\ProviderStatementService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ProviderStatementController extends Controller
{
    public function index(Request $request, $id, ProviderStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        return response()->json($statementService->build(
            (int) $id,
            $request->input('from_date'),
            $request->input('to_date')
        ));
    }

    public function pdf(Request $request, $id, ProviderStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $provider = Provider::whereNull('deleted_at')->findOrFail($id);
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $data = $statementService->build((int) $id, $fromDate, $toDate);
        $settings = Setting::whereNull('deleted_at')->first();
        $helpers = new helpers;

        $html = view('pdf.supplier_statement_modern', array_merge($data, [
            'setting' => $settings,
            'symbol' => $helpers->Get_Currency(),
            'priceFormat' => $settings['price_format'] ?? null,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]))->render();

        $html = $this->shapeArabic($html);
        $pdf = \PDF::loadHTML($html, 'UTF-8')->setPaper('a4', 'portrait');

        return $pdf->download('supplier_statement_'.($provider->code ?: $provider->id).'.pdf');
    }

    public function excel(Request $request, $id, ProviderStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $provider = Provider::whereNull('deleted_at')->findOrFail($id);
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $data = $statementService->build((int) $id, $fromDate, $toDate);
        $period = $fromDate || $toDate
            ? trim(($fromDate ?: '…').' to '.($toDate ?: '…'))
            : 'All time';

        $export = new ClientStatementExport($data['entries'], [
            'title' => 'Supplier Account Statement',
            'party_label' => 'Supplier',
            'client_name' => $provider->name,
            'period' => $period,
            'opening_balance' => number_format((float) $data['opening_balance'], 2),
            'closing_balance' => number_format((float) $data['closing_balance'], 2),
        ]);

        return Excel::download($export, 'supplier_statement_'.($provider->code ?: $provider->id).'.xlsx');
    }

    /** Full supplier ledger PDF, parallel to ClientController@export. */
    public function ledgerPdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $provider = Provider::select(
            'id', 'name', 'email', 'phone', 'code', 'adresse', 'country', 'city',
            'tax_number', 'opening_balance', 'credit_limit'
        )->whereNull('deleted_at')->findOrFail($id);

        $purchases = Purchase::with('warehouse:id,name')
            ->whereNull('deleted_at')->where('provider_id', $provider->id)
            ->orderByDesc('id')->get();

        $purchasePayments = DB::table('payment_purchases')
            ->join('purchases', 'payment_purchases.purchase_id', '=', 'purchases.id')
            ->leftJoin('payment_methods', 'payment_purchases.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_purchases.deleted_at')->whereNull('purchases.deleted_at')
            ->where('purchases.provider_id', $provider->id)
            ->select(
                'payment_purchases.date', 'payment_purchases.Ref',
                'purchases.Ref as purchase_ref', 'payment_methods.name as payment_method',
                'payment_purchases.montant', DB::raw("'purchase' as payment_type")
            )->get();

        $openingPayments = DB::table('provider_opening_balance_payments')
            ->leftJoin('payment_methods', 'provider_opening_balance_payments.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('provider_opening_balance_payments.deleted_at')
            ->where('provider_opening_balance_payments.provider_id', $provider->id)
            ->select(
                'provider_opening_balance_payments.date', 'provider_opening_balance_payments.Ref',
                DB::raw('NULL as purchase_ref'), 'payment_methods.name as payment_method',
                'provider_opening_balance_payments.montant', DB::raw("'opening_balance' as payment_type")
            )->get();

        $payments = $purchasePayments->merge($openingPayments)
            ->sortByDesc(fn ($payment) => $payment->date)->values();

        $returns = PurchaseReturn::with(['warehouse:id,name', 'purchase:id,Ref'])
            ->whereNull('deleted_at')->where('provider_id', $provider->id)
            ->orderByDesc('id')->get();

        $returnRefunds = DB::table('payment_purchase_returns')
            ->join('purchase_returns', 'payment_purchase_returns.purchase_return_id', '=', 'purchase_returns.id')
            ->leftJoin('payment_methods', 'payment_purchase_returns.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_purchase_returns.deleted_at')->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.provider_id', $provider->id)
            ->select(
                'payment_purchase_returns.date', 'payment_purchase_returns.Ref',
                'purchase_returns.Ref as return_ref', 'payment_methods.name as payment_method',
                'payment_purchase_returns.montant'
            )->orderByDesc('payment_purchase_returns.id')->get();

        // Supplier balance uses received purchases only, matching the supplier
        // list/details calculation. The detail table still includes every
        // purchase status, exactly like the customer ledger lists every sale.
        $receivedPurchases = $purchases->where('statut', 'received');
        $purchaseGrand = (float) $receivedPurchases->sum('GrandTotal');
        $purchasePaid = (float) $receivedPurchases->sum('paid_amount');
        $purchaseDue = $purchaseGrand - $purchasePaid;
        $returnGrand = (float) $returns->sum('GrandTotal');
        $returnPaid = (float) $returns->sum('paid_amount');
        $returnDue = $returnGrand - $returnPaid;

        $provider->purchasesGrand = $purchaseGrand;
        $provider->purchasesPaid = $purchasePaid;
        $provider->purchaseDue = $purchaseDue;
        $provider->returnsGrand = $returnGrand;
        $provider->returnsPaid = $returnPaid;
        $provider->returnDue = $returnDue;
        $provider->paymentsTotal = (float) $payments->sum('montant');
        $provider->returnRefundsTotal = (float) $returnRefunds->sum('montant');
        $provider->netBalance = (float) ($provider->opening_balance ?? 0) + $purchaseDue - $returnDue;

        $settings = Setting::whereNull('deleted_at')->first();
        $html = view('pdf.supplier_ledger', compact(
            'provider', 'purchases', 'payments', 'returns', 'returnRefunds', 'settings'
        ))->render();

        $html = $this->shapeArabic($html);
        $pdf = \PDF::loadHTML($html, 'UTF-8')->setPaper('a4', 'portrait');

        return $pdf->download("supplier_ledger_{$provider->id}.pdf");
    }

    private function shapeArabic(string $html): string
    {
        $arabic = new Arabic;
        $positions = $arabic->arIdentify($html);
        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $glyphs = $arabic->utf8Glyphs(substr(
                $html,
                $positions[$i - 1],
                $positions[$i] - $positions[$i - 1]
            ));
            $html = substr_replace(
                $html,
                $glyphs,
                $positions[$i - 1],
                $positions[$i] - $positions[$i - 1]
            );
        }

        return $html;
    }
}
