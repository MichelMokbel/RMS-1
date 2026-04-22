<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'marketing.access',
        'marketing.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissions as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->pluck('id', 'name');

        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'manager'])
            ->pluck('id', 'name');

        $assignments = [
            'admin' => ['marketing.access', 'marketing.manage'],
            'manager' => ['marketing.access'],
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
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissions)
            ->delete();
    }
};
