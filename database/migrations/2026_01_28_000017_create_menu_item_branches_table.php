<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_item_branches')) {
            return;
        }

        Schema::create('menu_item_branches', function (Blueprint $table) {
            $table->integer('menu_item_id');
            $table->integer('branch_id');
            $table->timestamps();

            $table->primary(['menu_item_id', 'branch_id'], 'menu_item_branches_pk');
            $table->index('branch_id', 'menu_item_branches_branch_index');
        });

        if (Schema::hasTable('menu_items') && Schema::hasTable('branches')) {
            Schema::table('menu_item_branches', function (Blueprint $table) {
                $table->foreign('menu_item_id', 'menu_item_branches_item_fk')
                    ->references('id')->on('menu_items')
                    ->onDelete('cascade');
                $table->foreign('branch_id', 'menu_item_branches_branch_fk')
                    ->references('id')->on('branches')
                    ->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_item_branches')) {
            return;
        }

        if ($this->foreignKeyExists('menu_item_branches_item_fk')) {
            Schema::table('menu_item_branches', function (Blueprint $table) {
                $table->dropForeign('menu_item_branches_item_fk');
            });
        }
        if ($this->foreignKeyExists('menu_item_branches_branch_fk')) {
            Schema::table('menu_item_branches', function (Blueprint $table) {
                $table->dropForeign('menu_item_branches_branch_fk');
            });
        }

        Schema::dropIfExists('menu_item_branches');
    }

    private function foreignKeyExists(string $constraint): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = "FOREIGN KEY" LIMIT 1',
            [$database, 'menu_item_branches', $constraint]
        );

        return $row !== null;
    }
};
