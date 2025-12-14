
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        if (Schema::hasColumn('menu_items', 'status')) {
            return;
        }

        // #region agent log
        try {
            file_put_contents(
                base_path('.cursor/debug.log'),
                json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'H_MENU_STATUS',
                    'location' => 'database/migrations/2025_12_14_000010_add_status_to_menu_items_table.php:up',
                    'message' => 'Adding menu_items.status column for rebuildable schema',
                    'data' => [],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // ignore
        }
        // #endregion

        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('display_order');
            $table->index('status', 'menu_items_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        if (! Schema::hasColumn('menu_items', 'status')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex('menu_items_status_index');
            $table->dropColumn('status');
        });
    }
};


