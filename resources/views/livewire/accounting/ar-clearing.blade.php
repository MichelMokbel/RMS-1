<?php

use App\Models\ArClearingSettlement;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Services\AR\ArClearingSettlementService;
use App\Services\Reports\UnsettledIncomingReceiptsReportService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
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

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->settlement_date = now()->toDateString();
    }

    public function updating($field): void
    {
        if (in_array($field, ['method', 'date_from', 'date_to', 'tab'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function bankAccounts(): Collection
    {
        return BankAccount::where('is_active', true)->orderBy('name')->get();
    }

    public function summary(): array
    {
        return app(UnsettledIncomingReceiptsReportService::class)->summary(null, now()->toDateString());
    }

    public function with(): array
    {
        return [
            'pending'      => $this->pendingQuery()->paginate(20),
            'settlements'  => $this->settlementsQuery()->paginate(20),
            'bankAccounts' => $this->bankAccounts(),
            'summary'      => $this->summary(),
        ];
    }

    private function pendingQuery()
    {
        return app(UnsettledIncomingReceiptsReportService::class)
            ->query(null, $this->method, $this->date_from, $this->date_to)
            ->with('customer');
    }

    private function settlementsQuery()
    {
        return ArClearingSettlement::with('bankAccount')
            ->where('settlement_method', $this->method)
            ->orderByDesc('settlement_date')
            ->orderByDesc('id');
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
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Unsettled Cheques') }}</p>
            <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $summary['cheque_count'] ?? 0 }}
                <span class="text-sm font-normal text-neutral-500 dark:text-neutral-400">· {{ $this->formatMoney($summary['cheque_total'] ?? 0) }}</span>
            </p>
        </div>
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
