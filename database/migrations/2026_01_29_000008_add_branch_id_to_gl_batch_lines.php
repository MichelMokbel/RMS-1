<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gl_batch_lines')) {
            return;
        }

        if (! Schema::hasColumn('gl_batch_lines', 'branch_id')) {
            Schema::table('gl_batch_lines', function (Blueprint $table) {
                $table->integer('branch_id')->default(0)->after('account_id');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        /**
         * MySQL FK constraints require indexed columns. The existing FK on `batch_id`
         * (and sometimes `account_id`) may rely on the composite index/unique we are
         * about to drop. Ensure safe single-column indexes exist first.
         *
         * This also makes the migration resilient if it previously partially ran:
         * - branch_id added
         * - unique dropped
         * - drop index failed (current user report)
         */
        $this->addIndexIfMissing('gl_batch_lines', 'gl_batch_lines_batch_id_fk_index', ['batch_id']);
        $this->addIndexIfMissing('gl_batch_lines', 'gl_batch_lines_account_id_fk_index', ['account_id']);

        // Replace unique(batch_id, account_id) with unique(batch_id, account_id, branch_id)
        $this->dropUniqueIfExists('gl_batch_lines', 'gl_batch_lines_batch_id_account_id_unique');
        $this->dropIndexIfExists('gl_batch_lines', 'gl_batch_lines_batch_id_account_id_index');

        $this->addUniqueIfMissing('gl_batch_lines', 'gl_batch_lines_batch_account_branch_unique', ['batch_id', 'account_id', 'branch_id']);
        $this->addIndexIfMissing('gl_batch_lines', 'gl_batch_lines_batch_account_branch_index', ['batch_id', 'account_id', 'branch_id']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('gl_batch_lines')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropUniqueIfExists('gl_batch_lines', 'gl_batch_lines_batch_account_branch_unique');
            $this->dropIndexIfExists('gl_batch_lines', 'gl_batch_lines_batch_account_branch_index');
            $this->addUniqueIfMissing('gl_batch_lines', 'gl_batch_lines_batch_id_account_id_unique', ['batch_id', 'account_id']);
            $this->addIndexIfMissing('gl_batch_lines', 'gl_batch_lines_batch_id_account_id_index', ['batch_id', 'account_id']);
        }

        if (Schema::hasColumn('gl_batch_lines', 'branch_id')) {
            Schema::table('gl_batch_lines', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }

    private function dropUniqueIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }
        DB::statement("ALTER TABLE {$table} DROP INDEX {$name}");
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }
        try {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$name}");
        } catch (\Throwable $e) {
            // If MySQL still considers the index required by an FK, avoid breaking migrate.
            // The new unique/index added below will still enforce correctness.
        }
    }

    private function addUniqueIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }
        $cols = implode(',', $columns);
        DB::statement("ALTER TABLE {$table} ADD UNIQUE {$name} ({$cols})");
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }
        $cols = implode(',', $columns);
        DB::statement("ALTER TABLE {$table} ADD INDEX {$name} ({$cols})");
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }
};
