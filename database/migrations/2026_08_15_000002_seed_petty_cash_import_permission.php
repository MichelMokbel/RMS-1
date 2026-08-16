<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'name' => 'petty_cash.import',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = DB::table('permissions')
            ->where('name', 'petty_cash.import')
            ->where('guard_name', 'web')
            ->value('id');
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId && $adminRoleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
            ]);
        }
    }

    /** Forward-only: deployment rollback must not silently revoke import authority. */
    public function down(): void
    {
        // No-op.
    }
};
