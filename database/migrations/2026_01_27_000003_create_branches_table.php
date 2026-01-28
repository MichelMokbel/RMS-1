<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('CREATE TABLE branches (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, code VARCHAR(50) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT NULL, updated_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
            } else {
                Schema::create('branches', function (Blueprint $table) {
                    $table->integer('id', true);
                    $table->string('name', 100);
                    $table->string('code', 50)->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->timestamps();
                });
            }
        }

        if (! Schema::hasTable('branches')) {
            return;
        }

        $branchIds = $this->collectBranchIds();
        if (empty($branchIds)) {
            $branchIds = [1];
        }

        foreach ($branchIds as $id) {
            if (! is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $exists = DB::table('branches')->where('id', $id)->exists();
            if ($exists) {
                continue;
            }

            DB::table('branches')->insert([
                'id' => $id,
                'name' => 'Branch '.$id,
                'code' => null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }

    private function collectBranchIds(): array
    {
        $tables = [
            'orders',
            'meal_subscriptions',
            'meal_subscription_orders',
            'daily_dish_menus',
            'ops_events',
            'subscription_order_runs',
        ];

        $ids = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }
            $rows = DB::table($table)->select('branch_id')->distinct()->pluck('branch_id')->all();
            foreach ($rows as $row) {
                if ($row === null || $row === '') {
                    continue;
                }
                $ids[] = (int) $row;
            }
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
        sort($ids);
        return $ids;
    }
};
