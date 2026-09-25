<?php

namespace App\Http\Controllers;

use App\Models\BdDivision;
use App\Models\Sale;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Models\Shipment;
use App\Services\SaleLookupService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Small lookup endpoints for the Sale "Zone" and "Courier" dropdowns
 * (custom addition, not part of the original app).
 *
 * - GET  sale_meta            → {zones: [...], couriers: [...]} used to
 *   populate the two dropdowns on the Create/Edit Sale form.
 * - POST sale_zones {name}    → creates (or restores/returns existing) a
 *   zone, used by the "+ add new" affordance in the Zone dropdown.
 * - POST sale_couriers {name} → same for Courier.
 *
 * Build E2 keeps the original on-the-fly creation convenience, but only users
 * who already have a legitimate Sales/POS/Shipment workflow permission may
 * create lookup values. Names are normalized and resolved case-insensitively
 * before insert so common duplicates such as "Pathao" / " pathao " are
 * returned as the same lookup instead of dirtying the metadata tables.
 */
class SaleMetaController extends Controller
{
    protected $lookups;

    public function __construct(SaleLookupService $lookups)
    {
        $this->lookups = $lookups;
    }

    /**
     * Read access is intentionally broad enough for every existing workflow
     * that consumes Sale metadata, while excluding unrelated authenticated
     * users from using this endpoint as a generic directory.
     */
    private function authorizeReadAccess(Request $request): void
    {
        $user = $request->user('api');
        $allowed = $user && (
            $user->can('view', Sale::class)
            || $user->can('create', Sale::class)
            || $user->can('update', Sale::class)
            || $user->can('Sales_pos', Sale::class)
            || $user->can('view', Shipment::class)
        );

        abort_unless($allowed, 403);
    }

    /**
     * Option B permission model: staff may still create a Zone/Courier from
     * the existing CreatableSelect when their job already allows Sale create/
     * edit, POS operation, or Shipment handling. No separate admin-only
     * metadata permission is introduced in this stabilization patch.
     */
    private function authorizeCreateAccess(Request $request): void
    {
        $user = $request->user('api');
        $allowed = $user && (
            $user->can('create', Sale::class)
            || $user->can('update', Sale::class)
            || $user->can('Sales_pos', Sale::class)
            || $user->can('create', Shipment::class)
            || $user->can('update', Shipment::class)
        );

        abort_unless($allowed, 403);
    }

    private function authorizeUpdateAccess(Request $request): void
    {
        $user = $request->user('api');
        abort_unless($user && $user->can('update', Sale::class), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeReadAccess($request);

        return response()->json([
            'zones' => SaleZone::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'couriers' => SaleCourier::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The Divisions (Bangladesh's 8, plus any custom ones added), for the Zone/Area form's Division dropdown. */
    public function divisions(Request $request)
    {
        $this->authorizeReadAccess($request);

        return response()->json(['divisions' => $this->lookups->divisions()]);
    }

    /**
     * Live suggestion while typing a Zone/Area name (matches known districts/aliases) — the frontend prefills the
     * Division dropdown with this, but the user can always change or clear it before saving.
     */
    public function suggestDivision(Request $request)
    {
        $this->authorizeReadAccess($request);
        $divisionId = $this->lookups->suggestDivisionId((string) $request->input('name', ''));

        return response()->json(['division_id' => $divisionId]);
    }

    /** Paginated list for the Divisions management page (Divisions are no longer hardcoded/fixed). */
    public function bdDivisions(Request $request)
    {
        $this->authorizeReadAccess($request);
        $rows = $this->lookups->divisionsPaginated([
            'search' => $request->input('search'),
            'sort_field' => $request->input('SortField'),
            'sort_type' => $request->input('SortType'),
            'limit' => $request->input('limit'),
        ]);

        return response()->json([
            'divisions' => $rows->items(),
            'totalRows' => $rows->total(),
        ]);
    }

    public function storeDivision(Request $request)
    {
        $this->authorizeUpdateAccess($request);
        $division = $this->lookups->createDivision((string) $request->input('name', ''));

        return response()->json(['division' => $division->only(['id', 'name'])]);
    }

    public function updateDivisionRow(Request $request, BdDivision $division)
    {
        $this->authorizeUpdateAccess($request);
        $division = $this->lookups->updateDivision($division, (string) $request->input('name', ''));

        return response()->json(['division' => $division->only(['id', 'name'])]);
    }

    /**
     * Audit-style guard: a Division still linked to a Zone/Area can never be deleted -- 422, never a silent no-op.
     */
    public function destroyDivision(Request $request, BdDivision $division)
    {
        $this->authorizeUpdateAccess($request);

        try {
            $this->lookups->destroyDivision($division);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['division'][0] ?? 'This Division cannot be deleted.',
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    public function storeZone(Request $request)
    {
        $this->authorizeCreateAccess($request);
        $zone = $this->lookups->createZone(
            (string) $request->input('name', ''),
            $request->has('division_id'),
            $request->filled('division_id') ? (int) $request->input('division_id') : null
        );

        return response()->json(['zone' => $zone->load('division')->only(['id', 'name', 'division_id', 'division'])]);
    }

    public function storeCourier(Request $request)
    {
        $this->authorizeCreateAccess($request);
        $courier = $this->lookups->createCourier((string) $request->input('name', ''));

        return response()->json(['courier' => $courier->only(['id', 'name'])]);
    }

    public function zones(Request $request)
    {
        $this->authorizeReadAccess($request);
        $rows = $this->lookups->zones([
            'search' => $request->input('search'),
            'sort_field' => $request->input('SortField'),
            'sort_type' => $request->input('SortType'),
            'limit' => $request->input('limit'),
        ]);

        return response()->json([
            'zones' => $rows->items(),
            'totalRows' => $rows->total(),
        ]);
    }

    public function couriers(Request $request)
    {
        $this->authorizeReadAccess($request);
        $rows = $this->lookups->couriers([
            'search' => $request->input('search'),
            'sort_field' => $request->input('SortField'),
            'sort_type' => $request->input('SortType'),
            'limit' => $request->input('limit'),
        ]);

        return response()->json([
            'couriers' => $rows->items(),
            'totalRows' => $rows->total(),
        ]);
    }

    public function updateZone(Request $request, SaleZone $zone)
    {
        $this->authorizeUpdateAccess($request);
        $zone = $this->lookups->updateZone(
            $zone,
            (string) $request->input('name', ''),
            $request->has('division_id'),
            $request->filled('division_id') ? (int) $request->input('division_id') : null
        );

        return response()->json(['zone' => $zone->load('division')->only(['id', 'name', 'division_id', 'division'])]);
    }

    /**
     * Audit-style guard: a Zone/Area still linked to a Sale can never be deleted (matches every other module's
     * "no deleting a document/lookup that's still in use" rule) — 422, never a silent no-op or a hidden FK error.
     */
    public function destroyZone(Request $request, SaleZone $zone)
    {
        $this->authorizeUpdateAccess($request);

        try {
            $this->lookups->destroyZone($zone);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['zone'][0] ?? 'This Zone/Area cannot be deleted.',
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    public function updateCourier(Request $request, SaleCourier $courier)
    {
        $this->authorizeUpdateAccess($request);
        $courier = $this->lookups->updateCourier($courier, (string) $request->input('name', ''));

        return response()->json(['courier' => $courier->only(['id', 'name'])]);
    }
}
