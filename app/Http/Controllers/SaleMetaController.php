<?php

namespace App\Http\Controllers;

use App\Models\SaleCourier;
use App\Models\SaleZone;
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
 * The "Tracking Ref / Zone / Courier" values themselves are saved as plain
 * columns on `sales` (see SalesController@store / @update) and are included
 * automatically in the existing PDF/Excel export of the sales list, since
 * that export just serializes whatever columns the Sales list table shows.
 */
class SaleMetaController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'zones' => SaleZone::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'couriers' => SaleCourier::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeZone(Request $request)
    {
        $request->validate(['name' => 'required|string|max:191']);

        $zone = SaleZone::withTrashed()->firstOrNew(['name' => trim($request->name)]);
        if ($zone->trashed()) {
            $zone->restore();
        }
        $zone->name = trim($request->name);
        $zone->save();

        return response()->json(['zone' => $zone->only(['id', 'name'])]);
    }

    public function storeCourier(Request $request)
    {
        $request->validate(['name' => 'required|string|max:191']);

        $courier = SaleCourier::withTrashed()->firstOrNew(['name' => trim($request->name)]);
        if ($courier->trashed()) {
            $courier->restore();
        }
        $courier->name = trim($request->name);
        $courier->save();

        return response()->json(['courier' => $courier->only(['id', 'name'])]);
    }
}
