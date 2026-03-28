<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->insertOrIgnore([
            'name' => 'customer',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', 'customer')
            ->delete();
    }
};
