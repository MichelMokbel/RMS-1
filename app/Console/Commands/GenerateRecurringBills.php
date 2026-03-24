<?php

namespace App\Console\Commands;

use App\Jobs\Accounting\GenerateRecurringBillTemplateJob;
use App\Models\RecurringBillTemplate;
use Illuminate\Console\Command;

class GenerateRecurringBills extends Command
{
    protected $signature = 'accounting:generate-recurring-bills {--date=} {--actor-id=}';

    protected $description = 'Dispatch queued recurring bill generation for due templates.';

    public function handle(): int
    {
        $runDate = (string) ($this->option('date') ?: now()->toDateString());
        $actorId = $this->option('actor-id') !== null
            ? (int) $this->option('actor-id')
            : ((int) config('app.system_user_id') ?: null);

        $templates = RecurringBillTemplate::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $runDate)
            ->orderBy('next_run_date')
            ->get(['id', 'next_run_date']);

        foreach ($templates as $template) {
            GenerateRecurringBillTemplateJob::dispatch(
                (int) $template->id,
                optional($template->next_run_date)->toDateString() ?: $runDate,
                $actorId
            );
        }

        $this->info('Queued recurring bill templates: '.$templates->count());

        return self::SUCCESS;
    }
}
