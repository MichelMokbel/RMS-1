<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $branch_id = 1;
    public ?int $customer_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
    }

    public function updating($name): void
    {
        if (in_array($name, ['branch_id', 'customer_id', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'payments' => $this->query()->paginate(15),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->limit(200)->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        return Payment::query()
            ->where('source', 'ar')
            ->whereNull('voided_at')
            ->with(['customer'])
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->customer_id, fn ($q) => $q->where('customer_id', $this->customer_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('received_at', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('received_at', '<=', $this->date_to))
            ->whereRaw('amount_cents > (SELECT COALESCE(SUM(amount_cents), 0) FROM payment_allocations WHERE payment_allocations.payment_id = payments.id AND payment_allocations.voided_at IS NULL)')
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id,
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
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Advances') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customer-advances.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customer-advances.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.customer-advances.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
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
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Allocated') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unallocated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($payments as $pay)
                    @php
                        $allocated = (int) ($pay->allocated_sum ?? 0);
                        $remaining = (int) $pay->amount_cents - $allocated;
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">#{{ $pay->id }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->customer?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $pay->received_at?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ strtoupper($pay->method ?? '—') }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($pay->amount_cents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($allocated) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($remaining) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No advances found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($payments->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="4" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum('amount_cents')) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum(fn ($p) => (int) ($p->allocated_sum ?? 0))) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($payments->getCollection()->sum(fn ($p) => (int) $p->amount_cents - (int) ($p->allocated_sum ?? 0))) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div>{{ $payments->links() }}</div>
</div>
