<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if (! $this->indexExists('menu_items', 'menu_items_code_index')) {
                $table->index('code', 'menu_items_code_index');
            }
            if (! $this->indexExists('menu_items', 'menu_items_name_index')) {
                $table->index('name', 'menu_items_name_index');
            }
            if (! $this->indexExists('menu_items', 'menu_items_arabic_name_index')) {
                $table->index('arabic_name', 'menu_items_arabic_name_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if ($this->indexExists('menu_items', 'menu_items_code_index')) {
                $table->dropIndex('menu_items_code_index');
            }
            if ($this->indexExists('menu_items', 'menu_items_name_index')) {
                $table->dropIndex('menu_items_name_index');
            }
            if ($this->indexExists('menu_items', 'menu_items_arabic_name_index')) {
                $table->dropIndex('menu_items_arabic_name_index');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            if (! $database) {
                return false;
            }

            $result = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
