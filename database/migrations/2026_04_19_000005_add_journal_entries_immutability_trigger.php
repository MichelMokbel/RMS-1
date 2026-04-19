<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->triggerExists('journal_entries_immutable_posted')) {
            DB::unprepared('
                CREATE TRIGGER journal_entries_immutable_posted
                BEFORE UPDATE ON journal_entries
                FOR EACH ROW
                BEGIN
                    IF OLD.status != \'draft\' THEN
                        SIGNAL SQLSTATE \'45000\'
                        SET MESSAGE_TEXT = \'Posted journal entries are immutable.\';
                    END IF;
                END
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_posted');
    }

    private function triggerExists(string $name): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.triggers WHERE trigger_schema = ? AND trigger_name = ? LIMIT 1',
            [$database, $name]
        );

        return $row !== null;
    }
};
