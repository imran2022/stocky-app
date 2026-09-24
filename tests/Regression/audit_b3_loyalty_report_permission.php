<?php
// Audit Batch 3 (R1): the loyalty report honours its permission (used to be open to any logged-in user).
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\DB;

const CT_RP = App\Http\Controllers\ReportController::class;
$pid = (int) DB::table('permissions')->where('name', 'customer_loyalty_points_report')->value('id');
check('permission row exists', $pid > 0);
$roleId = (int) DB::table('role_user')->where('user_id', 2)->value('role_id');

DB::table('permission_role')->where('permission_id', $pid)->where('role_id', $roleId)->delete();
App\Models\User::find(2)->unsetRelation('roles');
Illuminate\Support\Facades\Auth::guard('api')->setUser(App\Models\User::find(2));
[$c, $b] = call(CT_RP, 'customerLoyaltyPoints', ['limit' => 5], 'GET');
check('role without the permission gets 403', $c == 403, "$c ".substr($b, 0, 100));

DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $roleId]);
Illuminate\Support\Facades\Auth::guard('api')->setUser(App\Models\User::find(2));
[$c, $b] = call(CT_RP, 'customerLoyaltyPoints', ['limit' => 5], 'GET');
check('role with the permission gets the report', $c == 200, "$c ".substr($b, 0, 100));
finish('Audit B3 loyalty report permission');
