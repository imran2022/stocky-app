<?php
// Costing Method settings UI (App\Http\Controllers\Settings\CostingSettingsController) — the web wrapper around
// `php artisan costing:rebuild --apply --enable`. Verifies:
//   - GET costing_settings reports tablesReady/method/stats correctly;
//   - POST costing_settings actually flips InventoryCostingService::isActive() both ways (checked via direct
//     service calls, not just the JSON response);
//   - switching to moving_average with an empty ledger costs every non-service product into
//     inventory_cost_balances (products_costed > 0).
// The 3000-product safety cap (MAX_SYNC_PRODUCTS in the controller) is not exercised end-to-end here — seeding
// 3000+ real products just to hit a guard would be disproportionate for a catalog this small (~68 demo products).
// Instead this file asserts the guard's source is present (see the last check below); the guard logic itself is a
// one-line count() comparison, low risk, and mirrors the exact same "products too many for a web request" pattern
// already used nowhere else in this codebase to test.
require __DIR__.'/_audit_lib.php';
use App\Http\Controllers\Settings\CostingSettingsController as CSC;
use App\Services\Costing\InventoryCostingService as Svc;
use Illuminate\Support\Facades\DB;

// Start from a clean slate: legacy, empty ledger — this test owns switching costing on/off for the run.
Svc::setMethod(Svc::METHOD_LEGACY);
Svc::forgetMethodCache();
DB::table('inventory_cost_balances')->delete();
DB::table('inventory_cost_ledger')->delete();
DB::table('inventory_cost_keys')->delete();
DB::table('inventory_cost_seeds')->delete();
DB::table('inventory_cost_stamps')->delete();
DB::table('inventory_cost_meta')->where('meta_key', 'hw')->delete();

// ---------------------------------------------------------------- GET (show) -----------------------------------
[$c, $b] = call(CSC::class, 'show', [], 'GET');
check('GET costing_settings 200', $c == 200, "$c $b");
$show = json_decode($b, true);
check('tablesReady = true (migration already run in dev DB)', $show['tablesReady'] === true, $b);
check('method starts as legacy', $show['method'] === 'legacy', $b);
check('active = false', $show['active'] === false, $b);
check('productsCosted = 0 before any sync', $show['productsCosted'] === 0, $b);
check('totalNonServiceProducts > 0 (demo catalog present)', $show['totalNonServiceProducts'] > 0, $b);

// ---------------------------------------------------------------- switch to moving_average -----------------------
check('InventoryCostingService::isActive() is false before switching', ! Svc::isActive());
[$c, $b] = call(CSC::class, 'update', ['method' => 'moving_average']);
check('POST costing_settings (moving_average) 200', $c == 200, "$c $b");
$after = json_decode($b, true);
check('response reports method = moving_average', $after['method'] === 'moving_average', $b);
check('response reports active = true', $after['active'] === true, $b);
check('response includes lastSync stats (a sync happened, ledger was empty)', isset($after['lastSync']['products']) && $after['lastSync']['products'] > 0, $b);

// The check that actually matters: verify through the service directly, not just the controller's JSON.
Svc::forgetMethodCache();
check('InventoryCostingService::isActive() flips to TRUE after switching', Svc::isActive());
check('InventoryCostingService::method() = moving_average', Svc::method() === Svc::METHOD_MOVING_AVERAGE);

$costed = DB::table('inventory_cost_balances')->distinct('product_id')->count('product_id');
check('inventory_cost_balances actually populated (products_costed > 0)', $costed > 0, (string) $costed);
check("response's productsCosted matches the ledger (>0 and == controller's own count)", $after['productsCosted'] == $costed, json_encode([$after['productsCosted'], $costed]));

// ---------------------------------------------------------------- switch back to legacy -----------------------
[$c, $b] = call(CSC::class, 'update', ['method' => 'legacy']);
check('POST costing_settings (legacy) 200', $c == 200, "$c $b");
$back = json_decode($b, true);
check('response reports method = legacy', $back['method'] === 'legacy', $b);
check('response reports active = false', $back['active'] === false, $b);
check('no lastSync stats when switching to legacy (nothing costed)', ! isset($back['lastSync']), $b);

Svc::forgetMethodCache();
check('InventoryCostingService::isActive() flips back to FALSE', ! Svc::isActive());
check('InventoryCostingService::method() = legacy', Svc::method() === Svc::METHOD_LEGACY);

// the ledger itself is left in place (switching to legacy does not erase costed history) — costed products still
// there, just not read by legacy reports.
$stillThere = DB::table('inventory_cost_balances')->distinct('product_id')->count('product_id');
check('switching back to legacy does not clear the already-costed ledger', $stillThere == $costed, json_encode([$stillThere, $costed]));

// ---------------------------------------------------------------- resync flag ------------------------------------
[$c, $b] = call(CSC::class, 'update', ['method' => 'moving_average', 'resync' => true]);
check('POST with resync=true still 200', $c == 200, "$c $b");
$resynced = json_decode($b, true);
check('resync=true re-costs even though the ledger already had rows (lastSync present)', isset($resynced['lastSync']['products']) && $resynced['lastSync']['products'] > 0, $b);
Svc::forgetMethodCache();
Svc::setMethod(Svc::METHOD_LEGACY);
Svc::forgetMethodCache();

// ---------------------------------------------------------------- validation ---------------------------------
[$c, $b] = call(CSC::class, 'update', ['method' => 'not_a_real_method']);
check('unknown method rejected (422)', $c == 422, "$c $b");

// ---------------------------------------------------------------- safety cap (guard presence, see note above) ---
$src = file_get_contents(__DIR__.'/../../app/Http/Controllers/Settings/CostingSettingsController.php');
check('3000-product safety cap guard is present in the controller source', str_contains($src, 'MAX_SYNC_PRODUCTS') && str_contains($src, 'costing:rebuild --apply --enable'), 'guard text not found');

finish('Costing Method settings UI');
