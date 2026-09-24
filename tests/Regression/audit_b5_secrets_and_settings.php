<?php
// Audit Batch 5 (X2): integration credentials and settings writers are not open to every logged-in user.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\{Auth, DB};

$pid = (int) DB::table('permissions')->where('name', 'setting_system')->value('id');
$roleId = (int) DB::table('role_user')->where('user_id', 2)->value('role_id');
$as = function () { App\Models\User::find(2)->unsetRelation('roles'); Auth::guard('api')->setUser(App\Models\User::find(2)); };
DB::table('settings')->update(['backup_s3_secret_key' => 'TOPSECRET-B5']);

DB::table('permission_role')->where('permission_id', $pid)->where('role_id', $roleId)->delete(); $as();
[$c, $b] = call(App\Http\Controllers\SettingsController::class, 'getSettings', ['include_secrets' => '1'], 'GET');
check('non-admin asking include_secrets=1 gets the settings WITHOUT the secret', $c == 200 && ! str_contains($b, 'TOPSECRET-B5'), "$c");
foreach ([[App\Http\Controllers\QuickBooksController::class, 'quickbookgetSettings'], [App\Http\Controllers\QuickBooksController::class, 'saveSettings'], [App\Http\Controllers\QuickBooksController::class, 'disconnect'],
    [App\Http\Controllers\CustomFieldController::class, 'store'], [App\Http\Controllers\Api\Store\PagesApiController::class, 'store']] as [$cls, $m]) {
    [$c] = call($cls, $m, ['x' => 1]);
    check("non-admin: $m on ".class_basename($cls).' is 403', $c == 403, (string) $c);
}
DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $roleId]); $as();
[$c, $b] = call(App\Http\Controllers\SettingsController::class, 'getSettings', ['include_secrets' => '1'], 'GET');
check('settings admin still gets the secret when asking for it', $c == 200 && str_contains($b, 'TOPSECRET-B5'), "$c");
[$c] = call(App\Http\Controllers\QuickBooksController::class, 'quickbookgetSettings', []);
check('settings admin still reaches QuickBooks settings', $c == 200, (string) $c);
finish('Audit B5 secrets and settings');
