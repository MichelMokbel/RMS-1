<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\RecurringBillTemplate;
use App\Models\RecurringBillTemplateLine;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurringBillService
{
    public function __construct(
        protected AccountingContextService $context,
        protected ApInvoiceTotalsService $totalsService,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveTemplate(array $data, int $actorId, ?RecurringBillTemplate $template = null): RecurringBillTemplate
    {
        return DB::transaction(function () use ($data, $actorId, $template) {
            $companyId = (int) ($data['company_id'] ?? 0) ?: $this->context->defaultCompanyId();
            $lines = collect((array) ($data['lines'] ?? []))
                ->map(fn (array $line, int $index) => [
                    'purchase_order_item_id' => ! empty($line['purchase_order_item_id']) ? (int) $line['purchase_order_item_id'] : null,
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => round((float) ($line['quantity'] ?? 0), 3),
                    'unit_price' => round((float) ($line['unit_price'] ?? 0), 4),
                    'line_total' => round((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2),
                    'sort_order' => $index + 1,
                ])
                ->filter(fn (array $line) => $line['description'] !== '' && $line['quantity'] > 0)
                ->values();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => __('At least one recurring bill line is required.'),
                ]);
            }

            $record = $template
                ? RecurringBillTemplate::query()->lockForUpdate()->findOrFail($template->id)
                : new RecurringBillTemplate();

            $record->fill([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'department_id' => $data['department_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'name' => $data['name'],
                'document_type' => 'recurring_bill',
                'frequency' => $data['frequency'] ?? 'monthly',
                'default_amount' => round((float) $lines->sum('line_total'), 2),
                'due_day_offset' => (int) ($data['due_day_offset'] ?? 30),
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'next_run_date' => $data['next_run_date'] ?? ($data['start_date'] ?? now()->toDateString()),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notes' => $data['notes'] ?? null,
                'line_template' => $lines->map(fn (array $line) => [
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ])->all(),
            ]);
            $record->save();

            $record->lines()->delete();
            foreach ($lines as $line) {
                $record->lines()->create($line);
            }

            $this->auditLog->log(
                $template ? 'recurring_bill_template.updated' : 'recurring_bill_template.created',
                $actorId,
                $record,
                ['line_count' => $lines->count()],
                $companyId
            );

            return $record->fresh(['lines', 'supplier', 'company']);
        });
    }

    public function pause(RecurringBillTemplate $template, int $actorId): RecurringBillTemplate
    {
        $template->update(['is_active' => false]);
        $this->auditLog->log('recurring_bill_template.paused', $actorId, $template, [], (int) $template->company_id);

        return $template->fresh(['lines', 'generatedInvoices']);
    }

    public function resume(RecurringBillTemplate $template, int $actorId): RecurringBillTemplate
    {
        $nextRunDate = $template->next_run_date ?: ($template->start_date ?: now()->toDateString());
        $template->update([
            'is_active' => true,
            'next_run_date' => $nextRunDate,
        ]);
        $this->auditLog->log('recurring_bill_template.resumed', $actorId, $template, [], (int) $template->company_id);

        return $template->fresh(['lines', 'generatedInvoices']);
    }

    public function generateTemplate(RecurringBillTemplate $template, ?string $runDate = null, ?int $actorId = null, string $generationSource = 'manual'): ApInvoice
    {
        return DB::transaction(function () use ($template, $runDate, $actorId, $generationSource) {
            $template = RecurringBillTemplate::query()->with('lines')->lockForUpdate()->findOrFail($template->id);
            $date = Carbon::parse($runDate ?: optional($template->next_run_date)->toDateString() ?: now()->toDateString())->toDateString();

            $duplicate = ApInvoice::query()
                ->where('recurring_template_id', $template->id)
                ->whereDate('invoice_date', $date)
                ->first();

            if ($duplicate) {
                return $duplicate->fresh(['items']);
            }

            $invoice = ApInvoice::query()->create([
                'company_id' => $template->company_id,
                'branch_id' => $template->branch_id,
                'department_id' => $template->department_id,
                'job_id' => $template->job_id,
                'period_id' => $this->context->resolvePeriodId($date, (int) $template->company_id),
                'supplier_id' => $template->supplier_id,
                'is_expense' => false,
                'document_type' => 'recurring_bill',
                'currency_code' => config('pos.currency', 'QAR'),
                'recurring_template_id' => $template->id,
                'invoice_number' => sprintf('RCB-%d-%s', $template->id, Carbon::parse($date)->format('Ymd')),
                'invoice_date' => $date,
                'due_date' => Carbon::parse($date)->addDays((int) ($template->due_day_offset ?? 30))->toDateString(),
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'status' => 'draft',
                'notes' => $template->notes,
                'created_by' => $actorId,
            ]);

            foreach ($template->lines as $line) {
                ApInvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'purchase_order_item_id' => $line->purchase_order_item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => round((float) $line->quantity * (float) $line->unit_price, 2),
                ]);
            }

            $this->totalsService->recalc($invoice);

            $template->update([
                'last_run_date' => $date,
                'next_run_date' => $this->nextRunDate($template, $date),
            ]);

            $this->auditLog->log('recurring_bill.generated.'.$generationSource, $actorId, $invoice, [
                'template_id' => (int) $template->id,
                'generation_source' => $generationSource,
            ], (int) $template->company_id);

            return $invoice->fresh(['items', 'supplier']);
        });
    }

    /**
     * @return Collection<int, ApInvoice>
     */
    public function generateDueTemplates(?string $asOfDate = null, ?int $actorId = null, string $generationSource = 'scheduled'): Collection
    {
        $date = Carbon::parse($asOfDate ?: now()->toDateString())->toDateString();

        return RecurringBillTemplate::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $date)
            ->orderBy('next_run_date')
            ->get()
            ->map(fn (RecurringBillTemplate $template) => $this->generateTemplate($template, optional($template->next_run_date)->toDateString() ?: $date, $actorId, $generationSource));
    }

    private function nextRunDate(RecurringBillTemplate $template, string $runDate): string
    {
        $date = Carbon::parse($runDate);

        return match ($template->frequency) {
            'weekly' => $date->addWeek()->toDateString(),
            'quarterly' => $date->addMonthsNoOverflow(3)->toDateString(),
            'annual', 'yearly' => $date->addYear()->toDateString(),
            default => $date->addMonthNoOverflow()->toDateString(),
        };
    }
}
