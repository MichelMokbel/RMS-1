<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $newPermissions = [
        'accounting.read',
        'accounting.write',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        DB::table('roles')->insertOrIgnore([
            'name'       => 'accounting',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($this->newPermissions as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name'       => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $allPermissionNames = array_merge($this->newPermissions, [
            'finance.access',
            'receivables.access',
            'reports.access',
        ]);

        $permissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $allPermissionNames)
            ->pluck('id', 'name');

        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'accounting'])
            ->pluck('id', 'name');

        $assignments = [
            'accounting' => [
                'accounting.read',
                'finance.access',
                'receivables.access',
                'reports.access',
            ],
            'admin' => [
                'accounting.read',
                'accounting.write',
            ],
        ];

        foreach ($assignments as $roleName => $permNames) {
            $roleId = $roles[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($permNames as $permName) {
                $permId = $permissions[$permName] ?? null;
                if (! $permId) {
                    continue;
                }

                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permId,
                    'role_id'       => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $roleId = DB::table('roles')
            ->where('name', 'accounting')
            ->where('guard_name', 'web')
            ->value('id');

        if ($roleId) {
            if (Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            }
            if (Schema::hasTable('model_has_roles')) {
                DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            }
            DB::table('roles')->where('id', $roleId)->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->whereIn('name', $this->newPermissions)
                ->delete();
        }
    }
};
