<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        // Safety guard:
        // This migration drops & recreates the schema from a SQL dump and intentionally strips data inserts.
        // It should ONLY run in tests unless explicitly enabled.
        if (! app()->environment('testing') && ! (bool) env('ALLOW_SCHEMA_REBUILD_FROM_DUMP', false)) {
            throw new RuntimeException(
                'Refusing to rebuild schema from dump outside testing. '.
                'Set ALLOW_SCHEMA_REBUILD_FROM_DUMP=true if you really want to run this migration.'
            );
        }

        $candidateDumpPaths = [
            // Prefer the most complete dump for test reliability (must include core Laravel tables like `migrations`, `users`).
            base_path('store-12142025.sql'),
            base_path('store_db.sql'),
            base_path('store_db_no_fk.sql'),
        ];

        $dumpPath = null;
        foreach ($candidateDumpPaths as $p) {
            if (is_string($p) && is_file($p)) {
                $dumpPath = $p;
                break;
            }
        }

        if (! $dumpPath) {
            throw new RuntimeException('No SQL dump file found. Expected one of: store_db_no_fk.sql, store_db.sql, store-12142025.sql');
        }

        $sql = (string) file_get_contents($dumpPath);

        // Remove dump noise + data inserts (tests should start from empty schema)
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('#/\*![\s\S]*?\*/#', '', $sql) ?? $sql; // MySQL versioned comments
        $sql = preg_replace('#/\*[\s\S]*?\*/#', '', $sql) ?? $sql; // block comments
        $sql = preg_replace('/^\s*LOCK TABLES\s+.*?;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*UNLOCK TABLES\s*;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*INSERT\s+INTO\s+.*?;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*\/\*.*?SET\s+character_set_client.*?\*\/\s*$/mi', '', $sql) ?? $sql;

        $statements = $this->splitSqlStatements($sql);
        $statements = $this->sanitizeStatements($statements);

        Schema::disableForeignKeyConstraints();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            DB::unprepared($stmt);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        Schema::enableForeignKeyConstraints();

        // Ensure the migrations repository table exists (some dumps may omit it).
        if (! Schema::hasTable('migrations')) {
            Schema::create('migrations', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op. Use `migrate:fresh` to rebuild schema.
    }

    /**
     * Split SQL into statements by semicolon, ignoring semicolons inside strings / identifiers.
     *
     * @return array<int, string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $out = [];
        $buf = '';

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $escape = false;

        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($escape) {
                $buf .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\') {
                $buf .= $ch;
                $escape = true;
                continue;
            }

            if (! $inDouble && ! $inBacktick && $ch === "'") {
                $inSingle = ! $inSingle;
                $buf .= $ch;
                continue;
            }

            if (! $inSingle && ! $inBacktick && $ch === '"') {
                $inDouble = ! $inDouble;
                $buf .= $ch;
                continue;
            }

            if (! $inSingle && ! $inDouble && $ch === '`') {
                $inBacktick = ! $inBacktick;
                $buf .= $ch;
                continue;
            }

            if (! $inSingle && ! $inDouble && ! $inBacktick && $ch === ';') {
                $out[] = $buf;
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * @param array<int, string> $statements
     * @return array<int, string>
     */
    private function sanitizeStatements(array $statements): array
    {
        $out = [];

        foreach ($statements as $stmt) {
            $t = ltrim($stmt);

            // Skip MySQL dump session SET statements (safe but noisy; some can break under strict modes)
            if (preg_match('/^(SET|USE)\s+/i', $t)) {
                continue;
            }

            // Remove FK constraints added via ALTER TABLE
            if (preg_match('/^ALTER\s+TABLE/i', $t) && preg_match('/\b(FOREIGN\s+KEY|CONSTRAINT)\b/i', $t)) {
                continue;
            }

            // Strip inline FK constraints in CREATE TABLE statements (dump may define tables out of dependency order)
            if (preg_match('/^CREATE\s+TABLE/i', $t) && preg_match('/\bFOREIGN\s+KEY\b/i', $t)) {
                $stmt = $this->stripInlineForeignKeysFromCreateTable($stmt);
            }

            $out[] = $stmt;
        }

        return $out;
    }

    private function stripInlineForeignKeysFromCreateTable(string $stmt): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $stmt) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            // remove FK constraint lines like: CONSTRAINT `x` FOREIGN KEY (...) REFERENCES ...
            if (preg_match('/\bCONSTRAINT\b.*\bFOREIGN\s+KEY\b/i', $line)) {
                continue;
            }
            // remove bare FOREIGN KEY lines if present
            if (preg_match('/\bFOREIGN\s+KEY\b/i', $line)) {
                continue;
            }
            $filtered[] = $line;
        }

        // Ensure the last definition line before the closing ')' has no trailing comma.
        for ($i = count($filtered) - 1; $i >= 0; $i--) {
            $trim = trim($filtered[$i]);
            if ($trim === '' || $trim === ')') {
                continue;
            }
            // If this line is the engine/options line, keep scanning upward.
            if (preg_match('/^\)\s*ENGINE\s*=/i', $trim) || preg_match('/^\)\s*;?\s*$/', $trim)) {
                continue;
            }
            $filtered[$i] = preg_replace('/,\s*$/', '', $filtered[$i]) ?? $filtered[$i];
            break;
        }

        return implode("\n", $filtered);
    }
};


