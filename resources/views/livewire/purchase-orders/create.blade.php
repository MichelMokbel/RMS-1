<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderNumberService;
use App\Services\Purchasing\PurchaseOrderFormQueryService;
use App\Services\Purchasing\PurchaseOrderPersistService;
use App\Support\Purchasing\PurchaseOrderRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $po_number = '';
    public ?int $supplier_id = null;
    public ?string $order_date = null;
    public ?string $expected_delivery_date = null;
    public ?string $notes = null;
    public ?string $payment_terms = null;
    public ?string $payment_type = null;
    public array $payment_term_options = ['Due on receipt', 'Credit', 'Net 15', 'Net 30', 'Net 60'];
    public array $payment_type_options = ['Cash', 'Card', 'Bank Transfer', 'Cheque'];
    public ?string $new_payment_term = null;
    public ?string $new_payment_type = null;
    public array $lines = [];
    public array $items = [];

    public function mount(PurchaseOrderNumberService $numberService, PurchaseOrderFormQueryService $queryService): void
    {
        $this->po_number = $numberService->generate();
        $this->items = $queryService->inventoryItemsArray();
        $this->lines = [
            ['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0],
        ];
    }

    public function with(PurchaseOrderFormQueryService $queryService): array
    {
        return [
            'suppliers' => $queryService->suppliers(),
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = ['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function updated($name, $value): void
    {
        $this->maybeSyncUnitPrice($name, $value);
    }

    public function updatedLines($value, $name): void
    {
        $this->maybeSyncUnitPrice("lines.$name", $value);
    }

    private function maybeSyncUnitPrice(string $name, $value): void
    {
        if (str_contains($name, '.item_id')) {
            $parts = explode('.', $name);
            $idx = $parts[1] ?? null;
            if ($idx !== null) {
                $this->syncUnitPriceFromItem((int) $value, (int) $idx);
            }
        }
    }

    private function syncUnitPriceFromItem(int $itemId, int $index): void
    {
        $item = collect($this->items)->firstWhere('id', $itemId);
        $cost = $item['cost_per_unit'] ?? 0;
        if (isset($this->lines[$index])) {
            $this->lines[$index]['unit_price'] = round((float) $cost, 2);
        }
    }

    public function syncPrice(int $index): void
    {
        $itemId = $this->lines[$index]['item_id'] ?? null;
        if ($itemId) {
            $this->syncUnitPriceFromItem((int) $itemId, $index);
            $this->maybeAppendNewLine($index);
        }
    }

    private function maybeAppendNewLine(int $index): void
    {
        if ($index === array_key_last($this->lines)) {
            $this->lines[] = ['item_id' => null, 'quantity' => 1.0, 'unit_price' => 0];
        }
    }

    public function saveDraft(PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        $this->persist(PurchaseOrder::STATUS_DRAFT, $persist, $rules);
    }

    public function submitPending(PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        $this->persist(PurchaseOrder::STATUS_PENDING, $persist, $rules);
    }

    private function persist(string $status, PurchaseOrderPersistService $persist, PurchaseOrderRules $rules): void
    {
        $filtered = $this->filteredLines();
        if (count($filtered) === 0) {
            $this->addError('lines', __('Add at least one line with item, quantity, and price.'));
            return;
        }
        $this->lines = $filtered;

        $data = $this->validate($rules->createRules());

        if ($status === PurchaseOrder::STATUS_PENDING && ! $data['supplier_id']) {
            $this->addError('supplier_id', __('Supplier is required to submit.'));
            return;
        }

        $po = $persist->create($data, $status, Auth::id());

        session()->flash('status', __('Purchase order saved.'));
        $this->redirectRoute('purchase-orders.show', $po, navigate: true);
    }

    private function filteredLines(): array
    {
        return collect($this->lines)
            ->filter(function ($line) {
                return ! empty($line['item_id'])
                    && isset($line['quantity']) && (float) $line['quantity'] > 0.0005
                    && isset($line['unit_price']) && $line['unit_price'] !== '';
            })
            ->values()
            ->toArray();
    }

    public function addPaymentTerm(): void
    {
        $value = trim((string) $this->new_payment_term);
        if ($value !== '' && ! in_array($value, $this->payment_term_options, true)) {
            $this->payment_term_options[] = $value;
            $this->payment_terms = $value;
        }
        $this->new_payment_term = null;
    }

    public function addPaymentType(): void
    {
        $value = trim((string) $this->new_payment_type);
        if ($value !== '' && ! in_array($value, $this->payment_type_options, true)) {
            $this->payment_type_options[] = $value;
            $this->payment_type = $value;
        }
        $this->new_payment_type = null;
    }

}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Create Purchase Order') }}
        </h1>
        <flux:button :href="route('purchase-orders.index')" wire:navigate variant="ghost">
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="saveDraft" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="po_number" :label="__('PO Number')" required maxlength="50" />
                <div>
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select supplier') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <p class="text-sm text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="order_date" type="date" :label="__('Order Date')" />
                <flux:input wire:model="expected_delivery_date" type="date" :label="__('Expected Delivery')" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Payment Terms') }}</label>
                    <select wire:model="payment_terms" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select terms') }}</option>
                        @foreach ($payment_term_options as $term)
                            <option value="{{ $term }}">{{ $term }}</option>
                        @endforeach
                    </select>
                    <div class="flex items-center gap-2">
                        <flux:input wire:model="new_payment_term" placeholder="{{ __('Add new term') }}" class="flex-1" />
                        <flux:button type="button" wire:click="addPaymentTerm" size="sm">{{ __('Add') }}</flux:button>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-neutral-800 dark:text-neutral-200 mb-1">{{ __('Payment Type') }}</label>
                    <select wire:model="payment_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select type') }}</option>
                        @foreach ($payment_type_options as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <div class="flex items-center gap-2">
                        <flux:input wire:model="new_payment_type" placeholder="{{ __('Add new type') }}" class="flex-1" />
                        <flux:button type="button" wire:click="addPaymentType" size="sm">{{ __('Add') }}</flux:button>
                    </div>
                </div>
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Line Items') }}</h2>
                <flux:button type="button" wire:click="addLine">{{ __('Add line') }}</flux:button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">#</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Unit Price') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Line Total') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @foreach ($lines as $index => $line)
                            <tr class="align-top">
                                <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 text-sm">
                                    <select wire:model="lines.{{ $index }}.item_id" wire:change="syncPrice({{ $index }})" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                        <option value="">{{ __('Select item') }}</option>
                                @foreach ($items as $item)
                                    <option value="{{ $item['id'] }}">[{{ $item['item_code'] }}] {{ $item['name'] }}</option>
                                @endforeach
                            </select>
                                    @error("lines.$index.item_id") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <flux:input wire:model.live="lines.{{ $index }}.quantity" type="number" min="0.001" step="0.001" />
                                    @error("lines.$index.quantity") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <flux:input wire:model.live="lines.{{ $index }}.unit_price" type="number" step="0.01" min="0" />
                                    @error("lines.$index.unit_price") <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2) }}
                                </td>
                                <td class="px-3 py-3 text-sm text-right">
                                    <flux:button type="button" wire:click="removeLine({{ $index }})" variant="ghost">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-end gap-3">
                <div class="text-right">
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total Amount') }}</p>
                    <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ number_format(collect($lines)->sum(fn ($l) => (float) ($l['quantity'] ?? 0) * (float) ($l['unit_price'] ?? 0)), 2) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="button" wire:click="saveDraft">{{ __('Save Draft') }}</flux:button>
            <flux:button type="button" wire:click="submitPending" variant="primary">{{ __('Submit (Pending)') }}</flux:button>
        </div>
    </form>
</div>
