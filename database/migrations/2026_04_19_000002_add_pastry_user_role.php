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
            'name'       => 'pastry-user',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $roleId = DB::table('roles')
            ->where('name', 'pastry-user')
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
    }
};
