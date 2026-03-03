<?php

use App\Models\Category;
use App\Models\MenuItem;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?int $category_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public string $group_by = 'category';
    public array $category_groups = [];

    public function with(): array
    {
        return [
            'rows' => $this->query(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
            'groupBy' => $this->group_by,
            'categoryGroups' => $this->category_groups,
        ];
    }

    private function query()
    {
        return DB::table('ar_invoice_items as items')
            ->join('ar_invoices as inv', 'inv.id', '=', 'items.invoice_id')
            ->leftJoin('menu_items as mi', function ($join) {
                $join->on('mi.id', '=', 'items.sellable_id')
                    ->where('items.sellable_type', '=', MenuItem::class);
            })
            ->leftJoin('categories as cat', 'cat.id', '=', 'mi.category_id')
            ->select([
                'cat.id as category_id',
                'cat.name as category_name',
                'mi.id as item_id',
                DB::raw("COALESCE(mi.name, items.description) as item_name"),
                DB::raw('SUM(items.line_total_cents) as total_cents'),
                DB::raw('SUM(items.qty) as qty_total'),
                DB::raw('ROUND(AVG(items.unit_price_cents)) as avg_unit_price_cents'),
            ])
            ->where('inv.type', 'invoice')
            ->whereIn('inv.status', ['issued', 'partially_paid', 'paid'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('inv.branch_id', $this->branch_id))
            ->when($this->category_id, fn ($q) => $q->where('cat.id', $this->category_id))
            ->when($this->date_from, fn ($q) => $q->whereDate('inv.issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('inv.issue_date', '<=', $this->date_to))
            ->groupBy('cat.id', 'cat.name', 'mi.id', 'items.description')
            ->orderBy('cat.name')
            ->orderByDesc('total_cents')
            ->get();
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
            'category_id' => $this->category_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'group_by' => $this->group_by,
            'category_groups' => $this->category_groups,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Category Wise Sales Summary') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.category-sales-summary.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.category-sales-summary.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.category-sales-summary.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                <select wire:model.live="category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[220px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Grouping') }}</label>
                <select wire:model.live="group_by" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="category">{{ __('By Category') }}</option>
                    <option value="custom">{{ __('Custom Groups') }}</option>
                    <option value="none">{{ __('No Grouping') }}</option>
                </select>
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    @php
        $currentGroupBy = $groupBy ?? $group_by ?? 'category';
    @endphp

    @if ($currentGroupBy === 'custom')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium text-neutral-800 dark:text-neutral-100 mb-3">
                {{ __('Define Custom Category Groups') }}
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($categories as $cat)
                    <div class="flex items-center gap-2">
                        <div class="flex-1 text-sm text-neutral-800 dark:text-neutral-100">
                            {{ $cat->name }}
                        </div>
                        <input
                            type="text"
                            wire:model.live="category_groups.{{ $cat->id }}"
                            placeholder="{{ __('Group name') }}"
                            class="w-32 rounded-md border border-neutral-200 bg-white px-2 py-1 text-xs text-neutral-800 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        />
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                {{ __('Items in categories with the same group name will be summed under that group. Leave blank to keep a category separate.') }}
            </p>
        </div>
    @endif

    @php
        $grandTotalCents = $rows->sum(fn ($r) => (int) ($r->total_cents ?? 0));
        $grandDiscountCents = $rows->sum(fn ($r) => (int) ($r->discount_cents ?? 0));
        $grandQty = $rows->sum(fn ($r) => (float) ($r->qty_total ?? 0));
        $currentGroupBy = $groupBy ?? $group_by ?? 'category';
        $categoryGroups = $categoryGroups ?? [];

        if ($currentGroupBy === 'none') {
            $grouped = collect(['All Items' => $rows]);
        } elseif ($currentGroupBy === 'custom') {
            $grouped = $rows->groupBy(function ($r) use ($categoryGroups) {
                $catId = $r->category_id ?? null;
                $groupName = $catId !== null && isset($categoryGroups[$catId]) && $categoryGroups[$catId] !== ''
                    ? $categoryGroups[$catId]
                    : ($r->category_name ?? __('Uncategorized'));

                return $groupName;
            });
        } else {
            $grouped = $rows->groupBy(fn ($r) => $r->category_name ?? __('Uncategorized'));
        }
    @endphp

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Discount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($grouped as $categoryName => $items)
                    <tr class="bg-neutral-100 dark:bg-neutral-800">
                        <td colspan="5" class="px-3 py-2 text-sm font-bold text-neutral-900 dark:text-neutral-100">{{ $categoryName }}</td>
                    </tr>
                    @foreach ($items as $row)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-1.5 pl-6 text-sm text-neutral-700 dark:text-neutral-200">{{ $row->item_name ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($row->qty_total ?? 0), 3) }}</td>
                            <td class="px-3 py-1.5 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney((int) ($row->avg_unit_price_cents ?? 0)) }}</td>
                            <td class="px-3 py-1.5 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $this->formatMoney((int) ($row->discount_cents ?? 0)) }}</td>
                            <td class="px-3 py-1.5 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney((int) ($row->total_cents ?? 0)) }}</td>
                        </tr>
                    @endforeach
                    @if ($currentGroupBy !== 'none')
                        @php
                            $groupDiscountCents = $items->sum(fn ($r) => (int) ($r->discount_cents ?? 0));
                        @endphp
                        <tr class="bg-neutral-50 dark:bg-neutral-800/50">
                            <td class="px-3 py-1.5 pl-6 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Category Total') }}</td>
                            <td class="px-3 py-1.5 text-sm text-right font-semibold text-neutral-800 dark:text-neutral-200">{{ number_format($items->sum(fn ($r) => (float) ($r->qty_total ?? 0)), 3) }}</td>
                            <td class="px-3 py-1.5"></td>
                            <td class="px-3 py-1.5 text-sm text-right font-semibold text-neutral-800 dark:text-neutral-200">{{ $this->formatMoney($groupDiscountCents) }}</td>
                            <td class="px-3 py-1.5 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($items->sum(fn ($r) => (int) ($r->total_cents ?? 0))) }}</td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No sales found.') }}</td></tr>
                @endforelse
                @if ($grouped->isNotEmpty())
                    <tr class="bg-neutral-200 dark:bg-neutral-700">
                        <td class="px-3 py-2 text-sm font-bold text-neutral-900 dark:text-neutral-100">{{ __('GRAND TOTAL') }}</td>
                        <td class="px-3 py-2 text-sm text-right font-bold text-neutral-900 dark:text-neutral-100">{{ number_format($grandQty, 3) }}</td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-sm text-right font-bold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($grandDiscountCents) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-bold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($grandTotalCents) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
