<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleCourier;
use App\Models\SaleZone;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
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

    /**
     * Normalize only harmless presentation whitespace. We deliberately do not
     * force lowercase storage because the first canonical spelling entered by
     * staff should remain visible in dropdowns/reports.
     */
    private function normalizedName(Request $request): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $request->input('name', '')));
        $request->merge(['name' => $name]);
        $request->validate(['name' => 'required|string|max:191']);

        return $name;
    }

    /**
     * Resolve an active or soft-deleted lookup case-insensitively. TRIM keeps
     * compatibility with any historical rows that may contain outer spaces.
     */
    private function findLookup(string $modelClass, string $name)
    {
        return $modelClass::withTrashed()
            ->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])
            ->first();
    }

    /**
     * Return an existing lookup, restore a soft-deleted one, or insert a new
     * canonical row. The retry after QueryException handles the normal unique-
     * key race where two users submit the same new name concurrently.
     */
    private function createOrRestoreLookup(string $modelClass, string $name)
    {
        $lookup = $this->findLookup($modelClass, $name);
        if ($lookup) {
            if ($lookup->trashed()) {
                $lookup->restore();
            }

            return $lookup;
        }

        try {
            return $modelClass::create(['name' => $name]);
        } catch (QueryException $e) {
            // Only swallow a unique/integrity race. Other database failures
            // must surface normally instead of being hidden by lookup retry.
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            // Existing DB unique(name) remains the final concurrency guard.
            // If another request won the race, return that row gracefully;
            // otherwise rethrow the original database error.
            $lookup = $this->findLookup($modelClass, $name);
            if ($lookup) {
                if ($lookup->trashed()) {
                    $lookup->restore();
                }

                return $lookup;
            }

            throw $e;
        }
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
        $zone = $this->createOrRestoreLookup(SaleZone::class, $this->normalizedName($request));

        return response()->json(['zone' => $zone->only(['id', 'name'])]);
    }

    public function storeCourier(Request $request)
    {
        $this->authorizeCreateAccess($request);
        $courier = $this->createOrRestoreLookup(SaleCourier::class, $this->normalizedName($request));

        return response()->json(['courier' => $courier->only(['id', 'name'])]);
    }
}
