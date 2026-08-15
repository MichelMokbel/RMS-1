<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\HrImportBatch;
use App\Services\HR\HrImportService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads, WithPagination;

    public $manifest;

    public $archive;

    public ?int $company_id = null;

    public string $import_type = 'full_history';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.imports.manage'), 403);
        $this->company_id = $this->companyIds()->first();
    }

    public function stage(HrImportService $service): void
    {
        $data = $this->validate([
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'import_type' => ['required', 'in:full_history,employees,assignments,documents,leave,payroll'],
            'manifest' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'archive' => ['nullable', 'required_if:import_type,documents', 'prohibited_if:import_type,full_history', 'file', 'mimes:zip', 'max:102400'],
        ]);
        $batch = $service->stage(
            $this->manifest,
            $data['import_type'],
            (int) $data['company_id'],
            auth()->user(),
            $data['import_type'] === 'full_history' ? null : $this->archive,
        );
        $this->reset(['manifest', 'archive']);
        $this->resetValidation();
        $this->dispatch('modal-close', name: 'stage-hr-import');
        session()->flash('status', __('Import staged. Review row errors before committing.'));
        $this->redirectRoute('hr.imports.show', ['batch' => $batch->id], navigate: true);
    }

    public function updatedImportType(string $type): void
    {
        if ($type === 'full_history') {
            $this->reset('archive');
        }

        $this->resetValidation(['import_type', 'archive']);
    }

    public function with(): array
    {
        $ids = $this->companyIds();

        return [
            'companies' => AccountingCompany::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(),
            'batches' => HrImportBatch::query()
                ->whereIn('company_id', $ids)
                ->withCount(['rows', 'rows as invalid_rows_count' => fn ($query) => $query->where('status', 'invalid')])
                ->latest()
                ->paginate(15),
        ];
    }

    private function companyIds()
    {
        return auth()->user()?->hasRole('admin') ? AccountingCompany::query()->pluck('id') : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('HR imports') }}</h1>
            <p class="text-sm text-neutral-500">{{ __('Validate complete employee history before changing live records.') }}</p>
        </div>
        <flux:modal.trigger name="stage-hr-import">
            <flux:button type="button" icon="arrow-up-tray">{{ __('Upload Excel workbook') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @include('livewire.hr.partials.navigation')

    @if(session('status'))
        <div class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <section class="rounded-lg border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-blue-950 dark:text-blue-100">{{ __('Import complete employee history from Excel') }}</h2>
                <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">{{ __('Upload from this page. The workbook is validated and staged first, then opened on a separate review page before anything is committed.') }}</p>
            </div>
            <flux:modal.trigger name="stage-hr-import">
                <flux:button type="button" icon="arrow-up-tray">{{ __('Upload workbook') }}</flux:button>
            </flux:modal.trigger>
        </div>
        <ol class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>1.</strong> {{ __('Download template') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>2.</strong> {{ __('Upload and validate') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>3.</strong> {{ __('Review staged rows') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>4.</strong> {{ __('Confirm and commit') }}</li>
        </ol>
    </section>

    <section class="space-y-3">
        @forelse($batches as $batch)
            @php
                $status = $batch->status instanceof BackedEnum ? $batch->status->value : $batch->status;
                $type = $batch->type instanceof BackedEnum ? $batch->type->value : $batch->type;
                $stats = $batch->stats ?? [];
                $sheetStats = $stats['sheets'] ?? [];
            @endphp
            <article class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ $type === 'full_history' ? __('Full employee history') : Str::headline($type) }}</p>
                        <p class="text-sm text-neutral-500">
                            {{ $batch->source_name ?? __('Import batch') }} ·
                            {{ trans_choice(':count row|:count rows', $stats['rows'] ?? $batch->rows_count, ['count' => $stats['rows'] ?? $batch->rows_count]) }}
                        </p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ __('Valid: :valid · Errors: :errors', ['valid' => $stats['valid'] ?? 0, 'errors' => $stats['errors'] ?? $stats['invalid'] ?? 0]) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($status) }}</span>
                        <flux:button :href="route('hr.imports.show', ['batch' => $batch->id])" wire:navigate size="sm" :variant="$status === 'ready' ? 'primary' : 'ghost'" :icon="$batch->invalid_rows_count > 0 ? 'exclamation-triangle' : 'eye'">
                            {{ $status === 'completed' ? __('View import') : __('Review import') }}
                        </flux:button>
                    </div>
                </div>

                @if($sheetStats !== [])
                    <div class="mt-4 overflow-hidden rounded-md border border-neutral-200 dark:border-neutral-700">
                        <div class="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] gap-3 bg-neutral-50 px-3 py-2 text-xs font-medium text-neutral-500 dark:bg-neutral-800">
                            <span>{{ __('Sheet') }}</span><span>{{ __('Rows') }}</span><span>{{ __('Valid') }}</span><span>{{ __('Errors') }}</span>
                        </div>
                        @foreach($sheetStats as $sheet => $sheetCount)
                            <div class="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] gap-3 border-t border-neutral-100 px-3 py-2 text-sm dark:border-neutral-800">
                                <span class="truncate">{{ Str::headline($sheet) }}</span>
                                <span>{{ $sheetCount['rows'] ?? 0 }}</span>
                                <span class="text-emerald-700 dark:text-emerald-400">{{ $sheetCount['valid'] ?? 0 }}</span>
                                <span @class(['text-red-700 dark:text-red-400' => ($sheetCount['errors'] ?? $sheetCount['invalid'] ?? 0) > 0])>{{ $sheetCount['errors'] ?? $sheetCount['invalid'] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="rounded-lg border border-neutral-200 bg-white px-5 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900">{{ __('No staged imports.') }}</p>
        @endforelse

        <div>{{ $batches->links() }}</div>
    </section>

    <flux:modal name="stage-hr-import" :show="request()->boolean('upload') || $errors->has('company_id') || $errors->has('import_type') || $errors->has('manifest') || $errors->has('archive')" focusable class="max-w-3xl">
        <form wire:submit="stage" class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('Stage HR import') }}</flux:heading>
                    <flux:subheading>{{ __('Upload a workbook for validation. Nothing changes until a ready batch is committed.') }}</flux:subheading>
                </div>
                <flux:button :href="route('hr.imports.template', ['type' => $import_type, 'company_id' => $company_id])" size="sm" variant="ghost" icon="arrow-down-tray">{{ __('Download template') }}</flux:button>
            </div>

            <div @class(['rounded-lg border p-4', 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' => $import_type === 'full_history', 'border-neutral-200 dark:border-neutral-700' => $import_type !== 'full_history'])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-xl">
                        <div class="flex items-center gap-2"><p class="font-medium">{{ __('Full employee history') }}</p><span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900 dark:text-blue-200">{{ __('Recommended') }}</span></div>
                        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('One workbook covers employee profiles, assignments, compensation, leave balances and history, plus payroll and component history.') }}</p>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('Documents are excluded and should be uploaded to each employee profile after the history import.') }}</p>
                    </div>
                    <flux:button type="button" wire:click="$set('import_type', 'full_history')" :variant="$import_type === 'full_history' ? 'primary' : 'ghost'" size="sm">{{ $import_type === 'full_history' ? __('Selected') : __('Use full history') }}</flux:button>
                </div>
            </div>

            <details class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700" @if($import_type !== 'full_history') open @endif>
                <summary class="cursor-pointer text-sm font-medium">{{ __('Advanced: import one data type') }}</summary>
                <div class="mt-4">
                    <flux:select wire:model.live="import_type" :label="__('Single data type')">
                        <option value="full_history" disabled>{{ __('Choose a single data type') }}</option>
                        <option value="employees">{{ __('Employees only') }}</option>
                        <option value="assignments">{{ __('Assignment history') }}</option>
                        <option value="documents">{{ __('Documents with ZIP package') }}</option>
                        <option value="leave">{{ __('Leave history') }}</option>
                        <option value="payroll">{{ __('Historical payroll totals') }}</option>
                    </flux:select>
                </div>
            </details>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model.live="company_id" :label="__('Company')">
                    @foreach($companies as $row)<option value="{{ $row->id }}">{{ $row->name }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="manifest" type="file" accept=".xlsx" :label="__('XLSX workbook')" />
                @if($import_type !== 'full_history')
                    <div class="md:col-span-2">
                        <p class="mb-3 text-xs text-neutral-500">{{ $import_type === 'employees' ? __('Use legacy_employee_number only for a number assigned by the previous HR system. Canonical employee numbers are generated automatically.') : __('Employee number is a lookup reference to an existing employee. Internal payroll and batch numbers are generated automatically.') }}</p>
                        @if($import_type === 'documents' || $import_type === 'payroll')
                            <flux:input wire:model="archive" type="file" accept=".zip" :label="$import_type === 'documents' ? __('Document ZIP package') : __('Payslip ZIP package (optional)')" />
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Validate and stage') }}</flux:button>
            </div>
        </form>
    </flux:modal>

</div>
