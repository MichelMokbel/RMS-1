<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionService
{
    /**
     * @param  array<int, string>  $permissionNames
     */
    public function updateRolePermissions(User $actor, Role $role, array $permissionNames): Role
    {
        if (! $actor->hasRole('admin') && ! $actor->can('iam.roles.manage')) {
            throw ValidationException::withMessages([
                'permissions' => __('You are not allowed to manage role permissions.'),
            ]);
        }

        $validNames = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        DB::transaction(function () use ($role, $validNames): void {
            $role->syncPermissions($validNames);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh(['permissions']);
    }
}

