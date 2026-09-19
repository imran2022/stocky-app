<?php

namespace App\Http\Controllers;

use App\Exports\ClientStatementExport;
use App\Models\Client;
use App\Models\Setting;
use App\Services\ClientStatementService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Admin-side Customer Statement (Build M4) — the "Customer_Ledger"-style page
 * that already existed shows KPI tiles + tabbed lists; this is a different,
 * additional view the user asked for: the same unified running-balance
 * ledger (Date / Type / Ref / Description / Debit / Credit / Balance) the
 * customer portal already shows the client, made available to admins too,
 * with a Modern-invoice-styled PDF and an Excel download.
 *
 * Both this controller and the portal's PortalStatementController build the
 * ledger through the same App\Services\ClientStatementService, so the two
 * never drift out of sync with each other.
 */
class ClientStatementController extends Controller
{
    /**
     * GET /clients/{id}/statement
     */
    public function index(Request $request, $id, ClientStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $data = $statementService->build(
            (int) $id,
            $request->input('from_date'),
            $request->input('to_date')
        );

        return response()->json($data);
    }

    /**
     * GET /clients/{id}/statement/pdf
     */
    public function pdf(Request $request, $id, ClientStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $client = Client::whereNull('deleted_at')->findOrFail($id);
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $data = $statementService->build((int) $id, $fromDate, $toDate);

        $settings = Setting::whereNull('deleted_at')->first();
        $helpers = new helpers;

        $html = view('pdf.customer_statement_modern', array_merge($data, [
            'setting' => $settings,
            'symbol' => $helpers->Get_Currency(),
            'priceFormat' => $settings['price_format'] ?? null,
            'client' => array_merge($data['client'], [
                'phone' => $client->phone,
                'adresse' => $client->adresse,
                'code' => $client->code,
            ]),
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]))->render();

        // Arabic/RTL glyph reshaping, same approach as the existing Customer
        // Ledger and Sale PDF exports (dompdf has no built-in shaping engine).
        $arabic = new Arabic;
        $p = $arabic->arIdentify($html);
        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $html = substr_replace($html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = \PDF::loadHTML($html, 'UTF-8')->setPaper('a4', 'portrait');

        return $pdf->download('statement_'.($client->code ?: $client->id).'.pdf');
    }

    /**
     * GET /clients/{id}/statement/excel
     */
    public function excel(Request $request, $id, ClientStatementService $statementService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Client::class);

        $client = Client::whereNull('deleted_at')->findOrFail($id);
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $data = $statementService->build((int) $id, $fromDate, $toDate);

        $period = $fromDate || $toDate
            ? trim(($fromDate ?: '…').' to '.($toDate ?: '…'))
            : 'All time';

        $export = new ClientStatementExport($data['entries'], [
            'client_name' => $client->name,
            'period' => $period,
            'opening_balance' => number_format((float) $data['opening_balance'], 2),
            'closing_balance' => number_format((float) $data['closing_balance'], 2),
        ]);

        return Excel::download($export, 'statement_'.($client->code ?: $client->id).'.xlsx');
    }
}
