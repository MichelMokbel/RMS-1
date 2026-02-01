<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_subscription_orders')) {
            return;
        }

        Schema::table('meal_subscription_orders', function (Blueprint $table) {
            if (! $this->uniqueExists('meal_subscription_orders', 'meal_sub_orders_subscription_order_unique')) {
                $table->unique(['subscription_id', 'order_id'], 'meal_sub_orders_subscription_order_unique');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql' && $this->indexExists('meal_subscription_orders', 'meal_sub_orders_unique')) {
            Schema::table('meal_subscription_orders', function (Blueprint $table) {
                $table->dropUnique('meal_sub_orders_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('meal_subscription_orders')) {
            return;
        }

        Schema::table('meal_subscription_orders', function (Blueprint $table) {
            if ($this->uniqueExists('meal_subscription_orders', 'meal_sub_orders_subscription_order_unique')) {
                $table->dropUnique('meal_sub_orders_subscription_order_unique');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql' && ! $this->indexExists('meal_subscription_orders', 'meal_sub_orders_unique')) {
            Schema::table('meal_subscription_orders', function (Blueprint $table) {
                $table->unique(['subscription_id', 'service_date', 'branch_id'], 'meal_sub_orders_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }
        $result = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$database, $table, $indexName]
        );

        return (int) ($result->c ?? 0) > 0;
    }

    private function uniqueExists(string $table, string $indexName): bool
    {
        return $this->indexExists($table, $indexName);
    }
};