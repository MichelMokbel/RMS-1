<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\DailyDishMenu;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Order $order;
    public ?string $notes = null;
    public array $items = [];
    public bool $itemsEditable = true;

    public function mount(): void
    {
        $this->order->loadMissing('items');
        $this->notes = $this->order->notes;
        $this->itemsEditable = ! $this->order->isSubscriptionGenerated() && in_array($this->order->status, ['Draft','Confirmed'], true);
        $this->items = $this->order->items->map(function (OrderItem $item) {
            return [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'description_snapshot' => $item->description_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_amount' => (float) $item->discount_amount,
                'line_total' => (float) $item->line_total,
                'status' => $item->status,
                'sort_order' => $item->sort_order,
            ];
        })->toArray();
    }

    public function save(OrderTotalsService $totalsService): void
    {
        if (! $this->itemsEditable) {
            $this->order->notes = $this->notes;
            $this->order->save();
            session()->flash('status', __('Notes updated.'));
            return;
        }

        $data = $this->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['array', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.status' => ['required', 'in:Pending,InProduction,Ready,Completed,Cancelled'],
            'items.*.sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($data, $totalsService) {
            $this->order->notes = $data['notes'];
            $this->order->save();

            foreach ($this->items as $row) {
                $item = OrderItem::find($row['id']);
                if (! $item) {
                    continue;
                }
                $qty = (float) $row['quantity'];
                $price = (float) $row['unit_price'];
                $lineTotal = round($qty * $price, 3);

                $item->update([
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => 0,
                    'line_total' => $lineTotal,
                    'status' => $row['status'],
                    'sort_order' => $row['sort_order'] ?? 0,
                ]);
            }

            $totalsService->recalc($this->order);
        });

        session()->flash('status', __('Order updated.'));
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Order') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $order->order_number }}</h1>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $order->source }} · {{ $order->type }} · {{ $order->status }}</p>
        </div>
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>
            @if(! $itemsEditable)
                <span class="text-xs text-amber-600 dark:text-amber-300">{{ __('Subscription orders are read-only for items.') }}</span>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Price') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Line Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach ($items as $index => $row)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['description_snapshot'] }}</td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.quantity" type="number" step="0.001" class="w-24" :disabled="!$itemsEditable" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.unit_price" type="number" step="0.001" class="w-24" :disabled="!$itemsEditable" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.status" class="rounded-md border border-neutral-200 bg-white px-2 py-1 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $itemsEditable)>
                                    <option value="Pending">{{ __('Pending') }}</option>
                                    <option value="InProduction">{{ __('In Production') }}</option>
                                    <option value="Ready">{{ __('Ready') }}</option>
                                    <option value="Completed">{{ __('Completed') }}</option>
                                    <option value="Cancelled">{{ __('Cancelled') }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                {{ number_format((float) ($row['quantity'] * $row['unit_price']), 3) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
    </div>
</div>

