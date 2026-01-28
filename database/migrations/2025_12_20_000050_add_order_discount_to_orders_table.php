<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // #region agent log
        try {
            $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
            $hasTable = Schema::hasTable('orders');
            $hasColumn = $hasTable && Schema::hasColumn('orders', 'order_discount_amount');
            file_put_contents(
                $logPath,
                json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'pre-fix',
                    'hypothesisId' => 'H2',
                    'location' => 'database/migrations/2025_12_20_000050_add_order_discount_to_orders_table.php:9',
                    'message' => 'add_order_discount migration BEFORE column add',
                    'data' => [
                        'has_orders_table' => $hasTable,
                        'has_order_discount_amount_column' => $hasColumn,
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // ignore
        }
        // #endregion
        if (! Schema::hasTable('orders')) {
            return;
        }
        if (Schema::hasColumn('orders', 'order_discount_amount')) {
            // #region agent log
            try {
                $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
                file_put_contents(
                    $logPath,
                    json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'pre-fix',
                        'hypothesisId' => 'H2',
                        'location' => 'database/migrations/2025_12_20_000050_add_order_discount_to_orders_table.php:15',
                        'message' => 'add_order_discount SKIPPED - column already exists',
                        'data' => [],
                        'timestamp' => (int) (microtime(true) * 1000),
                    ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                // ignore
            }
            // #endregion
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('order_discount_amount', 10, 3)->default(0)->after('notes');
        });
        // #region agent log
        try {
            $logPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'debug.log';
            file_put_contents(
                $logPath,
                json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'pre-fix',
                    'hypothesisId' => 'H2',
                    'location' => 'database/migrations/2025_12_20_000050_add_order_discount_to_orders_table.php:35',
                    'message' => 'add_order_discount column added successfully',
                    'data' => [],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // ignore
        }
        // #endregion
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('order_discount_amount');
        });
    }
};
