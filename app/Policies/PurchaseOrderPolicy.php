<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Single coarse-grained 'purchase_orders' permission covering every PO
 * action — mirrors ShipmentPolicy's convention exactly (see that class and
 * the add_purchase_orders_permission migration's docblock for why this
 * feature uses one permission rather than Purchases' four-way split).
 */
class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user)
    {
        $permission = Permission::where('name', 'purchase_orders')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function create(User $user)
    {
        $permission = Permission::where('name', 'purchase_orders')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function update(User $user)
    {
        $permission = Permission::where('name', 'purchase_orders')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function delete(User $user)
    {
        $permission = Permission::where('name', 'purchase_orders')->first();

        return $permission && $user->hasRole($permission->roles);
    }

    public function restore(User $user)
    {
        //
    }

    public function forceDelete(User $user)
    {
        //
    }
}
