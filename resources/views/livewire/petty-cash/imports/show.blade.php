<?php

use App\Models\PettyCashImportBatch;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\Accounting\AccountingContextService;
use App\Services\PettyCash\PettyCashImportService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $batchId;

    public function mount(int $batch): void
    {
        abort_unless(auth()->user()?->hasRole('admin') && auth()->user()?->can('petty_cash.import'), 403);
        $this->batchId = $batch;
        $this->batch();
    }

    public function commit(PettyCashImportService $service): void
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
        $this->modal('commit-petty-cash-import')->close();
        session()->flash('status', __('Petty cash import committed. The invoices and settlement results are now available in Accounts Payable.'));
    }

    public function with(): array
    {
        $batch = $this->batch();
        $rows = $batch->rows()->orderBy('row_number')->paginate(30);

        $stagedInvoices = $batch->invoices()->with(['targetInvoice', 'rows'])->orderBy('id')->get();
        $headers = $stagedInvoices->pluck('header');
        $suppliers = Supplier::query()->whereIn('id', $headers->pluck('supplier_id')->filter()->unique())->pluck('name', 'id');
        $categories = ExpenseCategory::query()->whereIn('id', $headers->pluck('category_id')->filter()->unique())->pluck('name', 'id');
        $wallets = PettyCashWallet::query()->whereIn('id', $headers->pluck('wallet_id')->filter()->unique())->get()->keyBy('id');
        $importInvoices = $stagedInvoices->map(function ($invoice) use ($suppliers, $categories, $wallets): array {
            $header = $invoice->header ?? [];
            $subtotal = round((float) $invoice->rows->sum(fn ($row): float => round(
                (float) ($row->payload['quantity'] ?? 0) * (float) ($row->payload['unit_price'] ?? 0),
                2
            )), 2);
            $tax = round((float) ($header['tax_amount'] ?? 0), 2);
            $wallet = $wallets->get((int) ($header['wallet_id'] ?? 0));

            return [
                'model' => $invoice,
                'supplier' => $suppliers->get((int) ($header['supplier_id'] ?? 0), __('Unknown supplier')),
                'category' => $categories->get((int) ($header['category_id'] ?? 0), __('Unknown category')),
                'wallet' => $wallet?->driver_name ?: ($wallet ? __('Custodian :id', ['id' => $wallet->driver_id]) : __('Unknown wallet')),
                'line_count' => $invoice->rows->count(),
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => round($subtotal + $tax, 2),
            ];
        });

        return [
            'batch' => $batch,
            'importInvoices' => $importInvoices,
            'reviewRows' => $rows,
            'statusValue' => $this->statusValue($batch),
            'stats' => $batch->stats ?? [],
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
            'supplier', 'reference_number', 'due_date', 'category', 'wallet', 'paid',
            'description', 'quantity', 'unit_price', 'tax_amount', 'notes',
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
            <p class="text-sm text-neutral-500">{{ __('Petty cash import review') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $batch->source_name ?: __('Import #:id', ['id' => $batch->id]) }}</h1>
            <p class="text-sm text-neutral-500">{{ __('Business date: :date', ['date' => optional($batch->business_date)->format('d M Y') ?: $batch->business_date]) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('petty-cash.imports.index')" wire:navigate variant="ghost" icon="arrow-left">{{ __('All Imports') }}</flux:button>
            <flux:button :href="route('petty-cash.imports.index')" wire:navigate variant="ghost" icon="arrow-up-tray">{{ __('Upload Another') }}</flux:button>
            @if($statusValue === 'ready')
                <flux:modal.trigger name="commit-petty-cash-import">
                    <flux:button type="button" variant="primary" icon="check-circle">{{ __('Confirm and Commit') }}</flux:button>
                </flux:modal.trigger>
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

    @if($statusValue === 'ready')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
            <p class="font-medium">{{ __('Validation passed. No accounting or wallet records have changed yet.') }}</p>
            <p class="mt-1">{{ __('Review the staged rows below. Committing creates every invoice and applies only the settlements marked as paid.') }}</p>
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

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold">{{ __('Invoice Group Summary') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Every entry ID becomes one AP invoice after a successful commit.') }}</p>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach($importInvoices as $entry)
                @php($importInvoice = $entry['model'])
                @php($header = $importInvoice->header ?? [])
                @php($groupErrors = $importInvoice->errors ?? [])
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
                        <div><dt class="text-xs text-neutral-500">{{ __('Wallet') }}</dt><dd>{{ $entry['wallet'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Settlement') }}</dt><dd>{{ ($header['paid'] ?? false) ? __('Paid') : __('Pending') }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Lines') }}</dt><dd>{{ $entry['line_count'] }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Subtotal') }}</dt><dd>{{ number_format($entry['subtotal'], 2) }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Tax') }}</dt><dd>{{ number_format($entry['tax'], 2) }}</dd></div>
                        <div><dt class="text-xs text-neutral-500">{{ __('Total') }}</dt><dd class="font-medium">{{ number_format($entry['total'], 2) }}</dd></div>
                    </dl>
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

    <flux:modal name="commit-petty-cash-import" focusable class="max-w-xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Commit this petty cash import?') }}</flux:heading>
                <flux:subheading>{{ __('This creates the validated invoices, posts them, and settles every group marked as paid.') }}</flux:subheading>
            </div>
            <div class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                {{ __('The commit is atomic. If any invoice, ledger entry, payment, or wallet deduction fails, the entire batch is rolled back.') }}
            </div>
            @error('commit') <p class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="button" wire:click="commit" wire:loading.attr="disabled" variant="primary">{{ __('Commit Import') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
