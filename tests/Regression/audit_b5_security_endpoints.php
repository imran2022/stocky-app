<?php
// Audit Batch 5 (X1): module upload / module on-off / clear-cache are settings-admin only (any logged-in user could upload code);
// a module zip cannot escape its folder; the maintenance route products_clean_names is no longer public.
require __DIR__.'/_audit_lib.php';
use Illuminate\Support\Facades\{Auth, DB};

const CT_MS = App\Http\Controllers\ModuleSettingsController::class;
const CT_SE = App\Http\Controllers\SettingsController::class;
$pid = (int) DB::table('permissions')->where('name', 'setting_system')->value('id');
$roleId = (int) DB::table('role_user')->where('user_id', 2)->value('role_id');
$as = function () { App\Models\User::find(2)->unsetRelation('roles'); Auth::guard('api')->setUser(App\Models\User::find(2)); };

DB::table('permission_role')->where('permission_id', $pid)->where('role_id', $roleId)->delete(); $as();
[$c] = call(CT_MS, 'upload_module', []);
check('user without setting_system cannot upload a module (403)', $c == 403, (string) $c);
[$c] = call(CT_MS, 'update_status_module', ['name' => 'X', 'status' => 1]);
check('...cannot switch a module (403)', $c == 403, (string) $c);
[$c] = call(CT_SE, 'Clear_Cache', [], 'GET');
check('...cannot clear the cache (403)', $c == 403, (string) $c);

DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $roleId]); $as();
[$c] = call(CT_MS, 'upload_module', []);
check('settings admin passes the gate (then fails validation: no file -> 422)', $c == 422, (string) $c);

// zip slip and bad module names are refused
$mk = function (array $entries) { $f = tempnam(sys_get_temp_dir(), 'mz').'.zip'; $z = new ZipArchive; $z->open($f, ZipArchive::CREATE); foreach ($entries as $n => $c) { $z->addFromString($n, $c); } $z->close(); return $f; };
$up = function ($zip) {
    $req = Illuminate\Http\Request::create('/api/x', 'POST', [], [], ['module_zip' => new Illuminate\Http\UploadedFile($zip, 'm.zip', 'application/zip', null, true)]);
    $u = Auth::guard('api')->user(); $req->setUserResolver(fn () => $u); app()->instance('request', $req);
    try { $r = app(CT_MS)->upload_module($req); return [$r->getStatusCode(), $r->getContent()]; } catch (Throwable $e) { return ['EXC', substr($e->getMessage(), 0, 120)]; }
};
[$c, $b] = $up($mk(['../evil.php' => '<?php', 'module.json' => '{"name":"Ok"}']));
check('zip with a ../ entry refused', $c == 422 && ! file_exists(storage_path('evil.php')), "$c $b");
[$c, $b] = $up($mk(['module.json' => '{"name":"../../pwned"}']));
check('module name with path characters refused', $c == 422 && ! is_dir(base_path('pwned')), "$c $b");

$routes = file_get_contents(dirname(__DIR__, 2).'/routes/api.php');
check('products_clean_names route is gone', ! str_contains($routes, "products_clean_names"));
finish('Audit B5 security endpoints');
