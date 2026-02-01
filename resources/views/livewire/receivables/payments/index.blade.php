<?php

use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $customer_search = '';
    public ?string $method = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        if (in_array($field, ['customer_search', 'method', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'payments' => $this->query()->paginate(15),
        ];
    }

    private function query()
    {
        return Payment::query()
            ->where('source', 'ar')
            ->with(['customer'])
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->when(trim($this->customer_search) !== '', function ($q) {
                $term = trim($this->customer_search);
                $q->whereHas('customer', fn ($cq) => $cq->search($term));
            })
            ->when($this->method, fn ($q) => $q->where('method', $this->method))
            ->when($this->date_from, fn ($q) => $q->whereDate('received_at', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('received_at', '<=', $this->date_to))
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Payments') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('receivables.payments.create')" wire:navigate>{{ __('New Payment') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <input wire:model.live.debounce.300ms="customer_search" type="text" placeholder="{{ __('Search name/phone/code') }}" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
            <div class="min-w-[160px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                <select wire:model.live="method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                    <option value="online">{{ __('Online') }}</option>
                    <option value="bank">{{ __('Bank') }}</option>
                    <option value="voucher">{{ __('Voucher') }}</option>
                </select>
            </div>
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

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Allocated') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unallocated') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($payments as $payment)
                    @php
                        $allocated = (int) ($payment->allocated_sum ?? 0);
                        $remaining = (int) $payment->amount_cents - $allocated;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $payment->id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->received_at?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->customer?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($payment->method ?? '—') }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payment->amount_cents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($allocated) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($remaining) }}</td>
                        <td class="px-3 py-2 text-sm text-right">
                            <flux:button size="xs" :href="route('receivables.payments.show', $payment)" wire:navigate>{{ __('View') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No payments found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $payments->links() }}</div>
</div>
