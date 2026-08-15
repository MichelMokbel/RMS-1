<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\HrImportBatch;
use App\Services\HR\HrImportService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public int $batchId;

    public function mount(int $batch): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.imports.manage'), 403);
        $this->batchId = $batch;
        $this->batch();
    }

    public function commit(HrImportService $service): void
    {
        $batch = $this->batch();
        abort_unless($this->status($batch) === 'ready', 422);

        $service->commit($batch, auth()->user());
        $this->modal('commit-hr-import')->close();
        session()->flash('status', __('Import committed successfully. The staged review remains available as an audit record.'));
    }

    public function with(): array
    {
        $batch = $this->batch()->load(['company', 'initiator', 'committer']);
        $rows = $batch->rows()->orderBy('row_number')->paginate(30);
        $rows->through(function ($row) use ($batch): array {
            $payload = $row->payload ?? [];
            $sheet = (string) ($payload['_sheet'] ?? $this->type($batch));

            return [
                'model' => $row,
                'sheet' => $sheet,
                'sheet_row' => (int) ($payload['_sheet_row'] ?? $row->row_number),
                'fields' => $this->reviewFields($sheet, $payload),
                'errors' => $row->errors ?? [],
            ];
        });

        return [
            'batch' => $batch,
            'reviewRows' => $rows,
            'statusValue' => $this->status($batch),
            'typeValue' => $this->type($batch),
            'sheetStats' => ($batch->stats ?? [])['sheets'] ?? [],
        ];
    }

    private function batch(): HrImportBatch
    {
        return HrImportBatch::query()
            ->whereIn('company_id', $this->companyIds())
            ->findOrFail($this->batchId);
    }

    private function companyIds()
    {
        $user = auth()->user();

        return $user?->hasRole('admin')
            ? AccountingCompany::query()->pluck('id')
            : Branch::query()->whereIn('id', $user?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
    }

    /** @return array<int, array{label:string,value:string}> */
    private function reviewFields(string $sheet, array $payload): array
    {
        $payrollSheets = ['compensation', 'compensation_components', 'payroll_history', 'payroll_components'];
        $canViewPayroll = auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.view');
        $references = ['employee_ref', 'package_ref', 'payroll_ref'];

        if (in_array($sheet, $payrollSheets, true) && ! $canViewPayroll) {
            $visible = collect($payload)->only($references)->filter(fn ($value) => filled($value))->all();
            $visible['restricted_details'] = __('Payroll values are restricted to users with payroll access.');
        } else {
            $visible = collect($payload)
                ->reject(fn ($value, $field) => str_starts_with((string) $field, '_') || ! filled($value))
                ->all();
        }

        return collect($visible)->map(function ($value, $field): array {
            $sensitive = in_array((string) $field, ['qid_number', 'passport_number', 'bank_account_number', 'iban', 'swift_code'], true);

            return [
                'label' => Str::headline((string) $field),
                'value' => $sensitive ? $this->mask((string) $value) : (is_scalar($value) ? (string) $value : json_encode($value)),
            ];
        })->values()->all();
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= 4) {
            return str_repeat('•', mb_strlen($value));
        }

        return str_repeat('•', min(max(mb_strlen($value) - 4, 4), 12)).mb_substr($value, -4);
    }

    private function status(HrImportBatch $batch): string
    {
        return is_object($batch->status) ? (string) $batch->status->value : (string) $batch->status;
    }

    private function type(HrImportBatch $batch): string
    {
        return is_object($batch->type) ? (string) $batch->type->value : (string) $batch->type;
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-neutral-500">{{ __('HR import review') }}</p>
            <h1 class="text-2xl font-semibold">{{ $typeValue === 'full_history' ? __('Full employee history') : Str::headline($typeValue) }}</h1>
            <p class="text-sm text-neutral-500">{{ $batch->source_name }} · {{ $batch->company?->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('hr.imports.index')" wire:navigate variant="ghost" icon="arrow-left">{{ __('All imports') }}</flux:button>
            <flux:button :href="route('hr.imports.index', ['upload' => 1])" wire:navigate variant="ghost" icon="arrow-up-tray">{{ __('Upload another workbook') }}</flux:button>
            @if($statusValue === 'ready')
                <flux:modal.trigger name="commit-hr-import">
                    <flux:button type="button" variant="primary" icon="check-circle">{{ __('Confirm and commit') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @include('livewire.hr.partials.navigation')

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    @if($statusValue === 'ready')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
            <p class="font-medium">{{ __('Validation passed. No live HR records have been changed yet.') }}</p>
            <p class="mt-1">{{ __('Review the sheet totals and staged rows below. Use Confirm and commit only when the workbook is correct.') }}</p>
        </div>
    @elseif($statusValue === 'failed')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">
            <p class="font-medium">{{ __('This workbook cannot be committed because validation errors were found.') }}</p>
            <p class="mt-1">{{ $batch->failure_reason ?: __('Review the row errors below, correct the Excel workbook, and upload it again.') }}</p>
            <flux:button class="mt-3" :href="route('hr.imports.index', ['upload' => 1])" wire:navigate size="sm" variant="danger">{{ __('Upload corrected workbook') }}</flux:button>
        </div>
    @elseif($statusValue === 'completed')
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100">
            <p class="font-medium">{{ __('This import has been committed.') }}</p>
            <p class="mt-1">{{ __('Committed :date by :user.', ['date' => $batch->committed_at?->format('d M Y H:i') ?? '—', 'user' => $batch->committer?->name ?? __('Unknown user')]) }}</p>
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @forelse($sheetStats as $sheet => $counts)
            <article class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-sm font-medium">{{ Str::headline($sheet) }}</p>
                <div class="mt-2 flex items-center gap-3 text-xs text-neutral-500">
                    <span>{{ trans_choice(':count row|:count rows', $counts['rows'] ?? 0, ['count' => $counts['rows'] ?? 0]) }}</span>
                    <span class="text-emerald-700 dark:text-emerald-400">{{ __(':count valid', ['count' => $counts['valid'] ?? 0]) }}</span>
                    <span @class(['text-red-700 dark:text-red-400' => ($counts['errors'] ?? 0) > 0])>{{ __(':count errors', ['count' => $counts['errors'] ?? 0]) }}</span>
                </div>
            </article>
        @empty
            <article class="rounded-lg border border-neutral-200 bg-white p-4 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900">{{ __('No per-sheet statistics are available for this import type.') }}</article>
        @endforelse
    </section>

    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Staged row review') }}</h2>
                <p class="text-sm text-neutral-500">{{ __('Sensitive identifiers and bank references are masked. Payroll values require payroll-view permission.') }}</p>
            </div>
            <p class="text-sm text-neutral-500">{{ trans_choice(':count staged row|:count staged rows', $reviewRows->total(), ['count' => $reviewRows->total()]) }}</p>
        </div>

        @forelse($reviewRows as $entry)
            @php($row = $entry['model'])
            <article @class([
                'rounded-lg border bg-white p-5 dark:bg-neutral-900',
                'border-red-300 dark:border-red-800' => $entry['errors'] !== [],
                'border-neutral-200 dark:border-neutral-700' => $entry['errors'] === [],
            ])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ Str::headline($entry['sheet']) }} · {{ __('Row :row', ['row' => $entry['sheet_row']]) }}</p>
                        <p class="text-xs text-neutral-500">{{ $row->source_identifier ?: __('No natural key available') }}</p>
                    </div>
                    <span @class([
                        'rounded-full px-2 py-1 text-xs',
                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => $entry['errors'] !== [],
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $entry['errors'] === [],
                    ])>{{ $entry['errors'] === [] ? __('Valid') : __('Needs correction') }}</span>
                </div>

                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($entry['fields'] as $field)
                        <div class="min-w-0"><dt class="text-xs text-neutral-500">{{ $field['label'] }}</dt><dd class="break-words">{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>

                @if($entry['errors'] !== [])
                    <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">
                        <p class="font-medium">{{ __('Validation errors') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach($entry['errors'] as $field => $messages)
                                @foreach((array) $messages as $message)
                                    <li><strong>{{ Str::headline((string) $field) }}:</strong> {{ $message }}</li>
                                @endforeach
                            @endforeach
                        </ul>
                    </div>
                @endif
            </article>
        @empty
            <p class="rounded-lg border border-neutral-200 bg-white px-5 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900">{{ __('No staged rows were found.') }}</p>
        @endforelse

        <div>{{ $reviewRows->links() }}</div>
    </section>

    <flux:modal name="commit-hr-import" focusable class="max-w-xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Commit this HR import?') }}</flux:heading>
                <flux:subheading>{{ __('This is the final step. Validated employees, assignments, compensation, leave, and payroll history will be written together.') }}</flux:subheading>
            </div>
            <div class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                {{ __('The commit is atomic: if any row fails, none of the workbook will be applied. Committed historical payroll remains read-only and non-postable.') }}
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="button" wire:click="commit" wire:loading.attr="disabled" variant="primary">{{ __('Commit import') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
