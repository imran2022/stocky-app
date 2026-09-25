<?php
// New feature (2026-09-27, per Imran's request): Zone/Area -> Bangladesh Division auto-linking, so future
// Division-wise performance/reporting has real data to group by.
//
// Covers:
//  - GET  sale_divisions          -> the 8 seeded Divisions.
//  - GET  sale_zones/suggest_division -> exact district/alias match => division id; unrecognized name => null.
//  - POST sale_zones (quick "+ add new" path, NO division_id key sent) -> server auto-resolves from the name.
//  - POST sale_zones (full form path, division_id key sent explicitly, including explicit null) -> that choice
//    always wins over the name, whether or not the name itself matches a district.
//  - PUT  sale_zones/{id} -> same auto-resolve-vs-explicit-choice rule on update.
//  - DELETE sale_zones/{id} -> blocked (422) while a Sale still references the zone, allowed once it doesn't.
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\SaleMetaController;
use App\Models\BdDivision;
use App\Models\SaleZone;
use Illuminate\Support\Facades\DB;

function findZoneMF($id) { return SaleZone::findOrFail($id); }
function divIdByName($name) { return (int) DB::table('bd_divisions')->where('name', $name)->value('id'); }

// Idempotency: the updateZone rename test below needs the exact, unsuffixed district name "Rangpur" (an
// unsuffixed suffix would break BdDistrictMatcher's deliberate exact-match rule), so purge any leftover row from a
// previous run of this same test before it runs, rather than colliding with the unique-name constraint.
DB::table('sale_zones')->where('name', 'Rangpur')->delete(); // real (hard) delete of the row, bypassing SoftDeletes

// ---------------------------------------------------------------- GET sale_divisions -------------------------------
[$c, $b] = call(SaleMetaController::class, 'divisions', [], 'GET');
check('divisions() returns 200', $c == 200, "$c $b");
$divisions = json_decode($b, true)['divisions'] ?? [];
check('divisions() returns all 8 Bangladesh divisions', count($divisions) === 8, count($divisions));
$divisionNames = array_column($divisions, 'name');
foreach (['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'] as $n) {
    check("divisions() includes $n", in_array($n, $divisionNames, true));
}

// ---------------------------------------------------------------- GET suggest_division ------------------------------
[$c, $b] = call(SaleMetaController::class, 'suggestDivision', ['name' => 'Gazipur'], 'GET');
$sug = json_decode($b, true);
check('suggest_division: exact district name "Gazipur" -> Dhaka', ($sug['division_id'] ?? null) == divIdByName('Dhaka'), $b);

[$c, $b] = call(SaleMetaController::class, 'suggestDivision', ['name' => 'Bogra'], 'GET');
$sug = json_decode($b, true);
check('suggest_division: alias "Bogra" -> Rajshahi (canonical Bogura)', ($sug['division_id'] ?? null) == divIdByName('Rajshahi'), $b);

[$c, $b] = call(SaleMetaController::class, 'suggestDivision', ['name' => "  cox's   bazar "], 'GET');
$sug = json_decode($b, true);
check('suggest_division: punctuation/spacing-insensitive alias -> Chattogram', ($sug['division_id'] ?? null) == divIdByName('Chattogram'), $b);

[$c, $b] = call(SaleMetaController::class, 'suggestDivision', ['name' => 'Some Custom Delivery Zone XYZ'], 'GET');
$sug = json_decode($b, true);
check('suggest_division: unrecognized zone name -> null (never a wrong guess)', array_key_exists('division_id', $sug) && $sug['division_id'] === null, $b);

// ---------------------------------------------------------------- POST sale_zones: quick add (no division_id key) --
// Simulates the inline CreatableSelect on the Sale form, which only ever POSTs {name}. Uses an exact, unsuffixed
// district name -- BdDistrictMatcher is deliberately EXACT-match only, so a suffix like "-12345" would (correctly)
// break the match; createOrRestore()'s restore-on-duplicate makes reusing a plain district name across test runs safe.
[$c, $b] = call(SaleMetaController::class, 'storeZone', ['name' => 'Sherpur'], 'POST');
check('storeZone (quick add, recognizable name): 200', $c == 200, "$c $b");
$z = json_decode($b, true)['zone'] ?? [];
check('storeZone (quick add): auto-resolved to Mymensingh', ($z['division_id'] ?? null) == divIdByName('Mymensingh'), $b);

