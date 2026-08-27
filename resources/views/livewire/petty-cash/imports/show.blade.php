<?php

use App\Models\ExpenseCategory;
use App\Models\BankAccount;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\Accounting\AccountingContextService;
use App\Livewire\Concerns\InteractsWithPettyCashImportReview;
use App\Services\PettyCash\PettyCashImportService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use InteractsWithPettyCashImportReview, WithPagination;

    public int $batchId;

    public function mount(int $batch): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && auth()->user()?->can('petty_cash.import'), 403);
        $this->batchId = $batch;
        $this->batch();
        $this->syncForms();
    }

    public function commitImport(PettyCashImportService $service): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && auth()->user()?->can('petty_cash.import'), 403);
        $batch = $this->batch();
        abort_unless($this->statusValue($batch) === 'ready', 422);

        try {
            $service->commit($batch, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('commit', collect($exception->errors())->flatten()->first() ?: __('The import could not be committed.'));

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('commit', __('The import could not be committed. No documents were changed.'));

            return;
        }
        session()->flash('status', __('Expense import committed. The invoices and settlement results are now available in Accounts Payable.'));
        $this->redirectRoute('petty-cash.imports.show', ['batch' => $batch->id], navigate: true);
    }

    public function with(): array
    {
        $batch = $this->batch();
        $usesBank = ($batch->funding_source ?? 'petty_cash') === 'bank_account';
        $bankAccount = $usesBank
            ? BankAccount::query()->find($batch->default_bank_account_id)
            : null;
        $rows = $batch->rows()->orderBy('row_number')->paginate(30);

        $stagedInvoices = $batch->invoices()->with(['targetInvoice', 'rows'])->orderBy('business_date')->orderBy('id')->get();
        $headers = $stagedInvoices->pluck('header');
        $suppliers = Supplier::query()->whereIn('id', $headers->pluck('supplier_id')->filter()->unique())->pluck('name', 'id');
        $categories = ExpenseCategory::query()->whereIn('id', $headers->pluck('category_id')->filter()->unique())->pluck('name', 'id');
        $wallets = PettyCashWallet::query()->whereIn('id', $headers->pluck('wallet_id')->filter()->unique())->get()->keyBy('id');
        $importInvoices = $stagedInvoices->map(function ($invoice) use ($suppliers, $categories, $wallets, $usesBank, $bankAccount): array {
            $header = $invoice->header ?? [];
            $subtotal = round((float) $invoice->rows->where('excluded', false)->sum(fn ($row): float => round(
                (float) ($row->payload['quantity'] ?? 0) * (float) ($row->payload['unit_price'] ?? 0),
                2
            )), 2);
            $wallet = $wallets->get((int) ($header['wallet_id'] ?? 0));

            return [
                'model' => $invoice,
                'supplier' => $suppliers->get((int) ($header['supplier_id'] ?? 0), __('Unknown supplier')),
                'category' => $categories->get((int) ($header['category_id'] ?? 0), $header['category'] ?? $header['category_name'] ?? __('Unknown category')),
                'wallet' => $wallet?->driver_name ?: ($wallet ? __('Custodian :id', ['id' => $wallet->driver_id]) : __('Unknown wallet')),
                'payment_source' => $usesBank ? ($bankAccount?->name ?? __('Unknown bank account')) : ($wallet?->driver_name ?: __('Petty cash wallet')),
                'line_count' => $invoice->rows->where('excluded', false)->count(),
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ];
        });

        $importInvoices = $importInvoices->filter(function (array $entry): bool {
            $invoice = $entry['model'];
            $header = $invoice->header ?? [];
            $date = optional($invoice->business_date)->toDateString() ?: ($header['business_date'] ?? '');
            $status = $invoice->excluded ? 'excluded' : ($invoice->status->value ?? (string) $invoice->status);

            return ($this->filter_date === '' || $date === $this->filter_date)
                && ($this->filter_category === '' || str_contains(Str::lower($entry['category']), Str::lower($this->filter_category)))
                && ($this->filter_supplier === '' || (int) ($header['supplier_id'] ?? 0) === (int) $this->filter_supplier)
                && ($this->usesBankFunding() || $this->filter_wallet === '' || (int) ($header['wallet_id'] ?? 0) === (int) $this->filter_wallet)
                && ($this->filter_paid === '' || (bool) ($header['paid_requested'] ?? $header['paid'] ?? false) === ($this->filter_paid === '1'))
                && ($this->filter_status === '' || $status === $this->filter_status);
        })->values();

        $allSuppliers = Supplier::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('hold_status')->orWhere('hold_status', 'open'))
            ->where(fn ($query) => $query->where('company_id', $batch->company_id)->orWhereNull('company_id'))
            ->orderBy('name')
            ->get();
        $allCategories = ExpenseCategory::query()->where('active', true)->orderBy('name')->get();
        $allWallets = PettyCashWallet::query()->where('active', true)->orderBy('driver_name')->get();
        $categoryProposals = $batch->categoryProposals()->orderByRaw('source_code IS NULL, source_code')->orderBy('source_name')->get();

        return [
            'batch' => $batch,
            'importInvoices' => $importInvoices,
            'reviewRows' => $rows,
            'statusValue' => $this->statusValue($batch),
            'stats' => $batch->stats ?? [],
            'allSuppliers' => $allSuppliers,
            'allCategories' => $allCategories,
            'allWallets' => $allWallets,
            'bankAccount' => $bankAccount,
            'usesBank' => $usesBank,
            'categoryProposals' => $categoryProposals,
            'editable' => ($batch->import_mode ?? 'daily') === 'bulk' && in_array($this->statusValue($batch), ['needs_review', 'ready'], true),
        ];
    }

    private function batch(): PettyCashImportBatch
    {
        $companyId = app(AccountingContextService::class)->resolveCompanyId();
        abort_unless($companyId, 422, __('A default accounting company must be configured before viewing petty cash imports.'));

        return PettyCashImportBatch::query()
            ->where('company_id', $companyId)
            ->findOrFail($this->batchId);
    }

    public function statusValue(PettyCashImportBatch $batch): string
    {
        return is_object($batch->status) ? (string) $batch->status->value : (string) $batch->status;
    }

    /** @return array<int, array{label:string,value:string}> */
    public function reviewFields(array $payload): array
    {
        $order = [
            'supplier', 'reference_number', 'due_date', 'category', ...($this->usesBankFunding() ? [] : ['wallet']), 'paid',
            'description', 'quantity', 'unit_price', 'notes',
        ];

        return collect($order)
            ->filter(fn (string $field) => array_key_exists($field, $payload) && filled($payload[$field]))
            ->map(fn (string $field): array => [
                'label' => Str::headline($field),
                'value' => is_bool($payload[$field])
                    ? ($payload[$field] ? __('Yes') : __('No'))
                    : (is_scalar($payload[$field]) ? (string) $payload[$field] : json_encode($payload[$field])),
            ])
            ->values()
            ->all();
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-neutral-500">{{ __('Expense import review') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $batch->source_name ?: __('Import #:id', ['id' => $batch->id]) }}</h1>
            @if(($batch->import_mode ?? 'daily') === 'bulk')
                <p class="text-sm text-neutral-500">
                    {{ __('Multiple Dates: :from – :to', [
                        'from' => optional($batch->date_from)->format('d M Y') ?: ($batch->date_from ?: '—'),
                        'to' => optional($batch->date_to)->format('d M Y') ?: ($batch->date_to ?: '—'),
                    ]) }}
                </p>
            @else
                <p class="text-sm text-neutral-500">{{ __('Business date: :date', ['date' => optional($batch->business_date)->format('d M Y') ?: $batch->business_date]) }}</p>
            @endif
            <p class="text-sm text-neutral-500">
                {{ $usesBank
                    ? __('Paid from bank account: :account', ['account' => $bankAccount?->name ?? __('Unavailable')])
                    : __('Paid from petty cash wallets') }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('petty-cash.imports.index')" wire:navigate variant="ghost" icon="arrow-left">{{ __('All Imports') }}</flux:button>
            <flux:button :href="route('petty-cash.imports.index')" wire:navigate variant="ghost" icon="arrow-up-tray">{{ __('Upload Another') }}</flux:button>
            @if($editable)
                <flux:button type="button" wire:click="revalidateImport" wire:loading.attr="disabled" wire:target="revalidateImport" variant="ghost" icon="arrow-path">{{ __('Revalidate') }}</flux:button>
            @endif
            @if($statusValue === 'ready')
                <button
                    type="button"
                    wire:click="commitImport"
                    wire:loading.attr="disabled"
                    wire:target="commitImport"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-black/10 bg-[var(--color-accent)] px-4 text-sm font-medium text-[var(--color-accent-foreground)] shadow-sm hover:opacity-90 disabled:pointer-events-none disabled:opacity-60"
                >
                    <svg wire:loading.remove wire:target="commitImport" class="size-4 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm3.844-8.791a.75.75 0 0 0-1.188-.918l-3.7 4.79-1.649-1.833a.75.75 0 1 0-1.114 1.004l2.25 2.5a.75.75 0 0 0 1.15-.043l4.25-5.5Z" clip-rule="evenodd" />
                    </svg>
                    <span wire:loading.remove wire:target="commitImport">{{ __('Confirm and Commit') }}</span>
                    <span wire:loading wire:target="commitImport">{{ __('Committing…') }}</span>
                </button>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            <p class="font-medium">{{ __('The import could not be committed.') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach(array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($statusValue === 'ready' && filled($batch->failure_reason))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $batch->failure_reason }}
        </div>
    @endif

    @if(! $usesBank && (int) ($stats['unpaid_for_insufficient_balance'] ?? 0) > 0)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            {{ trans_choice(':count invoice was changed to pending because its wallet balance was insufficient.|:count invoices were changed to pending because their wallet balances were insufficient.', (int) $stats['unpaid_for_insufficient_balance'], ['count' => (int) $stats['unpaid_for_insufficient_balance']]) }}
        </div>
    @endif

    @if($statusValue === 'ready')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
            <p class="font-medium">{{ $usesBank ? __('Validation passed. No accounting or bank records have changed yet.') : __('Validation passed. No accounting or wallet records have changed yet.') }}</p>
            <p class="mt-1">{{ __('Review the staged rows below. Committing creates every invoice and applies only the settlements marked as paid.') }}</p>
        </div>
    @elseif($statusValue === 'needs_review')
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <p class="font-medium">{{ __('This import needs review before it can be committed.') }}</p>
            <p class="mt-1">{{ __('Use the filters and editable invoice cards below to correct the flagged values. Each save revalidates the complete batch.') }}</p>
        </div>
    @elseif($statusValue === 'failed')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">
            <p class="font-medium">{{ __('This workbook cannot be committed because validation errors were found.') }}</p>
            <p class="mt-1">{{ $batch->failure_reason ?: __('Correct the rows shown below and upload a new workbook.') }}</p>
        </div>
    @elseif($statusValue === 'completed')
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100">
            <p class="font-medium">{{ __('This import has been committed.') }}</p>
            <p class="mt-1">{{ __('The staged workbook remains available as a read-only audit record.') }}</p>
        </div>
    @elseif($statusValue === 'committing')
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <p class="font-medium">{{ __('This import is currently being committed.') }}</p>
            <p class="mt-1">{{ __('Do not upload the workbook again. Refresh this page to see the final result.') }}</p>
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach([
            __('Rows') => $stats['rows'] ?? $reviewRows->total(),
            __('Invoice Groups') => $stats['invoices'] ?? 0,
            __('Valid Invoices') => $stats['valid_invoices'] ?? 0,
            __('Invalid Invoices') => $stats['invalid_invoices'] ?? 0,
            __('Paid Invoices') => $importInvoices->filter(fn ($entry) => (bool) (($entry['model']->header ?? [])['paid'] ?? false))->count(),
        ] as $label => $value)
            <article class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs text-neutral-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ $value }}</p>
            </article>
        @endforeach
    </section>

    @if(($batch->import_mode ?? 'daily') === 'bulk')
        @include('livewire.petty-cash.imports.partials.category-proposals')

        <section class="space-y-4 rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Review Filters and Overrides') }}</h2>
                <p class="text-sm text-neutral-500">{{ __('Filters control the invoice cards below and which invoices receive a bulk override.') }}</p>
            </div>
            <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                <flux:input wire:model.live.debounce.300ms="filter_date" type="date" :label="__('Date')" />
                <flux:input wire:model.live.debounce.300ms="filter_category" :label="__('Category')" :placeholder="__('Any category')" />
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Supplier') }}</label>
                    <select wire:model.live="filter_supplier" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <option value="">{{ __('All suppliers') }}</option>
                        @foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                    </select>
                </div>
                @unless($usesBank)
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('Wallet') }}</label>
                        <select wire:model.live="filter_wallet" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <option value="">{{ __('All wallets') }}</option>
                            @foreach($allWallets as $wallet)<option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id]) }}</option>@endforeach
                        </select>
                    </div>
                @endunless
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Paid State') }}</label>
                    <select wire:model.live="filter_paid" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <option value="">{{ __('All states') }}</option><option value="1">{{ __('Paid requested') }}</option><option value="0">{{ __('Unpaid') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Validation Status') }}</label>
                    <select wire:model.live="filter_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <option value="">{{ __('All statuses') }}</option><option value="valid">{{ __('Valid') }}</option><option value="invalid">{{ __('Needs Correction') }}</option><option value="excluded">{{ __('Excluded') }}</option>
                    </select>
                </div>
            </div>

            @if($editable)
                <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <h3 class="font-medium">{{ __('Apply to Filtered Invoices') }}</h3>
                    <div class="mt-3 grid gap-3 md:grid-cols-4">
                        <select wire:model="bulk_supplier_id" aria-label="{{ __('Override supplier') }}" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <option value="">{{ __('Keep supplier') }}</option>@foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                        </select>
                        @unless($usesBank)
                            <select wire:model="bulk_wallet_id" aria-label="{{ __('Override wallet') }}" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                <option value="">{{ __('Keep wallet') }}</option>@foreach($allWallets as $wallet)<option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id]) }}</option>@endforeach
                            </select>
                        @endunless
                        <select wire:model="bulk_paid" aria-label="{{ __('Override paid state') }}" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <option value="">{{ __('Keep paid state') }}</option><option value="1">{{ __('Set paid') }}</option><option value="0">{{ __('Set unpaid') }}</option>
                        </select>
                        <flux:button type="button" wire:click="applyBulkOverride" variant="primary">{{ __('Apply Overrides') }}</flux:button>
                    </div>
                    @error('bulk_override') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <details class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <summary class="cursor-pointer font-medium">{{ __('Add Staged Expense') }}</summary>
                    <form wire:submit="addInvoice" class="mt-4 space-y-4">
                        <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
                            <flux:input wire:model="newInvoice.entry_id" :label="__('Entry ID')" />
                            <flux:input wire:model="newInvoice.business_date" type="date" :label="__('Business Date')" />
                            <div><label class="mb-1 block text-sm font-medium">{{ __('Supplier') }}</label><select wire:model="newInvoice.supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="">{{ __('Choose supplier') }}</option>@foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
                            <flux:input wire:model="newInvoice.category" :label="__('Category')" list="expense-category-names" />
                            @unless($usesBank)<div><label class="mb-1 block text-sm font-medium">{{ __('Wallet') }}</label><select wire:model="newInvoice.wallet_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="">{{ __('No wallet') }}</option>@foreach($allWallets as $wallet)<option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id]) }}</option>@endforeach</select></div>@endunless
                            <flux:input wire:model="newInvoice.reference_number" :label="__('Reference')" />
                            <flux:input wire:model="newInvoice.due_date" type="date" :label="__('Due Date')" />
                            <div><label class="mb-1 block text-sm font-medium">{{ __('Paid') }}</label><select wire:model="newInvoice.paid" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="0">{{ __('Unpaid') }}</option><option value="1">{{ __('Paid') }}</option></select></div>
                            <flux:input wire:model="newInvoice.description" :label="__('Line Description')" />
                            <flux:input wire:model="newInvoice.quantity" type="number" step="0.0001" min="0.0001" :label="__('Quantity')" />
                            <flux:input wire:model="newInvoice.unit_price" type="number" step="0.0001" min="0" :label="__('Unit Price')" />
                            <flux:input wire:model="newInvoice.notes" :label="__('Notes')" />
                        </div>
                        <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="plus">{{ __('Add Expense') }}</flux:button></div>
                    </form>
                </details>
                <datalist id="expense-category-names">@foreach($allCategories as $category)<option value="{{ $category->name }}"></option>@endforeach</datalist>
            @endif
        </section>
    @endif

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold">{{ __('Invoice Group Summary') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Every entry ID becomes one AP invoice after a successful commit.') }}</p>
        </div>
        <div @class(['grid gap-3', 'md:grid-cols-2 xl:grid-cols-3' => ($batch->import_mode ?? 'daily') !== 'bulk'])>
            @foreach($importInvoices as $entry)
                @php($importInvoice = $entry['model'])
                @php($header = $importInvoice->header ?? [])
                @php($groupErrors = $importInvoice->errors ?? [])
                @if(($batch->import_mode ?? 'daily') === 'bulk')
                    @include('livewire.petty-cash.imports.partials.bulk-invoice-card')
                    @continue
                @endif
                <article @class([
                    'rounded-lg border bg-white p-4 dark:bg-neutral-900',
                    'border-red-300 dark:border-red-800' => $groupErrors !== [],
                    'border-neutral-200 dark:border-neutral-700' => $groupErrors === [],
                ])>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ $importInvoice->entry_id }}</p>
                            <p class="text-xs text-neutral-500">{{ ($header['reference_number'] ?? null) ?: __('No supplier reference') }}</p>
                        </div>
                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">{{ Str::headline($importInvoice->status->value ?? (string) $importInvoice->status) }}</span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-xs text-neutral-500">{{ __('Supplier') }}</dt><dd>{{ $entry['supplier'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Due Date') }}</dt><dd>{{ $header['due_date'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Category') }}</dt><dd>{{ $entry['category'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ $usesBank ? __('Bank Account') : __('Wallet') }}</dt><dd>{{ $entry['payment_source'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Settlement') }}</dt><dd>{{ ($header['paid'] ?? false) ? __('Paid') : __('Pending') }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Lines') }}</dt><dd>{{ $entry['line_count'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Subtotal') }}</dt><dd>{{ number_format($entry['subtotal'], 2) }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Total') }}</dt><dd class="font-medium">{{ number_format($entry['total'], 2) }}</dd></div>
                    </dl>
                    @if(filled($header['settlement_warning'] ?? null))
                        <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                            {{ $header['settlement_warning'] }}
                        </p>
                    @endif
                    @if($importInvoice->targetInvoice)
                        <flux:button class="mt-3" :href="route('payables.invoices.show', $importInvoice->targetInvoice)" wire:navigate size="sm" variant="ghost">{{ __('Open AP Invoice') }}</flux:button>
                    @endif
                    @if($groupErrors !== [])
                        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-red-700 dark:text-red-300">
                            @foreach($groupErrors as $message)<li>{{ is_array($message) ? implode(' ', $message) : $message }}</li>@endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    @if(($batch->import_mode ?? 'daily') !== 'bulk')
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">{{ __('Staged Row Review') }}</h2>
                <p class="text-sm text-neutral-500">{{ __('Rows sharing an entry ID will become line items on the same supplier invoice.') }}</p>
            </div>
            <p class="text-sm text-neutral-500">{{ trans_choice(':count row|:count rows', $reviewRows->total(), ['count' => $reviewRows->total()]) }}</p>
        </div>

        @forelse($reviewRows as $row)
            @php($payload = $row->payload ?? [])
            @php($rowErrors = $row->errors ?? [])
            <article @class([
                'rounded-lg border bg-white p-5 dark:bg-neutral-900',
                'border-red-300 dark:border-red-800' => $rowErrors !== [],
                'border-neutral-200 dark:border-neutral-700' => $rowErrors === [],
            ])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ __('Entry :entry · Excel row :row', [
                            'entry' => ($payload['entry_id'] ?? $row->source_identifier) ?: '—',
                            'row' => $payload['_sheet_row'] ?? $row->row_number,
                        ]) }}</p>
                        <p class="text-xs text-neutral-500">{{ $payload['reference_number'] ?? __('No reference supplied') }}</p>
                    </div>
                    <span @class([
                        'rounded-full px-2 py-1 text-xs',
                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => $rowErrors !== [],
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $rowErrors === [],
                    ])>{{ $rowErrors === [] ? __('Valid') : __('Needs Correction') }}</span>
                </div>

                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($this->reviewFields($payload) as $field)
                        <div class="min-w-0"><dt class="text-xs text-neutral-500">{{ $field['label'] }}</dt><dd class="break-words">{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>

                @if($rowErrors !== [])
                    <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">
                        <p class="font-medium">{{ __('Validation Errors') }}</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach($rowErrors as $field => $messages)
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
    @endif

</div>
