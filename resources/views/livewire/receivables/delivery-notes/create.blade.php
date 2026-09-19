<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Services\AR\DeliveryNoteService;
use App\Support\Money\MinorUnits;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?DeliveryNote $deliveryNote = null;
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public string $delivery_date = '';
    public string $delivery_address = '';
    public string $reference = '';
    public string $notes = '';
    public array $items = [];

    public function mount(?DeliveryNote $deliveryNote = null): void
    {
        $this->deliveryNote = $deliveryNote?->exists ? $deliveryNote->load('items') : null;
        if ($this->deliveryNote && $this->deliveryNote->status !== 'draft') {
            $this->redirectRoute('delivery-notes.show', $this->deliveryNote, navigate: true); return;
        }
        $allowed = auth()->user()?->allowedBranchIds() ?? [];
        $this->branch_id = $this->deliveryNote?->branch_id ?? (int) ($allowed[0] ?? 0);
        $this->customer_id = $this->deliveryNote?->customer_id;
        $this->delivery_date = $this->deliveryNote?->delivery_date?->format('Y-m-d') ?? now()->toDateString();
        $this->delivery_address = $this->deliveryNote?->delivery_address_snapshot ?? '';
        $this->reference = $this->deliveryNote?->reference ?? '';
        $this->notes = $this->deliveryNote?->notes ?? '';
        $this->items = $this->deliveryNote?->items->map(fn ($item) => [
            'description' => $item->description, 'qty' => (string) $item->qty, 'unit' => $item->unit ?? '',
            'unit_price' => MinorUnits::format($item->unit_price_cents, null, false), 'line_notes' => $item->line_notes ?? '',
        ])->all() ?? [];
        if ($this->items === []) $this->addItem();
    }

    public function updatedCustomerId(): void
    {
        $customer = Customer::find($this->customer_id);
        if ($customer && blank($this->delivery_address)) $this->delivery_address = (string) $customer->delivery_address;
    }

    public function addItem(): void { $this->items[] = ['description' => '', 'qty' => '1', 'unit' => '', 'unit_price' => $this->moneyZero(), 'line_notes' => '']; }
    public function removeItem(int $index): void { unset($this->items[$index]); $this->items = array_values($this->items); if ($this->items === []) $this->addItem(); }
    private function moneyZero(): string { return MinorUnits::format(0, null, false); }

    public function save(DeliveryNoteService $service): void
    {
        abort_unless(auth()->user()?->can('finance.write'), 403);
        $data = $this->validate([
            'branch_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer'],
            'delivery_date' => ['required', 'date'], 'delivery_address' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999'], 'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.line_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['items'] = collect($data['items'])->map(function ($item) {
            try { $item['unit_price_cents'] = MinorUnits::parsePos((string) $item['unit_price']); }
            catch (\InvalidArgumentException) { throw \Illuminate\Validation\ValidationException::withMessages(['items' => __('One or more item prices are invalid.')]); }
            return $item;
        })->all();
        $note = $this->deliveryNote
            ? $service->updateDraft($this->deliveryNote, $data, auth()->id())
            : $service->createDraft($data, auth()->id());
        session()->flash('status', __('Delivery note saved.'));
        $this->redirectRoute('delivery-notes.show', $note, navigate: true);
    }

    public function with(): array
    {
        $branchIds = auth()->user()?->allowedBranchIds() ?? [];
        return ['branches' => Branch::whereIn('id', $branchIds)->where('is_active', true)->orderBy('name')->get(), 'customers' => Customer::active()->orderBy('name')->get(['id', 'name', 'phone'])];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between"><div><h1 class="text-xl font-semibold">{{ $deliveryNote ? __('Edit Delivery Note') : __('New Delivery Note') }}</h1><p class="text-sm text-neutral-500">{{ __('Record delivered quantities. Prices are retained only for later invoice generation and are excluded from the printed note.') }}</p></div><flux:button :href="route('delivery-notes.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button></div>
    <form wire:submit="save" class="space-y-5">
        <div class="grid gap-4 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 md:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model="branch_id" :label="__('Branch')" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="customer_id" :label="__('Customer')" required><option value="">{{ __('Select customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' · '.$customer->phone : '' }}</option>@endforeach</flux:select>
            <flux:input wire:model="delivery_date" type="date" :label="__('Delivery Date')" required />
            <flux:input wire:model="reference" :label="__('Reference')" />
            <div class="md:col-span-2"><flux:textarea wire:model="delivery_address" :label="__('Delivery Address')" rows="2" /></div><div class="md:col-span-2"><flux:textarea wire:model="notes" :label="__('Notes')" rows="2" /></div>
        </div>
        <div class="overflow-x-auto rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"><table class="min-w-[900px] w-full text-sm"><thead class="bg-neutral-50 dark:bg-neutral-800"><tr><th class="px-3 py-3 text-left">{{ __('Description') }}</th><th class="w-28 px-3 py-3">{{ __('Qty') }}</th><th class="w-28 px-3 py-3">{{ __('Unit') }}</th><th class="w-36 px-3 py-3">{{ __('Invoice Price') }}</th><th class="px-3 py-3 text-left">{{ __('Line Notes') }}</th><th class="w-20"></th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@foreach($items as $index => $item)<tr wire:key="delivery-item-{{ $index }}"><td class="p-2"><flux:input wire:model="items.{{ $index }}.description" /></td><td class="p-2"><flux:input wire:model="items.{{ $index }}.qty" type="number" min="0.001" step="0.001" /></td><td class="p-2"><flux:input wire:model="items.{{ $index }}.unit" /></td><td class="p-2"><flux:input wire:model="items.{{ $index }}.unit_price" type="number" min="0" step="0.001" /></td><td class="p-2"><flux:input wire:model="items.{{ $index }}.line_notes" /></td><td class="p-2"><flux:button type="button" wire:click="removeItem({{ $index }})" variant="danger" size="sm">{{ __('Remove') }}</flux:button></td></tr>@endforeach</tbody></table></div>
        @error('items')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="flex justify-between"><flux:button type="button" wire:click="addItem">{{ __('Add Line') }}</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled"><span wire:loading.remove wire:target="save">{{ __('Save Delivery Note') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span></flux:button></div>
    </form>
</div>
