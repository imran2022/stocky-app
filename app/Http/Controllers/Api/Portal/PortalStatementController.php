<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\ClientStatementService;
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

    private function assertPortalActive($portalClient): void
    {
        if ((int) $portalClient->status !== 1) {
            abort(403, 'Portal access is disabled');
        }
    }
}
