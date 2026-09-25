<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const CORE_TABLES = [
        'users',
        'customers',
        'menu_items',
        'orders',
        'payments',
    ];

    public function up(): void
    {
        $existingCoreTables = array_values(array_filter(
            self::CORE_TABLES,
            static fn (string $table): bool => Schema::hasTable($table)
        ));

        if (count($existingCoreTables) === count(self::CORE_TABLES)) {
            return;
        }

        if ($existingCoreTables !== []) {
            throw new \RuntimeException(
                'The database is partially initialized. Safe legacy schema bootstrap was refused.'
            );
        }

        $dumpPath = base_path('schema.sql');
        if (! is_file($dumpPath)) {
            throw new \RuntimeException('The legacy schema source was not found at schema.sql.');
        }

        $sql = (string) file_get_contents($dumpPath);
        $created = 0;

        foreach ($this->splitSqlStatements($sql) as $statement) {
            $statement = trim($statement);
            if (! preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches)) {
                continue;
            }

            $table = $matches[1];
            if (Schema::hasTable($table)) {
                continue;
            }

            DB::unprepared($this->stripInlineForeignKeys($statement));
            $created++;
        }

        if ($created === 0 || ! Schema::hasTable('orders')) {
            throw new \RuntimeException('Safe legacy schema bootstrap did not create the required core tables.');
        }
    }

    public function down(): void
    {
        // The safe bootstrap never removes tables or data.
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $escaped = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($escaped) {
                $buffer .= $character;
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $buffer .= $character;
                $escaped = true;

                continue;
            }

            if (! $inDoubleQuote && ! $inBacktick && $character === "'") {
                $inSingleQuote = ! $inSingleQuote;
            } elseif (! $inSingleQuote && ! $inBacktick && $character === '"') {
                $inDoubleQuote = ! $inDoubleQuote;
            } elseif (! $inSingleQuote && ! $inDoubleQuote && $character === '`') {
                $inBacktick = ! $inBacktick;
            }

            if (! $inSingleQuote && ! $inDoubleQuote && ! $inBacktick && $character === ';') {
                $statements[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function stripInlineForeignKeys(string $statement): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $statement) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            if (preg_match('/\bFOREIGN\s+KEY\b/i', $line)) {
                continue;
            }

            $filtered[] = $line;
        }

        for ($index = count($filtered) - 1; $index >= 0; $index--) {
            $trimmed = trim($filtered[$index]);
            if ($trimmed === '' || str_starts_with($trimmed, ')')) {
                continue;
            }

            $filtered[$index] = preg_replace('/,\s*$/', '', $filtered[$index]) ?? $filtered[$index];
            break;
        }

        return implode("\n", $filtered);
    }
};
