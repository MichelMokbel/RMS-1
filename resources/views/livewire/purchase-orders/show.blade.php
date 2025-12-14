<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public PurchaseOrder $purchaseOrder;
    public array $receipts = [];
    public ?string $receive_notes = null;
    public array $receive_costs = [];

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->purchaseOrder = $purchaseOrder->load(['items.item', 'supplier', 'creator']);
        foreach ($this->purchaseOrder->items as $item) {
            $this->receipts[$item->id] = $item->remainingToReceive();
            $this->receive_costs[$item->id] = $item->unit_price;
        }
    }

    public function approve(): void
    {
        if (! $this->purchaseOrder->isPending()) {
            $this->addError('status', __('Only pending purchase orders can be approved.'));
            return;
        }
        if (! $this->purchaseOrder->supplier_id || $this->purchaseOrder->items()->count() === 0) {
            $this->addError('status', __('Supplier and at least one line are required.'));
            return;
        }
        $this->purchaseOrder->update(['status' => PurchaseOrder::STATUS_APPROVED]);
        $this->refreshPo();
        session()->flash('status', __('Purchase order approved.'));
    }

    public function cancel(): void
    {
        $po = $this->purchaseOrder->load('items');
        if ($po->isReceived()) {
            $this->addError('status', __('Cannot cancel a received PO.'));
            return;
        }
        if ($po->isApproved() && $po->items->sum('received_quantity') > 0) {
            $this->addError('status', __('Cannot cancel after receiving items.'));
            return;
        }
        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        $this->refreshPo();
        session()->flash('status', __('Purchase order cancelled.'));
    }

    public function receive(PurchaseOrderReceivingService $receivingService): void
    {
        if (! $this->purchaseOrder->isApproved()) {
            $this->addError('status', __('Only approved purchase orders can be received.'));
            return;
        }

        $receipts = collect($this->receipts)->map(fn ($qty) => (int) $qty)->toArray();
        $costs = collect($this->receive_costs)->map(fn ($cost) => $cost === '' ? null : (float) $cost)->toArray();

        try {
            $po = $receivingService->receive($this->purchaseOrder, $receipts, auth()->id(), $this->receive_notes, $costs);
            $this->purchaseOrder = $po;
            $this->receive_notes = null;
            foreach ($this->purchaseOrder->items as $item) {
                $this->receipts[$item->id] = $item->remainingToReceive();
                $this->receive_costs[$item->id] = $item->unit_price;
            }
            session()->flash('status', __('Items received.'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, implode(' ', $messages));
            }
        }
    }

    private function refreshPo(): void
    {
        $this->purchaseOrder = $this->purchaseOrder->fresh(['items.item', 'supplier', 'creator']);
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Purchase Order') }} #{{ $purchaseOrder->po_number }}
            </h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Status:') }} {{ ucfirst($purchaseOrder->status) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('purchase-orders.index')" wire:navigate variant="ghost">
                {{ __('Back') }}
            </flux:button>
            @if($purchaseOrder->canEditLines())
                <flux:button :href="route('purchase-orders.edit', $purchaseOrder)" wire:navigate>
                    {{ __('Edit') }}
                </flux:button>
            @endif
            @if($purchaseOrder->isPending())
                <flux:button type="button" wire:click="approve">{{ __('Approve') }}</flux:button>
            @endif
            @if(! $purchaseOrder->isReceived())
                <flux:button type="button" wire:click="cancel" variant="ghost">{{ __('Cancel') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('status')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Header') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier:') }} {{ $purchaseOrder->supplier->name ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Order Date:') }} {{ $purchaseOrder->order_date?->format('Y-m-d') ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Expected Delivery:') }} {{ $purchaseOrder->expected_delivery_date?->format('Y-m-d') ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                {{ __('Created By:') }}
                {{ $purchaseOrder->creator->name ?? $purchaseOrder->creator->username ?? $purchaseOrder->creator->email ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Notes:') }} {{ $purchaseOrder->notes ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment Terms:') }} {{ $purchaseOrder->payment_terms ?? '—' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment Type:') }} {{ $purchaseOrder->payment_type ?? '—' }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Totals') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Total Amount:') }} {{ number_format((float) $purchaseOrder->total_amount, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Received Date:') }} {{ $purchaseOrder->received_date?->format('Y-m-d') ?? '—' }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200 mb-3">{{ __('Lines') }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Ordered') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Received') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                    @foreach ($purchaseOrder->items as $line)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->item?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->quantity }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->received_quantity ?? 0 }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->remainingToReceive() }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $line->unit_price, 2) }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $line->total_price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($purchaseOrder->isApproved())
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Receive Items') }}</h3>
            <div class="space-y-3">
                @foreach ($purchaseOrder->items as $line)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-5 items-center">
                        <div class="md:col-span-2 text-sm text-neutral-900 dark:text-neutral-100">
                            <p class="font-semibold">{{ $line->item?->name ?? '—' }}</p>
                            <p class="text-neutral-600 dark:text-neutral-300 text-xs">{{ __('Remaining:') }} {{ $line->remainingToReceive() }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <flux:input
                                wire:model="receipts.{{ $line->id }}"
                                type="number"
                                min="0"
                                :max="$line->remainingToReceive()"
                                :label="__('Receive now')"
                            />
                        </div>
                        <div class="md:col-span-2">
                            <flux:input
                                wire:model="receive_costs.{{ $line->id }}"
                                type="number"
                                step="0.01"
                                min="0"
                                :label="__('Actual Unit Cost (pkg)')"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Ordered:') }} {{ $line->quantity }}</p>
                        </div>
                    </div>
                @endforeach
                <flux:textarea wire:model="receive_notes" :label="__('Notes')" rows="2" />
                <div class="flex justify-end">
                    <flux:button type="button" wire:click="receive" variant="primary">{{ __('Receive Selected') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
