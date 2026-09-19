<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PermissionsController extends BaseController
{
    // ----------- GET ALL Roles --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Role::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;

        $roles = Role::where('deleted_at', '=', null)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $roles->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $roles = $roles->withCount('permissions')
            ->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'roles' => $roles,
            'totalRows' => $totalRows,
            'totalPermissions' => Permission::count(),
        ]);
    }

    // ----------- Store new Role --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);

        try {
            request()->validate([
                'role.name' => 'required',
            ]);

            \DB::transaction(function () use ($request) {

                // -- Create New Role
                $Role = new Role;
                $Role->name = $request['role']['name'];
                $Role->label = $request['role']['name'];
                $Role->status = 0;
                $Role->description = $request['role']['description'];
                $Role->save();

                $role = Role::findOrFail($Role->id);
                $role->permissions()->detach();
                $permissions = $request->permissions;

                foreach ($permissions as $permission_slug) {
                    $perm = Permission::firstOrCreate(['name' => $permission_slug]);
                    $data[] = $perm->id;
                }

                $role->permissions()->attach($data);

                // --- Build I2 (Activity Log): Role::save() above fires
                // Eloquent's 'created' event on its own (observable), but
                // that closure has no visibility into which permissions
                // were attached in this same transaction — logged here
                // instead, once, with the full picture.
                try {
                    \App\Services\Custom\ActivityLogger::log(
                        'Role',
                        'created',
                        'Role "'.$Role->name.'" created',
                        \App\Models\Role::class,
                        $Role->id,
                        null,
                        ['name' => $Role->name, 'description' => $Role->description, 'permissions' => $permissions]
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[ActivityLog] Role created log failed: '.$e->getMessage());
                }

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ----------- Update Role --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);

        try {
            request()->validate([
                'role.name' => 'required',
            ]);

            \DB::transaction(function () use ($request, $id) {

                // --- Build I2 (Activity Log): snapshot before the bulk
                // update + permission detach below — Role::whereId()
                // ->update() and the permissions() pivot detach/attach
                // don't fire Eloquent model events, so this update is
                // logged explicitly, same reasoning as ClientController.
                $activityLogOldRole = Role::with('permissions')->find($id);
                $activityLogOldPermissions = $activityLogOldRole
                    ? $activityLogOldRole->permissions->pluck('name')->sort()->values()->all()
                    : [];

                Role::whereId($id)->update($request['role']);

                $role = Role::findOrFail($id);
                $role->permissions()->detach();
                $permissions = $request->permissions;

                foreach ($permissions as $permission_slug) {

                    // get the permission object by name
                    $perm = Permission::firstOrCreate(['name' => $permission_slug]);
                    $data[] = $perm->id;
                }

                $role->permissions()->attach($data);

                try {
                    $newPermissions = collect($permissions)->sort()->values()->all();
                    $old = [];
                    $new = [];
                    if (($activityLogOldRole->name ?? null) !== $request['role']['name']) {
                        $old['name'] = $activityLogOldRole->name ?? null;
                        $new['name'] = $request['role']['name'];
                    }
                    if (($activityLogOldRole->description ?? null) !== ($request['role']['description'] ?? null)) {
                        $old['description'] = $activityLogOldRole->description ?? null;
                        $new['description'] = $request['role']['description'] ?? null;
                    }
                    if ($activityLogOldPermissions !== $newPermissions) {
                        $old['permissions'] = $activityLogOldPermissions;
                        $new['permissions'] = $newPermissions;
                    }
                    if (! empty($new)) {
                        \App\Services\Custom\ActivityLogger::log(
                            'Role',
                            'updated',
                            'Role "'.$role->name.'" updated',
                            \App\Models\Role::class,
                            $id,
                            $old,
                            $new
                        );
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[ActivityLog] Role updated log failed: '.$e->getMessage());
                }

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    // ----------- Delete Role --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);

        $activityLogRoleName = Role::find($id)?->name;

        Role::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        try {
            \App\Services\Custom\ActivityLogger::log(
                'Role',
                'deleted',
                'Role "'.($activityLogRoleName ?? $id).'" deleted',
                \App\Models\Role::class,
                $id
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ActivityLog] Role deleted log failed: '.$e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Role::class);

        $selectedIds = $request->selectedIds;
        $activityLogRoleNames = Role::whereIn('id', $selectedIds)->pluck('name', 'id');
        foreach ($selectedIds as $role_id) {
            Role::whereId($role_id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            try {
                \App\Services\Custom\ActivityLogger::log(
                    'Role',
                    'deleted',
                    'Role "'.($activityLogRoleNames[$role_id] ?? $role_id).'" deleted',
                    \App\Models\Role::class,
                    $role_id
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[ActivityLog] Role bulk-deleted log failed: '.$e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }

    // ----------- GET ALL Roles without paginate --------------\\

    public function getRoleswithoutpaginate()
    {
        $roles = Role::where('deleted_at', null)->get(['id', 'name']);

        return response()->json($roles);
    }

    // ------------- Show Form Edit Permissions -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Role::class);

        if ($id != '1') {
            $Role = Role::with('permissions')->where('deleted_at', '=', null)->findOrFail($id);
            if ($Role) {
                $item['name'] = $Role->name;
                $item['description'] = $Role->description;
                $data = [];
                if ($Role) {
                    foreach ($Role->permissions as $permission) {
                        $data[] = $permission->name;
                    }
                }
            }

            return response()->json([
                'permissions' => $data,
                'role' => $item,
            ]);

        } else {
            return response()->json([
                'success' => false,
            ], 401);
        }
    }
}
