<?php

use App\Models\Category;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $item_search = '';
    public ?int $category_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['item_search', 'category_id', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'rows' => $this->query()->paginate(20),
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
        ];
    }

    private function query()
    {
        return OrderItem::query()
            ->with(['menuItem.category'])
            ->join('orders as o', 'o.id', '=', 'order_items.order_id')
            ->leftJoin('menu_items as mi', 'mi.id', '=', 'order_items.menu_item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'mi.category_id')
            ->select('order_items.*')
            ->when($this->date_from, fn ($q) => $q->whereDate('o.scheduled_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('o.scheduled_date', '<=', $this->date_to))
            ->when($this->item_search, function ($q) {
                $like = '%'.$this->item_search.'%';
                $q->where(fn ($inner) => $inner->where('mi.name', 'like', $like)->orWhere('order_items.description_snapshot', 'like', $like));
            })
            ->when($this->category_id, fn ($q) => $q->where('mi.category_id', $this->category_id))
            ->orderByDesc('o.scheduled_date')
            ->orderByDesc('o.id')
            ->orderBy('order_items.sort_order');
    }

    public function formatMoney(?float $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', ',');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Sales by Item') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index', array_filter(['category' => \App\Support\Reports\ReportRegistry::findByRoute(request()->route()?->getName())['category'] ?? null]))" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="item_search" :label="__('Search Item')" placeholder="{{ __('Item name') }}" />
            </div>
            <div class="min-w-[200px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Line Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row->description_snapshot ?: ($row->menuItem?->name ?? '—') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->menuItem?->category?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney($row->unit_price) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) $row->quantity, 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row->line_total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No sale lines found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $rows->links() }}</div>
</div>
