<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->unique()->after('id');
            }

            if (! Schema::hasColumn('users', 'email')) {
                $table->string('email')->unique()->after('username');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('password');
            }

            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'remember_token')) {
                $table->string('remember_token', 100)->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('users', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            if (Schema::hasColumn('users', 'remember_token')) {
                $table->dropColumn('remember_token');
            }

            if (Schema::hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }

            if (Schema::hasColumn('users', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('users', 'username')) {
                $table->dropUnique(['username']);
                $table->dropColumn('username');
            }
        });
    }
};
