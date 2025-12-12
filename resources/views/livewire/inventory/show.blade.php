<?php

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryStockService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public InventoryItem $item;
    public string $direction = 'increase';
    public int $quantity = 1;
    public ?string $notes = null;
    public bool $showToggleConfirm = false;

    public function with(): array
    {
        return [
            'transactions' => $this->item->transactions()->limit(50)->get(),
        ];
    }

    public function adjust(InventoryStockService $stockService): void
    {
        $this->validate([
            'direction' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $delta = $this->direction === 'increase' ? $this->quantity : -$this->quantity;
        $stockService->adjustStock($this->item, $delta, $this->notes, auth()->id());

        $this->reset(['quantity', 'notes']);
        $this->dispatch('$refresh');
        session()->flash('status', __('Stock adjusted.'));
    }

    public function toggleStatus(): void
    {
        $this->item->update(['status' => $this->item->status === 'active' ? 'discontinued' : 'active']);
        session()->flash('status', $this->item->status === 'active' ? __('Item activated.') : __('Item discontinued.'));
        $this->dispatch('$refresh');
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $item->name }} ({{ $item->item_code }})
            </h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $item->description }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">
                {{ __('Back') }}
            </flux:button>
            @if(auth()->user()->hasAnyRole(['admin','manager']))
                <flux:button :href="route('inventory.edit', $item)" wire:navigate variant="primary">
                    {{ __('Edit') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if(auth()->user()->hasAnyRole(['admin','manager']))
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-1">{{ __('Item Status') }}</h2>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Current status:') }} {{ ucfirst($item->status) }}</p>
            </div>
            <flux:button size="sm" variant="danger" wire:click="toggleStatus">
                {{ $item->status === 'active' ? __('Discontinue') : __('Activate') }}
            </flux:button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">{{ __('Details') }}</h2>
            <dl class="space-y-1 text-sm text-neutral-700 dark:text-neutral-200">
                <div><span class="font-semibold">{{ __('Code') }}:</span> {{ $item->item_code }}</div>
                <div><span class="font-semibold">{{ __('Category') }}:</span> {{ $item->category?->name }}</div>
                <div><span class="font-semibold">{{ __('Supplier') }}:</span> {{ $item->supplier?->name }}</div>
                <div><span class="font-semibold">{{ __('Location') }}:</span> {{ $item->location }}</div>
                <div><span class="font-semibold">{{ __('Package') }}:</span> {{ $item->package_label }} ({{ $item->units_per_package }} {{ $item->unit_of_measure }})</div>
                <div><span class="font-semibold">{{ __('Status') }}:</span> {{ ucfirst($item->status) }}</div>
            </dl>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">{{ __('Stock & Cost') }}</h2>
            <dl class="space-y-1 text-sm text-neutral-700 dark:text-neutral-200">
                <div>
                    <span class="font-semibold">{{ __('Current Stock') }}:</span>
                    {{ $item->current_stock }}
                    @if ($item->isLowStock())
                        <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">{{ __('Low') }}</span>
                    @endif
                </div>
                <div><span class="font-semibold">{{ __('Minimum Stock') }}:</span> {{ $item->minimum_stock }}</div>
                <div><span class="font-semibold">{{ __('Package Cost') }}:</span> {{ $item->cost_per_unit }}</div>
                <div><span class="font-semibold">{{ __('Per Unit Cost') }}:</span> {{ $item->perUnitCost() ?? '—' }}</div>
                <div><span class="font-semibold">{{ __('Last Cost Update') }}:</span> {{ optional($item->last_cost_update)->format('Y-m-d H:i') ?? '—' }}</div>
            </dl>
        </div>
    </div>

    @if(auth()->user()->hasAnyRole(['admin','manager']))
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-3">{{ __('Adjust Stock') }}</h2>
            <form wire:submit="adjust" class="grid grid-cols-1 gap-3 md:grid-cols-4 md:items-end">
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Direction') }}</label>
                    <select wire:model="direction" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="increase">{{ __('Increase') }}</option>
                        <option value="decrease">{{ __('Decrease') }}</option>
                    </select>
                </div>
                <flux:input wire:model="quantity" type="number" min="1" :label="__('Quantity')" />
                <flux:input wire:model="notes" :label="__('Notes')" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('Apply') }}</flux:button>
                </div>
            </form>
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-3">{{ __('Recent Transactions') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full table-auto text-sm divide-y divide-neutral-200 dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-800/50 text-neutral-700 dark:text-neutral-200">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Quantity') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Reference') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('User') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Notes') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700 text-neutral-800 dark:text-neutral-100">
                    @forelse ($transactions as $tx)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/60">
                            <td class="px-3 py-2 whitespace-nowrap">{{ optional($tx->transaction_date)->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2 capitalize whitespace-nowrap">{{ $tx->transaction_type }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @php $d = $tx->delta(); @endphp
                                {{ $d > 0 ? '+' : '' }}{{ $d }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ trim($tx->reference_type.' '.$tx->reference_id) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $tx->user?->username ?? $tx->user?->email ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $tx->notes }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-4 text-center text-neutral-600 dark:text-neutral-300">{{ __('No transactions') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
