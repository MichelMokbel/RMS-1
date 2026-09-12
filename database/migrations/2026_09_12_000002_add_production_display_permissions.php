<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $permissions = [
        'kitchen.display',
        'pastry.display',
        'order-labels.print',
        'order-label-printers.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'manager', 'kitchen', 'pastry-user'])
            ->pluck('id', 'name');
        $permissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('id', 'name');

        $assignments = [
            'admin' => $this->permissions,
            'manager' => ['kitchen.display', 'pastry.display', 'order-labels.print'],
            'kitchen' => ['kitchen.display'],
            'pastry-user' => ['pastry.display'],
        ];

        foreach ($assignments as $roleName => $permissionNames) {
            $roleId = $roles[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissions[$permissionName] ?? null;
                if ($permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        $kitchenRoleId = $roles['kitchen'] ?? null;
        $operationsPermissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', 'operations.access')
            ->value('id');

        if ($kitchenRoleId && $operationsPermissionId) {
            DB::table('role_has_permissions')
                ->where('role_id', $kitchenRoleId)
                ->where('permission_id', $operationsPermissionId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $kitchenRoleId = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', 'kitchen')
            ->value('id');
        $operationsPermissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', 'operations.access')
            ->value('id');

        if ($kitchenRoleId && $operationsPermissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $operationsPermissionId,
                'role_id' => $kitchenRoleId,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            if (Schema::hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
