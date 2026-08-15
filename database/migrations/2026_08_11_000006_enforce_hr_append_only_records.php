<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'hr_document_versions',
        'hr_leave_request_events',
        'hr_leave_ledger_entries',
        'hr_payroll_status_events',
        'hr_payroll_result_components',
        'hr_audit_logs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables as $table) {
            $updateTrigger = "{$table}_immutable_update";
            $deleteTrigger = "{$table}_immutable_delete";

            DB::unprepared(
                "CREATE TRIGGER `{$updateTrigger}` BEFORE UPDATE ON `{$table}` FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR audit records are append-only'"
            );
            DB::unprepared(
                "CREATE TRIGGER `{$deleteTrigger}` BEFORE DELETE ON `{$table}` FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR audit records are append-only'"
            );
        }
    }

    /** Forward-only: removing these guards would weaken retained HR evidence. */
    public function down(): void
    {
        // No-op.
    }
};
