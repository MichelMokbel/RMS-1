<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $roleNames = ['admin', 'manager', 'cashier', 'waiter', 'kitchen', 'staff'];

    /**
     * @var array<int, string>
     */
    private array $permissionNames = [
        'iam.users.manage',
        'iam.roles.manage',
        'iam.roles.view',
        'iam.permissions.assign',
        'settings.pos_terminals.manage',
        'pos.login',
        'orders.access',
        'catalog.access',
        'operations.access',
        'receivables.access',
        'finance.access',
        'reports.access',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        foreach ($this->roleNames as $name) {
            DB::table('roles')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($this->permissionNames as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->roleNames)
            ->pluck('id', 'name');

        $permissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissionNames)
            ->pluck('id', 'name');

        $assignments = [
            'admin' => $this->permissionNames,
            'manager' => [
                'settings.pos_terminals.manage',
                'pos.login',
                'orders.access',
                'catalog.access',
                'operations.access',
                'receivables.access',
                'finance.access',
                'reports.access',
            ],
            'cashier' => [
                'pos.login',
                'orders.access',
                'catalog.access',
                'operations.access',
            ],
            'waiter' => [
                'pos.login',
                'orders.access',
                'catalog.access',
            ],
            'kitchen' => [
                'operations.access',
            ],
            'staff' => [
                'finance.access',
                'reports.access',
            ],
        ];

        foreach ($assignments as $roleName => $permissionList) {
            $roleId = $roles[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }
            foreach ($permissionList as $permissionName) {
                $permissionId = $permissions[$permissionName] ?? null;
                if (! $permissionId) {
                    continue;
                }
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'pos_enabled') && Schema::hasTable('model_has_roles')) {
            $allowedRoleIds = DB::table('roles')
                ->where('guard_name', 'web')
                ->whereIn('name', ['admin', 'manager', 'cashier', 'waiter'])
                ->pluck('id');

            if ($allowedRoleIds->isNotEmpty()) {
                DB::table('users')
                    ->whereIn('id', function ($q) use ($allowedRoleIds): void {
                        $q->select('model_id')
                            ->from('model_has_roles')
                            ->where('model_type', \App\Models\User::class)
                            ->whereIn('role_id', $allowedRoleIds);
                    })
                    ->update(['pos_enabled' => 1]);
            }
        }

        if (! Schema::hasTable('user_branch_access') || ! Schema::hasTable('branches') || ! Schema::hasTable('users')) {
            return;
        }

        $branchQuery = DB::table('branches')->select('id');
        if (Schema::hasColumn('branches', 'is_active')) {
            $branchQuery->where('is_active', 1);
        }
        $branchIds = $branchQuery->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($branchIds->isEmpty()) {
            return;
        }

        $adminRoleId = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', 'admin')
            ->value('id');

        $userQuery = DB::table('users')->select('id');
        if ($adminRoleId) {
            $userQuery->whereNotIn('id', function ($q) use ($adminRoleId): void {
                $q->select('model_id')
                    ->from('model_has_roles')
                    ->where('model_type', \App\Models\User::class)
                    ->where('role_id', $adminRoleId);
            });
        }

        $userIds = $userQuery->pluck('id')->map(fn ($id) => (int) $id)->values();
        if ($userIds->isEmpty()) {
            return;
        }

        $rows = [];
        foreach ($userIds as $userId) {
            foreach ($branchIds as $branchId) {
                $rows[] = [
                    'user_id' => $userId,
                    'branch_id' => $branchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) >= 500) {
                    DB::table('user_branch_access')->insertOrIgnore($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('user_branch_access')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->whereIn('name', $this->permissionNames)
                ->delete();
        }
    }
};
