<?php

use App\Support\Accounting\PeriodCloseTaskCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('closing_checklists')) {
            return;
        }

        Schema::table('closing_checklists', function (Blueprint $table) {
            if (! Schema::hasColumn('closing_checklists', 'task_key')) {
                $table->string('task_key', 80)->nullable()->after('period_id');
            }
            if (! Schema::hasColumn('closing_checklists', 'task_type')) {
                $table->string('task_type', 20)->default('manual')->after('task_name');
            }
            if (! Schema::hasColumn('closing_checklists', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('task_type');
            }
            if (! Schema::hasColumn('closing_checklists', 'result_payload')) {
                $table->json('result_payload')->nullable()->after('notes');
            }
        });

        $this->backfillExistingRows();
        $this->seedMissingRows();

        Schema::table('closing_checklists', function (Blueprint $table) {
            $table->unique(['company_id', 'period_id', 'task_key'], 'closing_checklists_period_task_key_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('closing_checklists')) {
            return;
        }

        Schema::table('closing_checklists', function (Blueprint $table) {
            $table->dropUnique('closing_checklists_period_task_key_uq');
        });

        Schema::table('closing_checklists', function (Blueprint $table) {
            foreach (['result_payload', 'is_required', 'task_type', 'task_key'] as $column) {
                if (Schema::hasColumn('closing_checklists', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillExistingRows(): void
    {
        $definitions = collect(PeriodCloseTaskCatalog::definitions())->keyBy('name');

        DB::table('closing_checklists')
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($definitions) {
                $definition = $definitions->get($row->task_name);
                $taskKey = $definition['key'] ?? $this->slug((string) $row->task_name);
                $taskType = $definition['type'] ?? 'manual';
                $isRequired = $definition['required'] ?? true;

                DB::table('closing_checklists')
                    ->where('id', $row->id)
                    ->update([
                        'task_key' => $taskKey,
                        'task_type' => $taskType,
                        'is_required' => $isRequired ? 1 : 0,
                    ]);
            });
    }

    private function seedMissingRows(): void
    {
        if (! Schema::hasTable('accounting_periods')) {
            return;
        }

        $periods = DB::table('accounting_periods')->select(['id', 'company_id'])->get();
        $now = now();

        foreach ($periods as $period) {
            foreach (PeriodCloseTaskCatalog::definitions() as $definition) {
                $exists = DB::table('closing_checklists')
                    ->where('company_id', $period->company_id)
                    ->where('period_id', $period->id)
                    ->where('task_key', $definition['key'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('closing_checklists')->insert([
                    'company_id' => $period->company_id,
                    'period_id' => $period->id,
                    'task_key' => $definition['key'],
                    'task_name' => $definition['name'],
                    'task_type' => $definition['type'],
                    'is_required' => $definition['required'] ? 1 : 0,
                    'status' => $definition['type'] === 'system' ? 'pending' : 'pending',
                    'completed_at' => null,
                    'completed_by' => null,
                    'notes' => null,
                    'result_payload' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
};
