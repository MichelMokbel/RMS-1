<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_branch_access')) {
            return;
        }

        Schema::create('user_branch_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();

            $table->unique(['user_id', 'branch_id'], 'user_branch_access_user_branch_unique');
            $table->index('user_id', 'user_branch_access_user_idx');
            $table->index('branch_id', 'user_branch_access_branch_idx');

            $table->foreign('user_id', 'user_branch_access_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('branch_id', 'user_branch_access_branch_fk')
                ->references('id')
                ->on('branches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_access');
    }
};

