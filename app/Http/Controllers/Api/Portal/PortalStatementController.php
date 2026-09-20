<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ClientStatementService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PortalStatementController extends Controller
{
    /**
     * GET /api/portal/statement - Account statement (ledger) for the authenticated client.
     *
     * The actual ledger-building logic (7 entry sources, sort, running balance)
     * lives in App\Services\ClientStatementService — the single source of truth
     * shared with the admin-side Customer Statement page/PDF/Excel export
     * (Build M4), so the two never drift apart the way the response shape here
     * used to be duplicated by hand.
     */
    public function index(Request $request, ClientStatementService $statementService)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $data = $statementService->build(
            $portalClient->client_id,
            $request->input('from_date'),
            $request->input('to_date')
        );

        return response()->json($data);
    }

    /**
     * GET /api/portal/statement/pdf - Client-scoped statement PDF.
     *
     * Uses the same ledger service and modern PDF view as the admin Customer
     * Statement export, but takes the client id exclusively from the portal
     * session so one client can never request another client's statement.
     */
    public function pdf(Request $request, ClientStatementService $statementService)
    {
        $portalClient = Auth::guard('portal')->user();
        $this->assertPortalActive($portalClient);

        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $data = $statementService->build(
            $portalClient->client_id,
            $fromDate,
            $toDate
        );

        $settings = Setting::whereNull('deleted_at')->first();
        $helpers = new helpers;
        $html = view('pdf.customer_statement_modern', array_merge($data, [
            'setting' => $settings,
            'symbol' => $helpers->Get_Currency(),
            'priceFormat' => $settings['price_format'] ?? null,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]))->render();

        $arabic = new Arabic;
        $parts = $arabic->arIdentify($html);
        for ($i = count($parts) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($html, $parts[$i - 1], $parts[$i] - $parts[$i - 1]));
            $html = substr_replace($html, $utf8ar, $parts[$i - 1], $parts[$i] - $parts[$i - 1]);
        }

        $pdf = \PDF::loadHTML($html, 'UTF-8')->setPaper('a4', 'portrait');
        $clientCode = $data['client']['code'] ?: $data['client']['id'];

        return $pdf->download('statement_'.$clientCode.'.pdf');
    }

    private function assertPortalActive($portalClient): void
    {
        if ((int) $portalClient->status !== 1) {
            abort(403, 'Portal access is disabled');
        }
    }
}
