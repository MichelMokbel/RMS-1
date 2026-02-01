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

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
    }

    public function with(): array
    {
        $invoices = ArInvoice::query()
            ->with('customer')
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->payment_type !== 'all', fn ($q) => $q->where('payment_type', $this->payment_type))
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

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Invoices (AR)') }}</h1>
        <div class="flex items-center gap-2">
            <flux:button :href="route('invoices.create')" wire:navigate variant="primary">{{ __('New Invoice') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
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
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoices as $inv)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $inv->invoice_number ?: ('#'.$inv->id) }}
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
                        <td class="px-3 py-2 text-sm text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button size="xs" :href="route('invoices.show', $inv)" wire:navigate>{{ __('View') }}</flux:button>
                                @if ($inv->status !== 'draft')
                                    <flux:button size="xs" variant="ghost" :href="route('invoices.print', $inv)" target="_blank">{{ __('Print') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No invoices found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

