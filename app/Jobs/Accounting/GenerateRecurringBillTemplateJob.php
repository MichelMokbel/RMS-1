<?php

namespace App\Jobs\Accounting;

use App\Models\RecurringBillTemplate;
use App\Services\AP\RecurringBillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateRecurringBillTemplateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $templateId,
        public string $runDate,
        public ?int $actorId = null
    ) {
    }

    public function handle(RecurringBillService $service): void
    {
        $template = RecurringBillTemplate::query()->find($this->templateId);
        if (! $template || ! $template->is_active) {
            return;
        }

        try {
            $service->generateTemplate($template, $this->runDate, $this->actorId, 'scheduled');
        } catch (\Throwable $exception) {
            Log::error('Recurring bill generation failed.', [
                'template_id' => $this->templateId,
                'run_date' => $this->runDate,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
