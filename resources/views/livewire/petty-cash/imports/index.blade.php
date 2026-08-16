<?php

use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashWallet;
use App\Services\Accounting\AccountingContextService;
use App\Services\PettyCash\PettyCashImportService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads, WithPagination;

    public $workbook;

    public string $business_date = '';

    public ?int $default_category_id = null;

    public ?int $default_wallet_id = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && auth()->user()?->can('petty_cash.import'), 403);
        $this->business_date = now()->toDateString();
    }

    public function stage(PettyCashImportService $service, AccountingContextService $context): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && auth()->user()?->can('petty_cash.import'), 403);

        $data = $this->validate([
            'business_date' => ['required', 'date'],
            'default_category_id' => [
                'nullable',
                'integer',
                Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'default_wallet_id' => [
                'nullable',
                'integer',
                Rule::exists('petty_cash_wallets', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'workbook' => ['required', 'file', 'mimes:xlsx', 'max:'.(int) config('petty_cash.imports.max_upload_kb', 10_240)],
        ]);

        $companyId = $context->resolveCompanyId();
        if (! $companyId) {
            throw ValidationException::withMessages([
                'workbook' => __('A default accounting company must be configured before importing petty cash expenses.'),
            ]);
        }

        $batch = $service->stage(
            $this->workbook,
            (string) $data['business_date'],
            isset($data['default_category_id']) ? (int) $data['default_category_id'] : null,
            isset($data['default_wallet_id']) ? (int) $data['default_wallet_id'] : null,
            (int) $companyId,
            auth()->user(),
        );

        $this->reset('workbook');
        $this->resetValidation();
        session()->flash('status', __('Workbook staged. Review every invoice group before committing.'));
        $this->redirectRoute('petty-cash.imports.show', ['batch' => $batch->id], navigate: true);
    }

    public function with(AccountingContextService $context): array
    {
        $companyId = $context->resolveCompanyId();
        abort_unless($companyId, 422, __('A default accounting company must be configured before viewing petty cash imports.'));

        return [
            'categories' => ExpenseCategory::query()->where('active', true)->orderBy('name')->get(),
            'wallets' => PettyCashWallet::query()->where('active', true)->orderBy('driver_name')->get(),
            'batches' => PettyCashImportBatch::query()
                ->where('company_id', $companyId)
                ->withCount([
                    'rows',
                    'rows as invalid_rows_count' => fn ($query) => $query->where('status', 'invalid'),
                ])
                ->latest('id')
                ->paginate(15),
        ];
    }

    public function statusValue(PettyCashImportBatch $batch): string
    {
        return is_object($batch->status) ? (string) $batch->status->value : (string) $batch->status;
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-neutral-500">{{ __('Petty Cash') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Expense Imports') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Validate a one-day Excel workbook before creating invoices or changing a wallet balance.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('petty-cash.imports.template')" variant="ghost" icon="arrow-down-tray">
                {{ __('Download Template') }}
            </flux:button>
            <flux:button :href="route('petty-cash.index')" wire:navigate variant="ghost" icon="arrow-left">
                {{ __('Back to Petty Cash') }}
            </flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            <p class="font-medium">{{ __('The workbook could not be staged.') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach(array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-lg border border-violet-200 bg-violet-50 p-5 dark:border-violet-900 dark:bg-violet-950/30">
        <h2 class="font-semibold text-violet-950 dark:text-violet-100">{{ __('Import one business day') }}</h2>
        <p class="mt-1 text-sm text-violet-800 dark:text-violet-200">{{ __('Use one color-coded four-line block per invoice. Enter supplier, category, wallet, and payment details once on the first row, then fill only the line-item columns below it.') }}</p>
        <ol class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>1.</strong> {{ __('Download template') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>2.</strong> {{ __('Enter daily expenses') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>3.</strong> {{ __('Upload and review') }}</li>
            <li class="rounded-md bg-white/70 px-3 py-2 dark:bg-neutral-900/40"><strong>4.</strong> {{ __('Confirm and commit') }}</li>
        </ol>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div>
            <h2 class="text-lg font-semibold">{{ __('Upload daily workbook') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Category and wallet defaults only fill blank spreadsheet cells. Explicit row values take precedence.') }}</p>
        </div>

        <form wire:submit="stage" class="mt-5 space-y-5">
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input wire:model="business_date" type="date" :label="__('Business Date')" />
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Default Category') }}</label>
                    <select wire:model="default_category_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('No default') }}</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('default_category_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Default Wallet') }}</label>
                    <select wire:model="default_wallet_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('No default') }}</option>
                        @foreach($wallets as $wallet)
                            <option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id]) }}</option>
                        @endforeach
                    </select>
                    @error('default_wallet_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <flux:input wire:model="workbook" type="file" accept=".xlsx" :label="__('Excel Workbook')" />
            @error('workbook') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            <p class="text-xs text-neutral-500">{{ __('XLSX only, maximum 10 MB. Formula cells, external links, macros, altered headers, and unsafe archives are rejected.') }}</p>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('petty-cash.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled">{{ __('Validate and Stage') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold">{{ __('Import History') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Previous uploads remain available as an audit trail, including failed validation attempts.') }}</p>
        </div>

        @forelse($batches as $batch)
            @php($status = $this->statusValue($batch))
            @php($stats = $batch->stats ?? [])
            <article class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ $batch->source_name ?: __('Petty cash import #:id', ['id' => $batch->id]) }}</p>
                        <p class="text-sm text-neutral-500">
                            {{ optional($batch->business_date)->format('d M Y') ?: $batch->business_date }} ·
                            {{ trans_choice(':count staged row|:count staged rows', $batch->rows_count, ['count' => $batch->rows_count]) }}
                        </p>
                        <p class="mt-1 text-xs text-neutral-500">
                            {{ __('Invoice groups: :entries · Valid: :valid · Errors: :errors', [
                                'entries' => $stats['invoices'] ?? 0,
                                'valid' => $stats['valid_invoices'] ?? 0,
                                'errors' => $stats['invalid_invoices'] ?? $batch->invalid_rows_count,
                            ]) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($status) }}</span>
                        <flux:button :href="route('petty-cash.imports.show', ['batch' => $batch->id])" wire:navigate size="sm" :variant="$status === 'ready' ? 'primary' : 'ghost'" icon="eye">
                            {{ __('Review') }}
                        </flux:button>
                    </div>
                </div>
            </article>
        @empty
            <p class="rounded-lg border border-neutral-200 bg-white px-5 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900">{{ __('No petty cash imports have been uploaded yet.') }}</p>
        @endforelse

        <div>{{ $batches->links() }}</div>
    </section>
</div>
