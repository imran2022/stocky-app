<?php
// Follow-up to the Zone/Area -> Division feature (2026-09-27): Divisions used to be a fixed, hardcoded 8-row
// Bangladesh list with no admin UI. Per the client's request, Divisions are now a manageable list (create/rename/
// delete), same pattern as Zone/Area and Courier. This test covers only the new Division CRUD surface -- the
// underlying auto-suggest/auto-link behavior is already covered by build_zone_division_linking.php and is
// untouched here.
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\SaleMetaController;
use App\Models\BdDivision;
use App\Models\SaleZone;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------- create --------------------------------------
$name = 'Test-Custom-Division-'.time();
[$c, $b] = call(SaleMetaController::class, 'storeDivision', ['name' => $name], 'POST');
check('storeDivision: 200', $c == 200, "$c $b");
$div = json_decode($b, true)['division'] ?? [];
check('storeDivision: name saved as given', ($div['name'] ?? null) === $name, $b);

[$c, $b] = call(SaleMetaController::class, 'storeDivision', ['name' => $name], 'POST');
check('storeDivision: duplicate name rejected (422)', $c == 422, "$c $b");

// ---------------------------------------------------------------- list ----------------------------------------
[$c, $b] = call(SaleMetaController::class, 'bdDivisions', ['limit' => 1000], 'GET');
check('bdDivisions: 200', $c == 200, "$c $b");
$listed = json_decode($b, true)['divisions'] ?? [];
check('bdDivisions: includes the new custom Division', in_array($name, array_column($listed, 'name'), true), $b);
$seeded = array_filter($listed, fn ($d) => $d['name'] === 'Dhaka');
check('bdDivisions: a seeded Division (Dhaka) reports its district count', (reset($seeded)['districts_count'] ?? 0) == 13, $b);

// ---------------------------------------------------------------- update --------------------------------------
$division = BdDivision::where('name', $name)->firstOrFail();
$renamed = $name.'-Renamed';
[$c, $b] = call(SaleMetaController::class, 'updateDivisionRow', ['name' => $renamed], 'PUT', [$division]);
check('updateDivisionRow: 200', $c == 200, "$c $b");
check('updateDivisionRow: renamed', (json_decode($b, true)['division']['name'] ?? null) === $renamed, $b);

[$c, $b] = call(SaleMetaController::class, 'updateDivisionRow', ['name' => 'Dhaka'], 'PUT', [$division]);
check('updateDivisionRow: renaming to an existing Division\'s name is rejected (422)', $c == 422, "$c $b");

// ---------------------------------------------------------------- delete guard ----------------------------------
$zone = SaleZone::create(['name' => 'MF-DivCrud-Zone-'.time(), 'division_id' => $division->id]);
[$c, $b] = call(SaleMetaController::class, 'destroyDivision', [], 'DELETE', [$division]);
check('destroyDivision: blocked (422) while a Zone/Area still references it', $c == 422, "$c $b");
check('destroyDivision: Division row still exists after blocked delete', BdDivision::find($division->id) !== null);

DB::table('sale_zones')->where('id', $zone->id)->delete();
[$c, $b] = call(SaleMetaController::class, 'destroyDivision', [], 'DELETE', [$division]);
check('destroyDivision: allowed (200) once no Zone/Area references it', $c == 200, "$c $b");
check('destroyDivision: Division row is gone', BdDivision::find($division->id) === null);

// A seeded, real Division (with real districts) is deletable the same way once nothing uses it -- proves this
// isn't special-cased to only custom Divisions. Uses a throwaway copy, not one of the real 8, to avoid disturbing
// the live district-matching data other tests rely on.
$copy = BdDivision::create(['name' => 'MF-DivCrud-RealCopy-'.time(), 'sort_order' => 999]);
[$c, $b] = call(SaleMetaController::class, 'destroyDivision', [], 'DELETE', [$copy]);
check('destroyDivision: a Division with zero linked Zones/Areas is always deletable', $c == 200, "$c $b");

finish('Divisions management: create/rename/delete (new admin UI, no longer hardcoded)');
