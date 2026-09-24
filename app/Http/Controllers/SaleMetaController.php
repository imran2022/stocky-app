<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Models\Shipment;
use App\Services\SaleLookupService;
use Illuminate\Http\Request;

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

    public function storeZone(Request $request)
    {
        $this->authorizeCreateAccess($request);
        $zone = $this->lookups->createZone((string) $request->input('name', ''));

        return response()->json(['zone' => $zone->only(['id', 'name'])]);
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
        $zone = $this->lookups->updateZone($zone, (string) $request->input('name', ''));

        return response()->json(['zone' => $zone->only(['id', 'name'])]);
    }

    public function updateCourier(Request $request, SaleCourier $courier)
    {
        $this->authorizeUpdateAccess($request);
        $courier = $this->lookups->updateCourier($courier, (string) $request->input('name', ''));

        return response()->json(['courier' => $courier->only(['id', 'name'])]);
    }
}
