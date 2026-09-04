<?php

use App\Models\ArClearingSettlement;
use App\Models\BankAccount;
use App\Models\GatewaySettlementImport;
use App\Models\PaymentSource;
use App\Services\Accounting\AccountingContextService;
use App\Services\AR\ArClearingSettlementService;
use App\Services\Payments\GatewaySettlementDuplicateImportException;
use App\Services\Payments\GatewaySettlementImportService;
use App\Services\Reports\UnsettledIncomingReceiptsReportService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'pending';

    public string $method = 'cheque';

    public array $selected = [];

    public ?string $settlement_date = null;

    public ?int $bank_account_id = null;

    public ?string $date_from = null;

    public ?string $date_to = null;

    public bool $confirm_settle = false;

    public string $reference = '';

    public string $notes = '';

    public $settlement_workbook = null;

    public ?int $payment_source_id = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->settlement_date = now()->toDateString();
        if (Auth::user()?->can('gateway_settlements.import')) {
            $companyId = app(AccountingContextService::class)->defaultCompanyId();
            $this->payment_source_id = PaymentSource::query()
                ->where('company_id', $companyId)
                ->where('method', 'skipcash')
                ->value('id');
        }
    }

    public function updating($field): void
    {
        if (in_array($field, ['method', 'date_from', 'date_to', 'tab'], true)) {
            $this->resetPage();
            $this->resetPage('gatewayImportsPage');
            $this->selected = [];
        }
    }

    public function bankAccounts(): Collection
    {
        return BankAccount::where('company_id', app(AccountingContextService::class)->defaultCompanyId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function summary(): array
    {
        return app(UnsettledIncomingReceiptsReportService::class)->summary(
            app(AccountingContextService::class)->defaultCompanyId(),
            now()->toDateString(),
            $this->visibleBranchIds(),
        );
    }

    public function with(): array
    {
        return [
            'pending' => $this->pendingQuery()->paginate(20),
            'settlements' => $this->settlementsQuery()->paginate(20),
            'bankAccounts' => $this->bankAccounts(),
            'summary' => $this->summary(),
            'gatewayImports' => $this->gatewayImportsQuery()->paginate(20, ['*'], 'gatewayImportsPage'),
            'gatewaySources' => $this->gatewaySources(),
        ];
    }

    private function pendingQuery()
    {
        return app(UnsettledIncomingReceiptsReportService::class)
            ->query(
                app(AccountingContextService::class)->defaultCompanyId(),
                $this->method,
                $this->date_from,
                $this->date_to,
                $this->visibleBranchIds(),
            )
            ->with('customer');
    }

    private function settlementsQuery()
    {
        if ($this->method === 'skipcash' && ! Auth::user()?->can('gateway_settlements.review')) {
            return ArClearingSettlement::query()->whereRaw('1 = 0');
        }

        $query = ArClearingSettlement::with('bankAccount')
            ->where('company_id', app(AccountingContextService::class)->defaultCompanyId())
            ->where('settlement_method', $this->method)
            ->orderByDesc('settlement_date')
            ->orderByDesc('id');
        $branchIds = $this->visibleBranchIds();
        if ($branchIds !== null) {
            if ($branchIds === []) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereDoesntHave('items.payment', fn ($payments) => $payments
                ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhereNotIn('branch_id', $branchIds)));
        }

        return $query;
    }

    private function gatewayImportsQuery()
    {
        $query = GatewaySettlementImport::query()
            ->with('paymentSource:id,name,code')
            ->withCount('rows')
            ->latest('id');
        $user = Auth::user();
        if (! $user?->can('gateway_settlements.review')) {
            return $query->whereRaw('1 = 0');
        }

        $companyId = app(AccountingContextService::class)->defaultCompanyId();
        $query->where('company_id', $companyId);
        if (! $user->isAdmin()) {
            $allowed = $user->allowedBranchIds();
            if ($allowed === []) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereDoesntHave('rows', fn ($rows) => $rows
                ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhereNotIn('branch_id', $allowed)));
        }

        return $query;
    }

    /** @return array<int, int>|null */
    private function visibleBranchIds(): ?array
    {
        $user = Auth::user();

        return $user?->isAdmin() ? null : ($user?->allowedBranchIds() ?? []);
    }

    public function gatewaySources(): Collection
    {
        if (! Auth::user()?->can('gateway_settlements.import')) {
            return collect();
        }

        return PaymentSource::query()
            ->where('company_id', app(AccountingContextService::class)->defaultCompanyId())
            ->where('method', 'skipcash')
            ->orderBy('name')
            ->get();
    }

    public function importSkipCash(GatewaySettlementImportService $service): void
    {
        abort_unless(Auth::user()?->can('gateway_settlements.import'), 403);
        $data = $this->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'settlement_workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        try {
            $import = $service->stage(
                $this->settlement_workbook,
                PaymentSource::query()->findOrFail((int) $data['payment_source_id']),
                Auth::user(),
            );
            $this->settlement_workbook = null;
            $this->resetPage('gatewayImportsPage');
            session()->flash('status', __('SkipCash report #:id was staged for review.', ['id' => $import->id]));
        } catch (GatewaySettlementDuplicateImportException $exception) {
            $this->addError('settlement_workbook', __('This report was already imported as #:id.', [
                'id' => $exception->existingImport->id,
            ]));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('settlement_workbook', $message);
                }
            }
        }
    }

    public function toggleSelect(int $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_filter($this->selected, fn ($v) => $v !== $id));
        } else {
            $this->selected[] = $id;
        }
    }

    public function selectAll(): void
    {
        $this->selected = $this->pendingQuery()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function settle(ArClearingSettlementService $service): void
    {
        $this->resetErrorBag();

        if (empty($this->selected)) {
            $this->addError('settle', __('Select at least one payment to settle.'));

            return;
        }

        if (! $this->bank_account_id) {
            $this->addError('bank_account_id', __('Please select a bank account.'));

            return;
        }

        if (! $this->settlement_date) {
            $this->addError('settlement_date', __('Please select a settlement date.'));

            return;
        }

        try {
            $service->settle(
                $this->selected,
                $this->method,
                (int) $this->bank_account_id,
                $this->settlement_date,
                (int) Auth::id(),
                Str::uuid()->toString(),
                $this->reference !== '' ? $this->reference : null,
                $this->notes !== '' ? $this->notes : null,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('settle', $message);
                }
            }

            return;
        }

        $this->selected = [];
        $this->confirm_settle = false;
        $this->reference = '';
        $this->notes = '';
        $this->tab = 'history';
        session()->flash('status', __('Settlement posted successfully.'));
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('AR Clearing Settlements') }}</h1>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    {{-- Summary bar --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Unsettled Cheques') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $summary['cheque_count'] ?? 0 }}
                <span class="text-sm font-normal text-neutral-500 dark:text-neutral-400">· {{ $this->formatMoney($summary['cheque_total'] ?? 0) }}</span>
            </p>
        </div>
        @can('gateway_settlements.review')
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('SkipCash outstanding gross') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ $this->formatMoney($summary['skipcash_unsettled_gross_cents'] ?? 0) }}
                </p>
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('Pending :pending · correction required :correction', [
                        'pending' => $this->formatMoney($summary['skipcash_pending_gross_cents'] ?? 0),
                        'correction' => $this->formatMoney($summary['skipcash_correction_required_gross_cents'] ?? 0),
                    ]) }}
                </p>
            </div>
        @endcan
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Unsettled Card') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $summary['card_count'] ?? 0 }}
                <span class="text-sm font-normal text-neutral-500 dark:text-neutral-400">· {{ $this->formatMoney($summary['card_total'] ?? 0) }}</span>
            </p>
        </div>
    </div>

    {{-- Tab bar --}}
    <div class="flex border-b border-neutral-200 dark:border-neutral-700">
        <button
            wire:click="$set('tab', 'pending')"
            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors {{ $tab === 'pending' ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-300' : 'border-transparent text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' }}"
        >
            {{ __('Pending') }}
        </button>
        <button
            wire:click="$set('tab', 'history')"
            class="px-4 py-2 text-sm font-medium border-b-2 transition-colors {{ $tab === 'history' ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-300' : 'border-transparent text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' }}"
        >
            {{ __('History') }}
        </button>
        @can('gateway_settlements.review')
            <button
                type="button"
                wire:click="$set('tab', 'skipcash')"
                class="min-h-11 border-b-2 px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $tab === 'skipcash' ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-300' : 'border-transparent text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' }}"
            >
                {{ __('SkipCash') }}
            </button>
        @endcan
    </div>

    {{-- ── PENDING TAB ── --}}
    @if ($tab === 'pending')
        {{-- Method toggle --}}
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type:') }}</span>
            <button
                wire:click="$set('method', 'cheque')"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $method === 'cheque' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}"
            >
                {{ __('Cheque') }}
            </button>
            <button
                wire:click="$set('method', 'card')"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $method === 'card' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}"
            >
                {{ __('Card') }}
            </button>
        </div>

        {{-- Date range filters --}}
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-filter-grid">
                <div class="min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date From') }}</label>
                    <input wire:model.live="date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date To') }}</label>
                    <input wire:model.live="date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>
        </div>

        {{-- Batch settle panel --}}
        @if (count($selected) > 0)
            @php
                $selectedTotal = $pending->getCollection()->whereIn('id', $selected)->sum('amount_cents');
            @endphp
            <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 shadow-sm dark:border-primary-900 dark:bg-primary-950/40 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-primary-800 dark:text-primary-200">
                            {{ __(':count payment(s) selected', ['count' => count($selected)]) }}
                            &mdash; {{ $this->formatMoney($selectedTotal) }}
                        </p>
                    </div>
                    <button wire:click="clearSelection" class="text-xs text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200">{{ __('Clear selection') }}</button>
                </div>

                @error('settle')
                    <p class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ __('Bank Account') }} <span class="text-rose-500">*</span></label>
                        <select wire:model="bank_account_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select bank account…') }}</option>
                            @foreach ($bankAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error('bank_account_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ __('Settlement Date') }} <span class="text-rose-500">*</span></label>
                        <input wire:model="settlement_date" type="date" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                        @error('settlement_date') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ __('Reference') }}</label>
                        <input wire:model="reference" type="text" placeholder="{{ __('Optional reference') }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="text-xs font-medium text-neutral-700 dark:text-neutral-200">{{ __('Notes') }}</label>
                        <input wire:model="notes" type="text" placeholder="{{ __('Optional notes') }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    @if (! $confirm_settle)
                        <flux:button wire:click="$set('confirm_settle', true)" variant="primary">
                            {{ __('Post Settlement') }}
                        </flux:button>
                    @else
                        <p class="text-sm font-medium text-rose-700 dark:text-rose-300">{{ __('Confirm posting settlement for :count payment(s)?', ['count' => count($selected)]) }}</p>
                        <flux:button wire:click="settle" wire:loading.attr="disabled" variant="primary">
                            {{ __('Yes, Settle') }}
                        </flux:button>
                        <flux:button wire:click="$set('confirm_settle', false)" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Pending payments table --}}
        <div class="app-table-shell">
            <div class="flex items-center justify-between px-3 py-2 border-b border-neutral-200 dark:border-neutral-700">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    {{ $pending->total() }} {{ __('unsettled') }} {{ strtoupper($method) }} {{ __('payment(s)') }}
                </p>
                @if ($pending->total() > 0)
                    <flux:button wire:click="selectAll" size="xs" variant="ghost">{{ __('Select All') }}</flux:button>
                @endif
            </div>
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('#') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($pending as $payment)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70 {{ in_array($payment->id, $selected) ? 'bg-primary-50 dark:bg-primary-950/30' : '' }}">
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    wire:click="toggleSelect({{ $payment->id }})"
                                    @checked(in_array($payment->id, $selected))
                                    class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"
                                />
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $payment->id }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->received_at?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->customer?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($payment->method ?? '—') }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payment->amount_cents) }}</td>
                            <td class="px-3 py-2 text-sm text-right">
                                <flux:button size="xs" :href="route('receivables.payments.show', $payment)" wire:navigate>{{ __('View') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No unsettled :method payments.', ['method' => strtoupper($method)]) }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $pending->links() }}</div>
    @endif

    @if ($tab === 'skipcash' && Auth::user()?->can('gateway_settlements.review'))
        <section aria-labelledby="skipcash-clearing-heading" class="space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="max-w-2xl">
                    <h2 id="skipcash-clearing-heading" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('SkipCash clearing') }}
                    </h2>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                        {{ __('Import the provider report, resolve every sale, verify the exact bank deposit, then post one net payout.') }}
                    </p>
                </div>
                @if (! config('skipcash.settlements.enabled'))
                    <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        {{ __('New settlement actions disabled') }}
                    </span>
                @endif
            </div>

            @can('gateway_settlements.import')
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Import provider report') }}</h3>
                    <p id="skipcash-workbook-help" class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                        {{ __('XLSX only, up to 10 MB. The original file is retained privately and importing does not create accounting entries.') }}
                    </p>
                    <form wire:submit="importSkipCash" class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] md:items-end">
                        <div>
                            <label for="skipcash-source" class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment source') }}</label>
                            <select id="skipcash-source" wire:model="payment_source_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Choose SkipCash source') }}</option>
                                @foreach ($gatewaySources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="skipcash-workbook" class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Settlement report') }}</label>
                            <input id="skipcash-workbook" wire:model="settlement_workbook" aria-describedby="skipcash-workbook-help skipcash-workbook-error" type="file" accept=".xlsx" class="mt-1 block w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 file:mr-3 file:rounded file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100 dark:file:bg-neutral-700" />
                            @error('settlement_workbook')
                                <p id="skipcash-workbook-error" role="alert" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="importSkipCash,settlement_workbook" :disabled="! config('skipcash.settlements.enabled')">
                            <span wire:loading.remove wire:target="importSkipCash">{{ __('Validate and stage') }}</span>
                            <span wire:loading wire:target="importSkipCash">{{ __('Staging…') }}</span>
                        </flux:button>
                    </form>
                </div>
            @endcan

            <div class="app-table-shell">
                <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-700">
                    <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Imported reports') }}</h3>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Blocked rows remain visible and cannot be posted.') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Import') }}</th>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Period') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Gross') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Deductions') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Net') }}</th>
                                <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('State') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse ($gatewayImports as $gatewayImport)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                    <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                        <span class="font-medium">#{{ $gatewayImport->id }}</span>
                                        <span class="mt-0.5 block max-w-64 truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $gatewayImport->original_name }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        {{ $gatewayImport->report_period_start?->format('Y-m-d') ?? '—' }}
                                        <span aria-hidden="true">–</span>
                                        {{ $gatewayImport->report_period_end?->format('Y-m-d') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($gatewayImport->gross_cents) }}</td>
                                    <td class="px-3 py-3 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($gatewayImport->commission_cents + $gatewayImport->settlement_fee_cents) }}</td>
                                    <td class="px-3 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($gatewayImport->net_cents) }}</td>
                                    <td class="px-3 py-3 text-sm">
                                        @if ($gatewayImport->review_state === 'blocked')
                                            <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-900/50 dark:text-rose-200">{{ __('Blocked') }}</span>
                                        @elseif ($gatewayImport->posting_state === 'posted')
                                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">{{ __('Posted') }}</span>
                                        @elseif ($gatewayImport->review_state === 'reviewed')
                                            <span class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/50 dark:text-sky-200">{{ __('Reviewed') }}</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ __('Draft') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <flux:button size="xs" :href="route('accounting.ar-clearing.skipcash.show', $gatewayImport)" wire:navigate>{{ __('Review') }}</flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center">
                                        <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('No SkipCash reports imported') }}</p>
                                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Upload the provider XLSX report when the first payout is ready to clear.') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div>{{ $gatewayImports->links() }}</div>
        </section>
    @endif

    {{-- ── HISTORY TAB ── --}}
    @if ($tab === 'history')
        {{-- Method toggle --}}
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type:') }}</span>
            <button
                wire:click="$set('method', 'cheque')"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $method === 'cheque' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}"
            >
                {{ __('Cheque') }}
            </button>
            <button
                wire:click="$set('method', 'card')"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $method === 'card' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}"
            >
                {{ __('Card') }}
            </button>
            @can('gateway_settlements.review')
                <button
                    type="button"
                    wire:click="$set('method', 'skipcash')"
                    class="min-h-11 rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $method === 'skipcash' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' }}"
                >
                    {{ __('SkipCash') }}
                </button>
            @endcan
        </div>

        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Settlement #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bank Account') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($settlements as $settlement)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $settlement->id }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($settlement->settlement_method ?? '—') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $settlement->settlement_date }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($settlement->amount_cents) }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $settlement->bankAccount?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm">
                                @if ($settlement->voided_at)
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-900/50 dark:text-rose-300">{{ __('Voided') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">{{ __('Active') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-sm text-right">
                                <flux:button size="xs" :href="route('accounting.ar-clearing-show', $settlement)" wire:navigate>{{ __('View') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No settlements found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $settlements->links() }}</div>
    @endif
</div>
