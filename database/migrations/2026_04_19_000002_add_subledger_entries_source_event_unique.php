<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subledger_entries')) {
            return;
        }

        if ($this->indexExists('subledger_entries', 'subledger_entries_source_event_unique')) {
            return;
        }

        if ($this->hasDuplicates()) {
            throw new RuntimeException(
                'Cannot add subledger_entries_source_event_unique: duplicate (source_type, source_id, event) rows exist. '
                . 'Resolve duplicates before running this migration.'
            );
        }

        Schema::table('subledger_entries', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'event'], 'subledger_entries_source_event_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('subledger_entries') && $this->indexExists('subledger_entries', 'subledger_entries_source_event_unique')) {
            Schema::table('subledger_entries', function (Blueprint $table) {
                $table->dropUnique('subledger_entries_source_event_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $row !== null;
    }

    private function hasDuplicates(): bool
    {
        return DB::table('subledger_entries')
            ->select(['source_type', 'source_id', 'event'])
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->whereNotNull('event')
            ->groupBy('source_type', 'source_id', 'event')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};
