<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function with(): array
    {
        return [
            'rows' => $this->query(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->limit(200)->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        $asOf = $this->date_to ? now()->parse($this->date_to) : now();

        $invoices = ArInvoice::query()
            ->with(['customer'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('balance_cents', '>', 0)
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->date_to))
            ->get();

        $bucketed = [];
        foreach ($invoices as $inv) {
            $customerId = (int) $inv->customer_id;
            $name = $inv->customer?->name ?? '—';
            $balance = (int) $inv->balance_cents;

            if (! isset($bucketed[$customerId])) {
                $bucketed[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'current' => 0,
                    'bucket_1_30' => 0,
                    'bucket_31_60' => 0,
                    'bucket_61_90' => 0,
                    'bucket_90_plus' => 0,
                    'total' => 0,
                ];
            }

            $days = $inv->due_date ? $inv->due_date->diffInDays($asOf, false) : 0;
            if ($days <= 0) {
                $bucketed[$customerId]['current'] += $balance;
            } elseif ($days <= 30) {
                $bucketed[$customerId]['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $bucketed[$customerId]['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $bucketed[$customerId]['bucket_61_90'] += $balance;
            } else {
                $bucketed[$customerId]['bucket_90_plus'] += $balance;
            }

            $bucketed[$customerId]['total'] += $balance;
        }

        return collect(array_values($bucketed));
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
            'customer_id' => $this->customer_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Aging Summary') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customer-aging-summary.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customer-aging-summary.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.customer-aging-summary.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-end gap-3">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer') }}</label>
                <select wire:model.live="customer_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Current') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('1-30') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('31-60') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('61-90') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('90+') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['customer_name'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['current']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['bucket_1_30']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['bucket_31_60']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['bucket_61_90']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row['bucket_90_plus']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No aging data found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
