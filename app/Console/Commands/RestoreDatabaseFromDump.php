<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RestoreDatabaseFromDump extends Command
{
    protected $signature = 'db:restore-from-dump
        {--path=store-12142025.sql : Path (relative to base_path) of the SQL dump to import}
        {--connection=mysql : Database connection to use}
        {--force : Required. Acknowledge that this will DROP/RECREATE tables and overwrite data}';

    protected $description = 'Restores the database by importing a full SQL dump (including data). WARNING: this drops/recreates tables.';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force. This command will DROP/RECREATE tables and overwrite data.');
            return self::FAILURE;
        }

        $connection = (string) $this->option('connection');
        $pathOpt = (string) $this->option('path');
        $dumpPath = str_starts_with($pathOpt, DIRECTORY_SEPARATOR) ? $pathOpt : base_path($pathOpt);

        if (! is_file($dumpPath)) {
            throw new RuntimeException("SQL dump not found at: {$dumpPath}");
        }

        $dbName = DB::connection($connection)->getDatabaseName();
        $this->warn("About to restore database '{$dbName}' from dump: {$dumpPath}");

        $sql = (string) file_get_contents($dumpPath);

        // Remove dump noise (keep INSERTs).
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('#/\*![\s\S]*?\*/#', '', $sql) ?? $sql; // MySQL versioned comments
        $sql = preg_replace('#/\*[\s\S]*?\*/#', '', $sql) ?? $sql;   // block comments
        $sql = preg_replace('/^\s*DELIMITER\s+.*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*LOCK TABLES\s+.*?;\s*$/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*UNLOCK TABLES\s*;\s*$/mi', '', $sql) ?? $sql;

        $statements = $this->splitSqlStatements($sql);

        $conn = DB::connection($connection);
        Schema::disableForeignKeyConstraints();
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');

        $executed = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $conn->unprepared($stmt);
            $executed++;

            if ($executed % 200 === 0) {
                $this->output->write('.');
            }
        }

        $conn->statement('SET FOREIGN_KEY_CHECKS=1');
        Schema::enableForeignKeyConstraints();

        $this->newLine();
        $this->info("Done. Executed {$executed} SQL statements.");

        return self::SUCCESS;
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
}


