<?php

use App\Models\PastryOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'all';
    public ?string $type = null;
    public ?int $branch_id = null;
    public ?string $scheduled_date = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public string $search = '';

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to   = now()->endOfMonth()->toDateString();
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'type', 'branch_id', 'scheduled_date', 'date_from', 'date_to', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'orders'       => $this->query()->paginate(25),
            'branches'     => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
            'totalAmount'  => $this->query()->sum('total_amount'),
        ];
    }

    private function query()
    {
        return PastryOrder::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->scheduled_date, fn ($q) => $q->whereDate('scheduled_date', $this->scheduled_date))
            ->when($this->date_from, fn ($q) => $q->whereDate('scheduled_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('scheduled_date', '<=', $this->date_to))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term)
                    ->orWhere('customer_phone_snapshot', 'like', $term)
                );
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');
    }

    public function exportParams(): array
    {
        return array_filter([
            'status'         => $this->status !== 'all' ? $this->status : null,
            'type'           => $this->type,
            'branch_id'      => $this->branch_id,
            'scheduled_date' => $this->scheduled_date,
            'date_from'      => $this->date_from,
            'date_to'        => $this->date_to,
            'search'         => $this->search !== '' ? $this->search : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Pastry Orders Report') }}</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.pastry-orders.print', $exportParams) }}" target="_blank"
               class="inline-flex items-center rounded-md border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-sm hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">
                {{ __('Print') }}
            </a>
            <a href="{{ route('reports.pastry-orders.csv', $exportParams) }}"
               class="inline-flex items-center rounded-md border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-sm hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">
                {{ __('CSV') }}
            </a>
            <a href="{{ route('reports.pastry-orders.pdf', $exportParams) }}"
               class="inline-flex items-center rounded-md border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-sm hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">
                {{ __('PDF') }}
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="app-filter-grid">
            <div class="min-w-[200px] flex-1">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # / customer / phone') }}" />
            </div>
            <div class="w-40">
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Status') }}</label>
                <select wire:model.live="status"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="Draft">{{ __('Draft') }}</option>
                    <option value="Confirmed">{{ __('Confirmed') }}</option>
                    <option value="InProduction">{{ __('In Production') }}</option>
                    <option value="Ready">{{ __('Ready') }}</option>
                    <option value="Delivered">{{ __('Delivered') }}</option>
                    <option value="Cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            <div class="w-36">
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1">{{ __('Type') }}</label>
                <select wire:model.live="type"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="Pickup">{{ __('Pickup') }}</option>
                    <option value="Delivery">{{ __('Delivery') }}</option>
                </select>
            </div>
            <div class="w-28">
                <flux:input wire:model.live="branch_id" type="number" :label="__('Branch')" />
            </div>
            <div class="w-36">
                <flux:input wire:model.live="date_from" type="date" :label="__('From')" />
            </div>
            <div class="w-36">
                <flux:input wire:model.live="date_to" type="date" :label="__('To')" />
            </div>
        </div>
    </div>

    {{-- Results table --}}
    <div class="app-table-shell rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phone') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Scheduled') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $order->order_number }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->status }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->type }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->customer_name_snapshot ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->customer_phone_snapshot ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->branch_id ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $order->scheduled_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $order->total_amount, 3) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No orders found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($orders->count())
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="7" class="px-3 py-3 text-sm font-semibold text-right text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-3 text-sm text-right font-bold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $totalAmount, 3) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div>{{ $orders->links() }}</div>
</div>
