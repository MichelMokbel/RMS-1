<?php

use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $status = 'all';
    public string $payment_type = 'all';
    public string $search = '';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
    }

    public function with(): array
    {
        $invoices = ArInvoice::query()
            ->with(['customer', 'creator'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->payment_type !== 'all', fn ($q) => $q->where('payment_type', $this->payment_type))
            ->when(trim($this->search) !== '', function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($qq) use ($term): void {
                    $qq->where('invoice_number', 'like', $term)
                        ->orWhere('pos_reference', 'like', $term)
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $term));
                });
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return [
            'invoices' => $invoices,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function applyFilters(): void
    {
        $this->search = trim($this->search);
    }

    public function resetFilters(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->status = 'all';
        $this->payment_type = 'all';
        $this->search = '';
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
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

    <form wire:submit.prevent="applyFilters" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid items-end">
            <div class="min-w-[180px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                @if ($branches->count())
                    <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model.live="branch_id" type="number" :label="__('Branch ID')" />
                @endif
            </div>
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
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
                <select wire:model.live="payment_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
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
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Invoice #, POS Ref, Customer') }}"
                    class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                />
            </div>
            <div class="min-w-[260px]">
                <div class="flex items-center gap-2">
                    <flux:button type="submit" variant="primary" class="w-full touch-target" wire:loading.attr="disabled" wire:target="applyFilters">
                        <span wire:loading.remove wire:target="applyFilters">{{ __('Apply Filters') }}</span>
                        <span wire:loading.inline-flex wire:target="applyFilters" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                            </svg>
                            <span>{{ __('Applying...') }}</span>
                        </span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" class="w-full touch-target" wire:click="resetFilters" wire:loading.attr="disabled" wire:target="resetFilters">
                        <span wire:loading.remove wire:target="resetFilters">{{ __('Reset Filters') }}</span>
                        <span wire:loading wire:target="resetFilters">{{ __('Resetting...') }}</span>
                    </flux:button>
                </div>
            </div>
        </div>
    </form>

    <div wire:loading.flex wire:target="search,branch_id,status,payment_type,applyFilters,resetFilters" class="items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-3 text-sm text-neutral-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
        <svg class="h-4 w-4 animate-spin text-neutral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
        </svg>
        <span>{{ __('Loading invoices...') }}</span>
    </div>

    <div wire:loading.remove wire:target="search,branch_id,status,payment_type,applyFilters,resetFilters" class="ar-invoices-mobile-cards">
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

    <div wire:loading.remove wire:target="search,branch_id,status,payment_type,applyFilters,resetFilters" class="ar-invoices-desktop-table overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
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
</div>