[$c, $b] = call(SaleMetaController::class, 'storeZone', ['name' => 'North Warehouse Route 9-'.time()], 'POST');
check('storeZone (quick add, unrecognizable name): 200', $c == 200, "$c $b");
$z2 = json_decode($b, true)['zone'] ?? [];
check('storeZone (quick add): no match -> division_id null, never guessed', array_key_exists('division_id', $z2) && $z2['division_id'] === null, $b);

// ---------------------------------------------------------------- POST sale_zones: full form, explicit choice ------
$khulnaId = divIdByName('Khulna');
[$c, $b] = call(SaleMetaController::class, 'storeZone', ['name' => 'Dinajpur-Override-'.time(), 'division_id' => $khulnaId], 'POST');
check('storeZone (explicit division_id): 200', $c == 200, "$c $b");
$z3 = json_decode($b, true)['zone'] ?? [];
check(
    'storeZone (explicit division_id): explicit choice (Khulna) wins even though name "Dinajpur" matches Rangpur',
    ($z3['division_id'] ?? null) == $khulnaId,
    $b
);

[$c, $b] = call(SaleMetaController::class, 'storeZone', ['name' => 'Dhaka-ExplicitClear-'.time(), 'division_id' => ''], 'POST');
check('storeZone (explicit empty division_id = deliberate clear): 200', $c == 200, "$c $b");
$z4 = json_decode($b, true)['zone'] ?? [];
check(
    'storeZone (explicit clear): division_id stays null even though name "Dhaka" would auto-match',
    array_key_exists('division_id', $z4) && $z4['division_id'] === null,
    $b
);

// ---------------------------------------------------------------- PUT sale_zones/{id} --------------------------------
$zoneToEdit = findZoneMF($z2['id']); // currently unmatched name, division_id null
[$c, $b] = call(SaleMetaController::class, 'updateZone', ['name' => 'Rangpur'], 'PUT', [$zoneToEdit]);
check('updateZone (no division_id key = auto-resolve path): 200', $c == 200, "$c $b");
$zu = json_decode($b, true)['zone'] ?? [];
check('updateZone (auto-resolve): renamed to "Rangpur" auto-links to Rangpur division', ($zu['division_id'] ?? null) == divIdByName('Rangpur'), $b);

$zoneToEdit2 = findZoneMF($z3['id']); // currently explicitly Khulna, name "Dinajpur-Override-..."
[$c, $b] = call(SaleMetaController::class, 'updateZone', ['name' => $zoneToEdit2->name, 'division_id' => ''], 'PUT', [$zoneToEdit2]);
check('updateZone (explicit clear on existing linked zone): 200', $c == 200, "$c $b");
$zu2 = json_decode($b, true)['zone'] ?? [];
check('updateZone (explicit clear): division_id explicitly cleared to null, name unchanged', array_key_exists('division_id', $zu2) && $zu2['division_id'] === null, $b);

// ---------------------------------------------------------------- DELETE sale_zones/{id} -----------------------------
$zoneNoSales = findZoneMF($z4['id']);
$zoneWithSale = SaleZone::create(['name' => 'MF-Zone-WithSale-'.time()]);
$anySaleId = DB::table('sales')->whereNull('deleted_at')->orderBy('id')->value('id');
check('a pre-existing, non-deleted Sale row is available to link for the delete-guard test', $anySaleId !== null, 'no sales rows in DB');
DB::table('sales')->where('id', $anySaleId)->update(['zone_id' => $zoneWithSale->id]);

[$c, $b] = call(SaleMetaController::class, 'destroyZone', [], 'DELETE', [$zoneWithSale]);
check('destroyZone: blocked (422) while a Sale still references the zone', $c == 422, "$c $b");
check('destroyZone: zone row still exists after blocked delete', SaleZone::find($zoneWithSale->id) !== null);

// Detach before deleting the "no sales" zone's own test to keep DB tidy for subsequent runs / other tests.
DB::table('sales')->where('id', $anySaleId)->where('zone_id', $zoneWithSale->id)->update(['zone_id' => null]);

[$c, $b] = call(SaleMetaController::class, 'destroyZone', [], 'DELETE', [$zoneNoSales]);
check('destroyZone: allowed (200) when zone has zero linked sales', $c == 200, "$c $b");
check('destroyZone: zone row is gone (soft-deleted) after successful delete', SaleZone::find($zoneNoSales->id) === null);

// Now that its blocking sale is detached, the zone should be deletable too.
[$c, $b] = call(SaleMetaController::class, 'destroyZone', [], 'DELETE', [$zoneWithSale]);
check('destroyZone: now allowed (200) once its sale was detached', $c == 200, "$c $b");

finish('Zone/Area -> Bangladesh Division auto-linking (new feature)');
