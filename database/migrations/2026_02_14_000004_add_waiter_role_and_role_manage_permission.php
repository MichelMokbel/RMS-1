<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();

        DB::table('roles')->insertOrIgnore([
            'name' => 'waiter',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('permissions')->insertOrIgnore([
            'name' => 'iam.roles.manage',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissions = [
            'pos.login',
            'orders.access',
            'catalog.access',
            'iam.roles.manage',
        ];

        foreach ($permissions as $permissionName) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permissionName,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $waiterRoleId = DB::table('roles')
            ->where('name', 'waiter')
            ->where('guard_name', 'web')
            ->value('id');

        if ($waiterRoleId) {
            $permissionIds = DB::table('permissions')
                ->where('guard_name', 'web')
                ->whereIn('name', ['pos.login', 'orders.access', 'catalog.access'])
                ->pluck('id')
                ->all();

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $waiterRoleId,
                ]);
            }
        }

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');
        $managePermissionId = DB::table('permissions')
            ->where('name', 'iam.roles.manage')
            ->where('guard_name', 'web')
            ->value('id');

        if ($adminRoleId && $managePermissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $managePermissionId,
                'role_id' => $adminRoleId,
            ]);
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('model_has_roles') || ! Schema::hasColumn('users', 'pos_enabled')) {
            return;
        }

        DB::table('users')
            ->whereIn('id', function ($q) use ($waiterRoleId): void {
                if (! $waiterRoleId) {
                    $q->selectRaw('0');
                    return;
                }
                $q->select('model_id')
                    ->from('model_has_roles')
                    ->where('model_type', \App\Models\User::class)
                    ->where('role_id', $waiterRoleId);
            })
            ->update(['pos_enabled' => 1]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $waiterRoleId = DB::table('roles')->where('name', 'waiter')->where('guard_name', 'web')->value('id');
        if ($waiterRoleId && Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->where('role_id', $waiterRoleId)->delete();
        }
        DB::table('roles')->where('name', 'waiter')->where('guard_name', 'web')->delete();
    }
};

