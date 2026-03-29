<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AP\PurchaseOrderInvoiceMatchingService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public PurchaseOrder $purchaseOrder;
    public array $receipts = [];
    public ?string $receive_notes = null;
    public ?string $receive_date = null;
    public array $receive_costs = [];

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->purchaseOrder = $purchaseOrder->load(['items.item', 'supplier', 'creator']);
        foreach ($this->purchaseOrder->items as $item) {
            $this->receipts[$item->id] = $item->remainingToReceive();
            $this->receive_costs[$item->id] = $item->unit_price;
        }
        $this->receive_date = now()->format('Y-m-d\TH:i');
    }

    public function approve(PurchaseOrderWorkflowService $workflow): void
    {
        try {
            $this->purchaseOrder = $workflow->approve($this->purchaseOrder);
            session()->flash('status', __('Purchase order approved.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Approve failed.');
            $this->addError('status', $message);
        }
    }

    public function voidOrder(PurchaseOrderWorkflowService $workflow): void
    {
        try {
            $this->purchaseOrder = $workflow->cancel($this->purchaseOrder);
            session()->flash('status', __('Purchase order voided.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Void failed.');
            $this->addError('status', $message);
        }
    }

    public function receive(PurchaseOrderReceivingService $receivingService): void
    {
        if (! $this->purchaseOrder->isApproved()) {
            $this->addError('status', __('Only approved purchase orders can be received.'));
            return;
        }

        $receipts = collect($this->receipts)->map(fn ($qty) => (float) $qty)->toArray();
        $costs = collect($this->receive_costs)->map(fn ($cost) => $cost === '' ? null : (float) $cost)->toArray();

        try {
            $po = $receivingService->receive($this->purchaseOrder, $receipts, Auth::id(), $this->receive_notes, $costs, $this->receive_date);
            $this->purchaseOrder = $po;
            $this->receive_notes = null;
            $this->receive_date = now()->format('Y-m-d\TH:i');
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

    public function accrualRows(): \Illuminate\Support\Collection
    {
        return app(PurchaseOrderInvoiceMatchingService::class)
            ->purchaseAccrualRows((int) ($this->purchaseOrder->company_id ?? 0), [])
            ->where('purchase_order_id', (int) $this->purchaseOrder->id)
            ->values();
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
            <flux:button :href="route('purchase-orders.document-print', $purchaseOrder)" target="_blank" variant="ghost">
                {{ __('Print') }}
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
                <flux:button type="button" wire:click="voidOrder" variant="ghost">{{ __('Void') }}</flux:button>
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
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Supplier:') }} {{ $purchaseOrder->supplier->name ?? '-' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Order Date:') }} {{ $purchaseOrder->order_date?->format('Y-m-d') ?? '-' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Expected Delivery:') }} {{ $purchaseOrder->expected_delivery_date?->format('Y-m-d') ?? '-' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                {{ __('Created By:') }}
                {{ $purchaseOrder->creator->name ?? $purchaseOrder->creator->username ?? $purchaseOrder->creator->email ?? '-' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Notes:') }} {{ $purchaseOrder->notes ?? '-' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment Terms:') }} {{ $purchaseOrder->payment_terms ?? '-' }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Payment Type:') }} {{ $purchaseOrder->payment_type ?? '-' }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Totals') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Total Amount:') }} {{ number_format((float) $purchaseOrder->total_amount, 2) }}</p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Received Date:') }} {{ $purchaseOrder->received_date?->format('Y-m-d') ?? '-' }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="mb-3 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Lines') }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Ordered') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Received') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Matched') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                    @foreach ($purchaseOrder->items as $line)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            @php($accrual = $this->accrualRows()->firstWhere('purchase_order_item_id', $line->id))
                            <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                <div>{{ $line->item?->name ?? '—' }}</div>
                                @if (filled($line->line_notes))
                                    <div class="mt-1 whitespace-pre-wrap text-xs text-neutral-500 dark:text-neutral-400">{{ $line->line_notes }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->quantity }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->received_quantity ?? 0 }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($accrual['matched_quantity'] ?? $line->invoiceMatches->sum('matched_quantity')), 3) }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->remainingToReceive() }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $line->unit_price, 2) }}</td>
                            <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $line->total_price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
    </div>

    @if($this->accrualRows()->isNotEmpty())
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="mb-3 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Purchase Accruals / GRNI') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining Qty') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Accrual Value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach($this->accrualRows() as $row)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['item_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float) $row['remaining_quantity'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $row['accrual_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

    @if($purchaseOrder->isApproved())
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Receive Items') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Ordered') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Received') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Remaining') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Receive Now') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actual Unit Cost (pkg)') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                        @foreach ($purchaseOrder->items as $line)
                            <tr class="align-top hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->item?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->quantity }}</td>
                                <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->received_quantity ?? 0 }}</td>
                                <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->remainingToReceive() }}</td>
                                <td class="px-3 py-3">
                                    <flux:input
                                        wire:model="receipts.{{ $line->id }}"
                                        type="number"
                                        min="0"
                                        :max="$line->remainingToReceive()"
                                    />
                                </td>
                                <td class="px-3 py-3">
                                    <flux:input
                                        wire:model="receive_costs.{{ $line->id }}"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="space-y-3">
                <flux:input wire:model="receive_date" type="datetime-local" :label="__('Receive Date')" />
                <flux:textarea wire:model="receive_notes" :label="__('Notes')" rows="2" />
                <div class="flex justify-end">
                    <flux:button type="button" wire:click="receive" variant="primary">{{ __('Receive Selected') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
