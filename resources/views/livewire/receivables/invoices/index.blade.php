<?php

use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $status = 'all';
    public string $payment_type = 'all';
    public string $search = '';
    public int $filter_branch_id = 1;
    public string $filter_status = 'all';
    public string $filter_payment_type = 'all';
    public string $filter_search = '';
    /** @var array<int, int> */
    public array $selected_invoice_ids = [];
    public bool $select_page = false;
    public string $bulk_discount_type = 'fixed';
    public string $bulk_discount_value = '0.00';
    public bool $bulk_discount_acknowledged = false;

    public function mount(): void
    {
        $defaultBranch = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->branch_id = (int) request()->query('branch_id', $defaultBranch);
        $this->status = (string) request()->query('status', 'all');
        $this->payment_type = (string) request()->query('payment_type', 'all');
        $this->search = trim((string) request()->query('search', ''));
        $this->filter_branch_id = $this->branch_id;
        $this->filter_status = $this->status;
        $this->filter_payment_type = $this->payment_type;
        $this->filter_search = $this->search;
        $this->bulk_discount_value = $this->moneyZero();
    }

    protected function invoiceQuery(): Builder
    {
        $search = trim($this->search);
        $searchLower = Str::lower($search);
        $searchTokens = collect(preg_split('/\s+/u', $searchLower) ?: [])
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->values()
            ->all();
        $searchCompact = str_replace(' ', '', $searchLower);

        return ArInvoice::query()
            ->with(['customer', 'creator'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->payment_type !== 'all', fn ($q) => $q->where('payment_type', $this->payment_type))
            ->when($search !== '', function ($q) use ($search, $searchTokens, $searchCompact): void {
                $term = '%'.$search.'%';
                $q->where(function ($qq) use ($term, $searchTokens, $searchCompact): void {
                    $qq->where('invoice_number', 'like', $term)
                        ->orWhere('pos_reference', 'like', $term)
                        ->orWhereHas('customer', function (Builder $cq) use ($term, $searchTokens, $searchCompact): void {
                            $cq->where(function (Builder $match) use ($term, $searchTokens, $searchCompact): void {
                                $match->where(function (Builder $base) use ($term, $searchCompact): void {
                                    $base->where('name', 'like', $term)
                                        ->orWhere('phone', 'like', $term)
                                        ->orWhere('customer_code', 'like', $term)
                                        ->orWhereRaw('LOWER(REPLACE(name, " ", "")) like ?', ['%'.$searchCompact.'%']);
                                });

                                if (count($searchTokens) > 0) {
                                    $match->orWhere(function (Builder $tokenChain) use ($searchTokens): void {
                                        foreach ($searchTokens as $token) {
                                            $tokenChain->where(function (Builder $tokenQ) use ($token): void {
                                                $like = '%'.$token.'%';
                                                $tokenQ->where('name', 'like', $like)
                                                    ->orWhere('phone', 'like', $like)
                                                    ->orWhere('customer_code', 'like', $like);
                                            });
                                        }
                                    });
                                }
                            });
                        });
                });
            })
            ->orderByDesc('id')
            ->limit(200);
    }

    public function with(): array
    {
        $invoices = $this->invoiceQuery()->get();

        return [
            'invoices' => $invoices,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function updatedFilterSearch(): void
    {
        $this->search = trim($this->filter_search);
    }

    public function applyFilters(): void
    {
        $this->branch_id = (int) $this->filter_branch_id;
        $this->status = $this->filter_status;
        $this->payment_type = $this->filter_payment_type;
        $this->search = trim($this->filter_search);
        $this->clearBulkSelection();
    }

    public function resetFilters(): void
    {
        $this->filter_branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->filter_status = 'all';
        $this->filter_payment_type = 'all';
        $this->filter_search = '';
        $this->search = '';
        $this->applyFilters();
    }

    public function updatedSelectPage(bool $checked): void
    {
        if ($checked) {
            $this->selected_invoice_ids = $this->selectableInvoiceIds();

            return;
        }

        $this->selected_invoice_ids = [];
    }

    public function updatedSelectedInvoiceIds(): void
    {
        $selectable = $this->selectableInvoiceIds();
        $selected = collect($this->selected_invoice_ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => in_array($id, $selectable, true))
            ->unique()
            ->values()
            ->all();

        $this->selected_invoice_ids = $selected;
        $this->select_page = $selectable !== [] && count($selected) === count($selectable);
    }

    public function applyBulkDiscount(\App\Services\AR\ArInvoiceService $service): void
    {
        $this->resetErrorBag([
            'selected_invoice_ids',
            'bulk_discount_value',
            'bulk_discount_acknowledged',
        ]);

        $selected = collect($this->selected_invoice_ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($selected === []) {
            $this->addError('selected_invoice_ids', __('Select at least one draft invoice.'));

            return;
        }

        if (! $this->bulk_discount_acknowledged) {
            $this->addError('bulk_discount_acknowledged', __('Confirm that this one-time fix should update issued and paid invoices directly.'));

            return;
        }

        try {
            $discountValue = $this->bulk_discount_type === 'percent'
                ? $this->parsePercentToBps($this->bulk_discount_value)
                : MinorUnits::parsePos($this->bulk_discount_value);
        } catch (\InvalidArgumentException $e) {
            $this->addError('bulk_discount_value', __('Enter a valid discount value.'));

            return;
        }

        if ($discountValue < 0) {
            $this->addError('bulk_discount_value', __('Discount must be zero or greater.'));

            return;
        }

        try {
            $count = $service->applyBulkDiscountFix(
                invoiceIds: $selected,
                discountType: $this->bulk_discount_type,
                discountValue: $discountValue,
                actorId: (int) Auth::id(),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = $field === 'invoice_ids' ? 'selected_invoice_ids' : $field;

                foreach ($messages as $message) {
                    $this->addError($target, $message);
                }
            }

            return;
        }

        $this->bulk_discount_type = 'fixed';
        $this->bulk_discount_value = $this->moneyZero();
        $this->bulk_discount_acknowledged = false;
        $this->clearBulkSelection();
        $this->dispatch('modal-close', name: 'bulk-discount-modal');

        session()->flash('status', trans_choice(
            'Bulk discount applied to :count invoice.|Bulk discount applied to :count invoices.',
            $count,
            ['count' => $count]
        ));
    }

    protected function clearBulkSelection(): void
    {
        $this->selected_invoice_ids = [];
        $this->select_page = false;
    }

    /**
     * @return array<int, int>
     */
    protected function selectableInvoiceIds(): array
    {
        return $this->invoiceQuery()
            ->where('type', 'invoice')
            ->whereIn('status', ['draft', 'issued', 'partially_paid', 'paid'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }

    public function moneyScaleDigits(): int
    {
        return MinorUnits::scaleDigits(MinorUnits::posScale());
    }

    public function moneyStep(): string
    {
        $digits = $this->moneyScaleDigits();
        if ($digits <= 0) {
            return '1';
        }

        return '0.'.str_pad('1', $digits, '0', STR_PAD_LEFT);
    }

    public function moneyZero(): string
    {
        $digits = $this->moneyScaleDigits();
        if ($digits <= 0) {
            return '0';
        }

        return '0.'.str_repeat('0', $digits);
    }

    private function parsePercentToBps(string $percent): int
    {
        $percent = trim($percent);
        if ($percent === '') {
            return 0;
        }
        $negative = str_starts_with($percent, '-');
        if ($negative) {
            $percent = ltrim($percent, '-');
        }
        $percent = str_replace(',', '', $percent);
        if (! preg_match('/^\\d+(\\.\\d+)?$/', $percent)) {
            return 0;
        }
        [$whole, $frac] = array_pad(explode('.', $percent, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = substr(str_pad($frac, 2, '0', STR_PAD_RIGHT), 0, 2);
        $bps = ((int) $whole) * 100 + (int) $frac;
        return $negative ? -$bps : $bps;
    }
}; ?>

<style>
    .ar-invoices-mobile-cards {
        display: grid;
        gap: 0.75rem;
    }

    .ar-invoices-desktop-table {
        display: none;
    }

    @media (min-width: 640px) {
        .ar-invoices-mobile-cards {
            display: none;
        }

        .ar-invoices-desktop-table {
            display: block;
        }
    }
</style>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Invoices (AR)') }}</h1>
        <div class="flex items-center gap-2">
            <flux:button :href="route('invoices.create')" wire:navigate variant="primary" class="touch-target">{{ __('New Invoice') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
            {{ session('error') }}
        </div>
    @endif

    <form wire:submit.prevent="applyFilters" method="get" action="{{ request()->url() }}" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid items-end">
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                @if ($branches->count())
                    <select name="branch_id" wire:model.defer="filter_branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model.defer="filter_branch_id" type="number" :label="__('Branch ID')" />
                @endif
            </div>
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select name="status" wire:model.defer="filter_status" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="issued">{{ __('Issued') }}</option>
                    <option value="partially_paid">{{ __('Partially Paid') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                    <option value="voided">{{ __('Voided') }}</option>
                </select>
            </div>
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Type') }}</label>
                <select name="payment_type" wire:model.defer="filter_payment_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="credit">{{ __('Credit') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="mixed">{{ __('Mixed') }}</option>
                </select>
            </div>
            <div class="min-w-[260px] grow">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Search') }}</label>
                <input
                    type="text"
                    name="search"
                    wire:model.live.debounce.500ms="filter_search"
                    wire:keydown.enter.prevent="applyFilters"
                    placeholder="{{ __('Invoice #, POS Ref, Customer') }}"
                    class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                />
            </div>
            <div class="min-w-[260px]">
                <div class="flex items-center gap-2">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="applyFilters"
                        class="w-full touch-target inline-flex items-center justify-center rounded-md border border-neutral-900 bg-neutral-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-neutral-800 dark:border-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-200 disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <span wire:loading.remove wire:target="applyFilters">{{ __('Search') }}</span>
                        <span wire:loading.inline-flex wire:target="applyFilters" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                            </svg>
                            <span>{{ __('Searching...') }}</span>
                        </span>
                    </button>
                    <button
                        type="button"
                        wire:click.prevent="resetFilters"
                        wire:loading.attr="disabled"
                        wire:target="resetFilters"
                        class="w-full touch-target inline-flex items-center justify-center rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-800 disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <span wire:loading.remove wire:target="resetFilters">{{ __('Reset Filters') }}</span>
                        <span wire:loading wire:target="resetFilters">{{ __('Resetting...') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div wire:loading.flex wire:target="applyFilters,resetFilters" class="items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
        <svg class="h-4 w-4 animate-spin text-neutral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
        </svg>
        <span>{{ __('Loading invoices...') }}</span>
    </div>

    <div wire:loading.remove wire:target="applyFilters,resetFilters" class="flex justify-end">
        <flux:modal.trigger name="bulk-discount-modal">
            <button
                type="button"
                class="touch-target inline-flex items-center justify-center rounded-md border border-neutral-900 bg-neutral-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-neutral-800 dark:border-neutral-100 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-200"
            >
                {{ __('Bulk Discount Fix') }}
            </button>
        </flux:modal.trigger>
    </div>

    <div wire:loading.remove wire:target="applyFilters,resetFilters" class="ar-invoices-mobile-cards">
        @forelse ($invoices as $inv)
            <article class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Invoice') }}</p>
                        <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number ?: ('#'.$inv->id) }}</p>
                    </div>
                    <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ $inv->status }}</span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Date') }}</p>
                        <p class="text-neutral-800 dark:text-neutral-100">{{ $inv->issue_date?->toDateString() ?: '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Customer') }}</p>
                        <p class="text-neutral-800 dark:text-neutral-100">{{ $inv->customer?->name ?: '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Total') }}</p>
                        <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($inv->total_cents) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Balance') }}</p>
                        <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($inv->balance_cents) }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" :href="route('invoices.show', $inv)" wire:navigate class="touch-target">{{ __('View') }}</flux:button>
                    @if ($inv->status === 'draft')
                        <flux:button size="sm" variant="ghost" :href="route('invoices.edit', $inv)" wire:navigate class="touch-target">{{ __('Edit') }}</flux:button>
                    @endif
                    @if (in_array($inv->status, ['issued', 'partially_paid', 'paid'], true))
                        <flux:button size="sm" variant="ghost" :href="route('invoices.print', $inv)" target="_blank" class="touch-target">{{ __('Print') }}</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-neutral-200 bg-white px-4 py-6 text-center text-sm text-neutral-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                {{ __('No invoices found.') }}
            </div>
        @endforelse
    </div>

    <div wire:loading.remove wire:target="applyFilters,resetFilters" class="ar-invoices-desktop-table overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('POS Ref') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Registered By') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoices as $inv)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $inv->issue_date?->toDateString() ?: '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $inv->invoice_number ?: ('#'.$inv->id) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $inv->pos_reference ?: '—' }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $inv->customer?->name ?: '—' }}
                        </td>

                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $inv->status }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ $this->formatMoney($inv->total_cents) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                            {{ $this->formatMoney($inv->balance_cents) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $inv->creator?->username ?: ($inv->creator?->name ?: ($inv->creator?->email ?: '—')) }}
                        </td>
                        <td class="px-3 py-2 text-sm text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button size="xs" :href="route('invoices.show', $inv)" wire:navigate>{{ __('View') }}</flux:button>
                                @if ($inv->status === 'draft')
                                    <flux:button size="xs" variant="ghost" :href="route('invoices.edit', $inv)" wire:navigate>{{ __('Edit') }}</flux:button>
                                @endif
                                @if (in_array($inv->status, ['issued', 'partially_paid', 'paid'], true))
                                    <flux:button size="xs" variant="ghost" :href="route('invoices.print', $inv)" target="_blank">{{ __('Print') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No invoices found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="bulk-discount-modal" :show="$errors->has('bulk_discount_value') || $errors->has('bulk_discount_acknowledged')" focusable class="max-w-xl">
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Bulk Discount Fix') }}</h2>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                    {{ __('Apply one invoice-level discount to all selected sales invoices. This corrective action updates issued and paid invoices in place and preserves their current status.') }}
                </p>
            </div>

            <div class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50">
                <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" wire:model.live="select_page" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500">
                        <span>{{ __('Select all visible eligible invoices') }}</span>
                    </label>
                    <span class="text-xs text-neutral-500 dark:text-neutral-400">
                        {{ trans_choice(':count invoice selected|:count invoices selected', count($selected_invoice_ids), ['count' => count($selected_invoice_ids)]) }}
                    </span>
                </div>

                <div class="max-h-64 space-y-2 overflow-y-auto rounded-md border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                    @php
                        $eligibleInvoices = $invoices->filter(fn ($inv) => $inv->type === 'invoice' && in_array($inv->status, ['draft', 'issued', 'partially_paid', 'paid'], true));
                    @endphp

                    @forelse ($eligibleInvoices as $inv)
                        <label class="flex items-start gap-3 rounded-md border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <input
                                type="checkbox"
                                wire:model.live="selected_invoice_ids"
                                value="{{ $inv->id }}"
                                class="mt-0.5 rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ $inv->invoice_number ?: ('#'.$inv->id) }} - {{ $inv->customer?->name ?: '—' }}
                                </span>
                                <span class="block text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ __('Status') }}: {{ $inv->status }} | {{ __('Total') }}: {{ $this->formatMoney($inv->total_cents) }} | {{ __('Balance') }}: {{ $this->formatMoney($inv->balance_cents) }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No visible invoices are eligible for this bulk fix.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Discount Type') }}</label>
                    <select wire:model.live="bulk_discount_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="fixed">{{ __('Fixed Amount') }}</option>
                        <option value="percent">{{ __('Percent') }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Discount Value') }}</label>
                    <input
                        type="number"
                        wire:model.defer="bulk_discount_value"
                        step="{{ $this->moneyStep() }}"
                        min="0"
                        class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                    />
                </div>
            </div>

            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ __('This bypasses the normal posted-invoice immutability rules for a one-time cleanup. Totals and balances will be recalculated, but statuses, line items, and allocations will remain unchanged.') }}
            </div>

            <label class="flex items-start gap-3 text-sm text-neutral-700 dark:text-neutral-200">
                <input type="checkbox" wire:model.live="bulk_discount_acknowledged" class="mt-0.5 rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                <span>{{ __('I confirm this one-time fix should update the selected issued and paid invoices directly.') }}</span>
            </label>

            @error('bulk_discount_value')
                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
            @error('bulk_discount_acknowledged')
                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror
            @error('selected_invoice_ids')
                <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="applyBulkDiscount" wire:loading.attr="disabled" wire:target="applyBulkDiscount" variant="primary">
                    <span wire:loading.remove wire:target="applyBulkDiscount">{{ __('Apply Discount') }}</span>
                    <span wire:loading.inline wire:target="applyBulkDiscount">{{ __('Applying...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
