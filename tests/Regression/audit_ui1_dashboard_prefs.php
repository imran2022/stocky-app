<?php
// Modern Dashboard step 1: Classic/Modern choice and section layout are stored per user, with an organisation default,
// validated, and only an authorised user can change the organisation default.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\{Auth,DB,Schema};
use App\Support\Dashboard\DashboardPreferences as P;

(require dirname(__DIR__, 2).'/database/migrations/2026_09_25_000001_create_dashboard_preferences_table.php')->up();
(require dirname(__DIR__, 2).'/database/migrations/2026_09_25_000002_add_allow_user_switch_to_dashboard_preferences.php')->up();
check('table exists', Schema::hasTable('dashboard_preferences'));

$get = fn () => json_decode(call(App\Http\Controllers\DashboardPreferenceController::class, 'show', [], 'GET')[1], true);

$d = $get();
check('nothing saved -> Classic (existing dashboard stays the default)', $d['style'] === 'classic' && $d['my_style'] === null && $d['default_style'] === 'classic', json_encode($d));
check('default layout = full section list, nothing hidden', $d['layout']['order'] === P::SECTIONS && $d['layout']['hidden'] === []);
check('owner may set the organisation default', $d['can_set_default'] === true);

// user's own choice
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'modern'], 'PUT');
$d = json_decode($b, true);
check('user picks Modern', $c == 200 && $d['style'] === 'modern' && $d['my_style'] === 'modern', $c.' '.$b);
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'fancy'], 'PUT');
check('unknown style is rejected (422) and nothing changes', $c == 422 && $get()['style'] === 'modern', $c.' '.$b);

// layout: unknown ids dropped, duplicates removed, missing sections appended, hidden kept
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['layout' => ['order' => ['recent_sales', 'bogus', 'kpis', 'recent_sales'], 'hidden' => ['sales_map', 'nope', 'sales_map']]], 'PUT');
$d = json_decode($b, true);
check('layout: saved order starts with the user order', array_slice($d['layout']['order'], 0, 2) === ['recent_sales', 'kpis'], json_encode($d['layout']));
check('layout: every section still present exactly once', count($d['layout']['order']) === count(P::SECTIONS) && count(array_unique($d['layout']['order'])) === count(P::SECTIONS) && !array_diff(P::SECTIONS, $d['layout']['order']));
check('layout: unknown ids dropped, hidden de-duplicated', $d['layout']['hidden'] === ['sales_map'], json_encode($d['layout']['hidden']));
check('style kept when only layout is sent', $d['style'] === 'modern');

// an old saved layout that misses a section added later still shows the new section
DB::table('dashboard_preferences')->where('user_id', 2)->update(['layout' => json_encode(['order' => ['kpis', 'insights'], 'hidden' => []])]);
$o = $get()['layout']['order'];
check('sections added by a later version appear automatically', count($o) === count(P::SECTIONS) && $o[0] === 'kpis' && in_array('sales_map', $o, true));

// reset to default: explicit null clears back to inherit
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['layout' => null], 'PUT');
$d = json_decode($b, true);
check('reset layout -> inherits default', $d['my_layout'] === null && $d['layout']['order'] === P::SECTIONS);

// organisation default is inherited by users who chose nothing
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateDefault', ['style' => 'modern', 'layout' => ['order' => ['insights'], 'hidden' => ['quick_actions']]], 'PUT');
check('owner saves the organisation default', $c == 200 && json_decode($b, true)['default_style'] === 'modern', $c.' '.$b);
$other = P::resolve(999);
check('a user with no choice inherits the organisation style + layout', $other['style'] === 'modern' && $other['layout']['order'][0] === 'insights' && $other['layout']['hidden'] === ['quick_actions'] && $other['my_style'] === null);
call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'classic'], 'PUT');
check('the user own choice beats the organisation default', $get()['style'] === 'classic');
call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => null], 'PUT');
check('clearing the own choice inherits the organisation default again', $get()['style'] === 'modern');
check('exactly one organisation row and one row per user', DB::table('dashboard_preferences')->whereNull('user_id')->count() === 1 && DB::table('dashboard_preferences')->where('user_id', 2)->count() === 1);

// a user without the system-settings permission cannot change the organisation default
$roleId = DB::table('roles')->insertGetId(['name' => 'Cashier', 'label' => 'Cashier', 'description' => 'x', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
$row = (array) DB::table('users')->where('id', 2)->first(); unset($row['id']);
$row['role_id'] = $roleId; $row['email'] = 'cashier@example.com'; $row['username'] = 'cashier';
$uid = DB::table('users')->insertGetId($row);
DB::table('role_user')->insert(['user_id' => $uid, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
Auth::guard('api')->setUser(App\Models\User::find($uid));
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateDefault', ['style' => 'classic'], 'PUT');
check('non-admin cannot change the organisation default (403)', $c == 403, $c.' '.$b);
check('the organisation default is unchanged', P::resolve(999)['default_style'] === 'modern');
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'modern'], 'PUT');
$d = json_decode($b, true);
check('non-admin can still choose their own style', $c == 200 && $d['my_style'] === 'modern' && $d['can_set_default'] === false, $c.' '.$b);
check('one user cannot see or change another user row', DB::table('dashboard_preferences')->where('user_id', 2)->value('style') === null);

// organisation switch: off -> people see the organisation default and cannot change it; administrators still can
Auth::guard('api')->setUser(App\Models\User::find(2));
check('switching is allowed by default', P::switchAllowed() === true);
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateDefault', ['allow_user_switch' => false, 'style' => 'classic'], 'PUT');
$d = json_decode($b, true);
check('admin turns switching off', $c == 200 && $d['allow_user_switch'] === false, $c.' '.$b);
Auth::guard('api')->setUser(App\Models\User::find($uid));
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'show', [], 'GET');
$d = json_decode($b, true);
check('non-admin now sees the organisation style and cannot switch', $d['style'] === 'classic' && $d['can_switch'] === false && $d['my_style'] === null, $b);
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'modern'], 'PUT');
check('non-admin style change is refused (403)', $c == 403, $c.' '.$b);
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['layout' => ['order' => ['kpis'], 'hidden' => []]], 'PUT');
check('layout can still be saved when switching is off', $c == 200, $c.' '.$b);
Auth::guard('api')->setUser(App\Models\User::find(2));
[$c, $b] = call(App\Http\Controllers\DashboardPreferenceController::class, 'updateMine', ['style' => 'modern'], 'PUT');
$d = json_decode($b, true);
check('admin can still choose their own style', $c == 200 && $d['style'] === 'modern' && $d['can_switch'] === true, $c.' '.$b);
call(App\Http\Controllers\DashboardPreferenceController::class, 'updateDefault', ['allow_user_switch' => true], 'PUT');
check('switching can be turned back on', P::switchAllowed() === true);
finish('UI1 dashboard preferences');
