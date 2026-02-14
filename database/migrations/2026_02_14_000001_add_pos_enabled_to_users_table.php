<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'pos_enabled')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('pos_enabled')->default(false)->after('status');
                $table->index('pos_enabled', 'users_pos_enabled_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'pos_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_pos_enabled_index');
            $table->dropColumn('pos_enabled');
        });
    }
};

