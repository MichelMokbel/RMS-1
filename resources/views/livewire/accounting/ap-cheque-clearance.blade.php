<?php

use App\Models\ApChequeClearance;
use App\Models\ApPayment;
use App\Models\BankAccount;
use App\Services\AP\ApChequeClearanceService;
use App\Services\Reports\OutstandingIssuedChequesReportService;
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

    public ?string $date_from = null;
    public ?string $date_to = null;

    public array $clearance_date = [];
    public array $clearance_bank_account = [];
    public array $clearance_reference = [];
    public array $confirm_clear = [];

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        // Dates default lazily per-row in the blade via null-coalescing
    }

    public function updating(string $field): void
    {
        if (in_array($field, ['tab', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function bankAccounts(): Collection
    {
        return BankAccount::where('is_active', true)->orderBy('name')->get();
    }

    public function summary(): array
    {
        return app(OutstandingIssuedChequesReportService::class)->summary(null, now()->toDateString());
    }

    private function pendingQuery()
    {
        return app(OutstandingIssuedChequesReportService::class)->query(null, $this->date_from, $this->date_to)
            ->with('supplier');
    }

    private function clearancesQuery()
    {
        return ApChequeClearance::with(['apPayment.supplier', 'bankAccount'])
            ->orderByDesc('clearance_date')
            ->orderByDesc('id');
    }

    public function with(): array
    {
        return [
            'pending'    => $this->tab === 'pending'
                ? $this->pendingQuery()->paginate(20)
                : collect(),
            'clearances' => $this->tab === 'history'
                ? $this->clearancesQuery()->paginate(20)
                : collect(),
            'bankAccounts' => $this->bankAccounts(),
            'summary'      => $this->summary(),
        ];
    }

    public function toggleConfirm(int $paymentId): void
    {
        if (isset($this->confirm_clear[$paymentId])) {
            unset($this->confirm_clear[$paymentId]);
        } else {
            $this->confirm_clear[$paymentId] = true;
        }
    }

    public function clearPayment(int $paymentId, ApChequeClearanceService $service): void
    {
        $payment = ApPayment::findOrFail($paymentId);

        $bankAccountId = $this->clearance_bank_account[$paymentId] ?? null;
        $clearanceDate = $this->clearance_date[$paymentId] ?? now()->toDateString();
        $reference     = $this->clearance_reference[$paymentId] ?? null;

        if (! $bankAccountId) {
            session()->flash('error', __('Please select a bank account for cheque #:ref.', ['ref' => $payment->reference ?? $paymentId]));
            return;
        }

        if (! $clearanceDate) {
            session()->flash('error', __('Please enter a clearance date for cheque #:ref.', ['ref' => $payment->reference ?? $paymentId]));
            return;
        }

        try {
            $service->clear(
                $paymentId,
                (int) $bankAccountId,
                $clearanceDate,
                (float) $payment->amount,
                Auth::id(),
                Str::uuid()->toString(),
                $reference ?: null,
                null
            );

            session()->flash('status', __('Cheque #:ref cleared successfully.', ['ref' => $payment->reference ?? $paymentId]));

            unset($this->confirm_clear[$paymentId]);
            unset($this->clearance_bank_account[$paymentId]);
            unset($this->clearance_date[$paymentId]);
            unset($this->clearance_reference[$paymentId]);

            // Switch to history tab so user sees the new clearance
            $this->tab = 'history';
            $this->resetPage();
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to clear cheque: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2);
    }
}; ?>

<div class="app-page space-y-6">

    {{-- Page header --}}
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('AP Cheque Clearance') }}
        </h1>
    </div>

    {{-- Flash messages --}}
    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    {{-- Summary card --}}
    @php $currency = config('pos.currency', 'QAR'); @endphp
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                    {{ __('Outstanding Cheques') }}
                </p>
                <p class="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                    {{ $summary['outstanding_count'] ?? 0 }}
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                    {{ __('Outstanding Total') }}
                </p>
                <p class="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                    {{ $currency }} {{ $this->formatAmount($summary['outstanding_total'] ?? 0) }}
                </p>
            </div>
            @if (! empty($summary['as_of']))
                <div class="ml-auto text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('As of') }} {{ $summary['as_of'] }}
                </div>
            @endif
        </div>
    </div>

    {{-- Tab bar --}}
    <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
        <button
            wire:click="$set('tab', 'pending')"
            class="px-4 py-2 text-sm font-medium transition-colors
                {{ $tab === 'pending'
                    ? 'border-b-2 border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' }}">
            {{ __('Pending') }}
        </button>
        <button
            wire:click="$set('tab', 'history')"
            class="px-4 py-2 text-sm font-medium transition-colors
                {{ $tab === 'history'
                    ? 'border-b-2 border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100' }}">
            {{ __('History') }}
        </button>
    </div>

    {{-- ── PENDING TAB ── --}}
    @if ($tab === 'pending')

        {{-- Date filters --}}
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-filter-grid">
                <div class="min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Date From') }}</label>
                    <input
                        wire:model.live="date_from"
                        type="date"
                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div class="min-w-[170px]">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Date To') }}</label>
                    <input
                        wire:model.live="date_to"
                        type="date"
                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>
        </div>

        {{-- Pending cheques table --}}
        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cheque Ref') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment Date') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bank Account') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Clearance Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($pending as $payment)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                {{ $payment->supplier?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $payment->reference ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $payment->payment_date?->format('Y-m-d') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                {{ $currency }} {{ $this->formatAmount($payment->amount) }}
                            </td>
                            <td class="px-3 py-2">
                                <select
                                    wire:model="clearance_bank_account.{{ $payment->id }}"
                                    class="w-full min-w-[180px] rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select bank…') }}</option>
                                    @foreach ($bankAccounts as $bank)
                                        <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    wire:model="clearance_date.{{ $payment->id }}"
                                    type="date"
                                    value="{{ now()->toDateString() }}"
                                    class="w-full min-w-[140px] rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                            </td>
                            <td class="px-3 py-2">
                                <input
                                    wire:model="clearance_reference.{{ $payment->id }}"
                                    type="text"
                                    placeholder="{{ __('Optional ref') }}"
                                    class="w-full min-w-[130px] rounded-md border border-neutral-200 bg-white px-2 py-1.5 text-sm text-neutral-800 placeholder-neutral-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:placeholder-neutral-500" />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <flux:button
                                    size="xs"
                                    wire:click="toggleConfirm({{ $payment->id }})">
                                    {{ isset($confirm_clear[$payment->id]) ? __('Cancel') : __('Clear') }}
                                </flux:button>
                            </td>
                        </tr>

                        {{-- Per-row confirmation panel --}}
                        @if (isset($confirm_clear[$payment->id]))
                            <tr class="bg-amber-50 dark:bg-amber-950/30">
                                <td colspan="8" class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-4">
                                        <p class="text-sm text-amber-900 dark:text-amber-200">
                                            {{ __('Clear cheque') }}
                                            <span class="font-semibold">#{{ $payment->reference ?? $payment->id }}</span>
                                            {{ __('for') }}
                                            <span class="font-semibold">{{ $currency }} {{ $this->formatAmount($payment->amount) }}</span>
                                            @if (! empty($clearance_bank_account[$payment->id]))
                                                {{ __('from') }}
                                                <span class="font-semibold">
                                                    {{ $bankAccounts->firstWhere('id', $clearance_bank_account[$payment->id])?->name ?? __('selected bank') }}
                                                </span>
                                            @endif
                                            {{ __('on') }}
                                            <span class="font-semibold">{{ $clearance_date[$payment->id] ?? now()->toDateString() }}</span>?
                                        </p>
                                        <div class="flex gap-2">
                                            <flux:button
                                                size="xs"
                                                variant="primary"
                                                wire:click="clearPayment({{ $payment->id }})"
                                                wire:loading.attr="disabled">
                                                {{ __('Confirm') }}
                                            </flux:button>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                wire:click="toggleConfirm({{ $payment->id }})">
                                                {{ __('Cancel') }}
                                            </flux:button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No outstanding cheques found.') }}
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

        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Clearance #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cheque Ref') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Clearance Date') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bank Account') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($clearances as $clearance)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                #{{ $clearance->id }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $clearance->apPayment?->supplier?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $clearance->apPayment?->reference ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $clearance->clearance_date instanceof \Carbon\Carbon
                                    ? $clearance->clearance_date->format('Y-m-d')
                                    : ($clearance->clearance_date ?? '—') }}
                            </td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                {{ $currency }} {{ $this->formatAmount($clearance->amount) }}
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $clearance->bankAccount?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-sm">
                                @if ($clearance->voided_at)
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                        {{ __('Voided') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                        {{ __('Active') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-sm text-right">
                                <flux:button
                                    size="xs"
                                    :href="route('accounting.ap-cheque-clearance-show', $clearance)"
                                    wire:navigate>
                                    {{ __('View') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No clearances recorded yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $clearances->links() }}</div>

    @endif

</div>
