<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $source = 'Backoffice';
    public bool $is_daily_dish = true;
    public string $type = 'Delivery';
    public string $status = 'Confirmed';
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?string $customer_name_snapshot = null;
    public ?string $customer_phone_snapshot = null;
    public ?string $delivery_address_snapshot = null;
    public string $scheduled_date;
    public ?string $scheduled_time = null;
    public ?string $notes = null;
    public float $order_discount_amount = 0.0;

    public ?int $menu_id = null;
    public array $selected_items = [];
    public array $item_search = [];
    public string $menu_item_code = '';
    public string $menu_item_name = '';
    public ?int $menu_item_category_id = null;
    public float $menu_item_price = 0.0;
    public bool $menu_item_is_active = true;

    public function mount(): void
    {
        $this->scheduled_date = now()->toDateString();
        $this->menu_item_is_active = true;
        if (! $this->is_daily_dish) {
            $this->addItemRow();
        }
    }

    public function with(): array
    {
        $menus = DailyDishMenu::with('items.menuItem')
            ->where('status', 'published')
            ->where('branch_id', $this->branch_id)
            ->orderByDesc('service_date')
            ->limit(30)
            ->get();

        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && $this->customer_search !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        $menuItemOptions = [];
        if (! $this->is_daily_dish && Schema::hasTable('menu_items')) {
            foreach ($this->item_search as $idx => $term) {
                $term = trim((string) $term);
                if ($term === '' || ! empty($this->selected_items[$idx]['menu_item_id'])) {
                    continue;
                }

                $menuItemOptions[$idx] = MenuItem::query()
                    ->active()
                    ->search($term)
                    ->ordered()
                    ->limit(15)
                    ->get();
            }
        }

        return [
            'customers' => $customers,
            'menus' => $menus,
            'menu_item_options' => $menuItemOptions,
            'categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
        ];
    }

    public function updatedBranchId(): void
    {
        $this->menu_id = null;
        $this->selected_items = [];
        $this->item_search = [];
        if (! $this->is_daily_dish) {
            $this->addItemRow();
        }
    }

    public function updatedIsDailyDish(): void
    {
        $this->menu_id = null;
        $this->selected_items = [];
        $this->item_search = [];

        if (! $this->is_daily_dish) {
            $this->addItemRow();
        }
    }

    public function updatedMenuId(): void
    {
        if (! $this->is_daily_dish) {
            return;
        }

        $menu = $this->menu_id ? DailyDishMenu::with('items')->find($this->menu_id) : null;
        $this->selected_items = $menu
            ? $menu->items
                ->pluck('menu_item_id')
                ->map(fn ($id) => [
                    'menu_item_id' => $id,
                    'quantity' => 1,
                    'unit_price' => null,
                    'discount_amount' => 0,
                    'sort_order' => 0,
                ])
                ->values()
                ->toArray()
            : [];
        $this->item_search = [];
    }

    public function updatedCustomerSearch(): void
    {
        if ($this->customer_id !== null) {
            $this->customer_id = null;
            $this->customer_name_snapshot = null;
            $this->customer_phone_snapshot = null;
        }
    }

    public function updatedCustomerId(): void
    {
        if (! $this->customer_id) {
            $this->customer_name_snapshot = null;
            $this->customer_phone_snapshot = null;
            return;
        }

        $customer = Customer::find($this->customer_id);
        if (! $customer) {
            $this->customer_id = null;
            $this->customer_name_snapshot = null;
            $this->customer_phone_snapshot = null;
            return;
        }

        $this->customer_name_snapshot = $customer->name;
        $this->customer_phone_snapshot = $customer->phone;
        $this->customer_search = trim($customer->name.' '.($customer->phone ?? ''));
    }

    public function selectCustomer(int $customerId): void
    {
        $this->customer_id = $customerId;
        $this->updatedCustomerId();
    }

    public function updatedItemSearch($value, $name): void
    {
        $parts = explode('.', (string) $name);
        $index = (int) end($parts);
        if (! array_key_exists($index, $this->selected_items)) {
            return;
        }

        $this->selected_items[$index]['menu_item_id'] = null;
        $this->selected_items[$index]['unit_price'] = null;
    }

    public function addItemRow(): void
    {
        $this->selected_items[] = [
            'menu_item_id' => null,
            'quantity' => 1,
            'unit_price' => null,
            'discount_amount' => 0,
            'sort_order' => count($this->selected_items),
        ];
        $this->item_search[] = '';
    }

    public function removeItemRow(int $index): void
    {
        unset($this->selected_items[$index], $this->item_search[$index]);
        $this->selected_items = array_values($this->selected_items);
        $this->item_search = array_values($this->item_search);
    }

    public function selectMenuItem(int $index, int $menuItemId): void
    {
        $menuItem = MenuItem::find($menuItemId);
        if (! $menuItem) {
            return;
        }

        $this->selected_items[$index]['menu_item_id'] = $menuItem->id;
        $this->selected_items[$index]['quantity'] = $this->selected_items[$index]['quantity'] ?? 1;
        $this->selected_items[$index]['unit_price'] = (float) ($menuItem->selling_price_per_unit ?? 0);
        $this->selected_items[$index]['discount_amount'] = $this->selected_items[$index]['discount_amount'] ?? 0;
        $this->selected_items[$index]['sort_order'] = $this->selected_items[$index]['sort_order'] ?? $index;
        $this->item_search[$index] = trim(($menuItem->code ?? '').' '.$menuItem->name);

        $this->ensureTrailingItemRow();
    }

    public function prepareMenuItemModal(): void
    {
        $this->resetErrorBag(['menu_item_code', 'menu_item_name', 'menu_item_category_id', 'menu_item_price']);
        $this->menu_item_code = $this->nextMenuItemCode();
        $this->menu_item_name = '';
        $this->menu_item_category_id = null;
        $this->menu_item_price = 0.0;
        $this->menu_item_is_active = true;
    }

    public function createMenuItem(): void
    {
        $data = $this->validate([
            'menu_item_code' => ['required', 'string', 'max:50', 'unique:menu_items,code'],
            'menu_item_name' => ['required', 'string', 'max:255'],
            'menu_item_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'menu_item_price' => ['required', 'numeric', 'min:0'],
            'menu_item_is_active' => ['required', 'boolean'],
        ]);

        $displayOrder = ((int) MenuItem::query()->max('display_order')) + 1;

        $menuItem = MenuItem::create([
            'code' => $data['menu_item_code'],
            'name' => $data['menu_item_name'],
            'arabic_name' => null,
            'category_id' => $data['menu_item_category_id'],
            'recipe_id' => null,
            'selling_price_per_unit' => $data['menu_item_price'],
            'tax_rate' => 0,
            'is_active' => $data['menu_item_is_active'],
            'display_order' => $displayOrder,
        ]);

        if (! $this->is_daily_dish) {
            $targetIndex = null;
            foreach ($this->selected_items as $idx => $row) {
                if (empty($row['menu_item_id'])) {
                    $targetIndex = $idx;
                    break;
                }
            }
            if ($targetIndex === null) {
                $this->addItemRow();
                $targetIndex = count($this->selected_items) - 1;
            }

            $this->selectMenuItem($targetIndex, $menuItem->id);
        }

        $this->dispatch('modal-close', name: 'create-menu-item');
    }

    private function nextMenuItemCode(): string
    {
        $lastCode = MenuItem::query()
            ->whereNotNull('code')
            ->orderByRaw('CAST(code AS UNSIGNED) DESC')
            ->orderByDesc('id')
            ->value('code');

        if (! $lastCode) {
            return '1';
        }

        if (is_numeric($lastCode)) {
            return (string) (((int) $lastCode) + 1);
        }

        if (preg_match('/^(\D*)(\d+)$/', (string) $lastCode, $matches)) {
            $prefix = $matches[1];
            $number = (int) $matches[2];
            return $prefix.($number + 1);
        }

        return $lastCode.'1';
    }

    private function ensureTrailingItemRow(): void
    {
        if ($this->is_daily_dish) {
            return;
        }

        foreach ($this->selected_items as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $hasPrice = isset($row['unit_price']) && $row['unit_price'] !== '';
            if (empty($row['menu_item_id']) || $qty <= 0 || ! $hasPrice) {
                return;
            }
        }

        $this->addItemRow();
    }

    public function save(OrderNumberService $numberService, OrderTotalsService $totalsService): void
    {
        $data = $this->validate([
            'branch_id' => ['required', 'integer'],
            'source' => ['required', 'in:POS,Phone,WhatsApp,Subscription,Backoffice,Website'],
            'is_daily_dish' => ['boolean'],
            'type' => ['required', 'in:DineIn,Takeaway,Delivery,Pastry'],
            'status' => ['required', 'in:Draft,Confirmed,InProduction,Ready,OutForDelivery,Delivered,Cancelled'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name_snapshot' => ['nullable', 'string', 'max:255'],
            'customer_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'delivery_address_snapshot' => ['nullable', 'string'],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'order_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'menu_id' => ['nullable', 'integer'],
            'selected_items' => ['array'],
            'selected_items.*.menu_item_id' => ['nullable', 'integer'],
            'selected_items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'selected_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'selected_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'selected_items.*.sort_order' => ['nullable', 'integer'],
        ]);

        if ($this->is_daily_dish) {
            $menu = DailyDishMenu::with('items.menuItem')
                ->where('id', $this->menu_id)
                ->where('branch_id', $this->branch_id)
                ->where('status', 'published')
                ->first();
            if (! $menu) {
                $this->addError('menu_id', __('A published daily dish menu is required for daily dish orders.'));
                return;
            }
        }

        $items = collect($data['selected_items'] ?? [])
            ->filter(fn ($row) => ! empty($row['menu_item_id']))
            ->values();

        if ($items->isEmpty()) {
            $this->addError('selected_items', __('Select at least one menu item.'));
            return;
        }

        $menuItems = MenuItem::query()
            ->whereIn('id', $items->pluck('menu_item_id')->all())
            ->get()
            ->keyBy('id');

        if ($menuItems->count() !== $items->count()) {
            $this->addError('selected_items', __('Some menu items could not be found.'));
            return;
        }

        $subtotal = $items->reduce(function (float $carry, array $row): float {
            $qty = (float) ($row['quantity'] ?? 1);
            $price = (float) ($row['unit_price'] ?? 0);
            $discount = (float) ($row['discount_amount'] ?? 0);
            return $carry + max(0, ($qty * $price) - $discount);
        }, 0.0);
        $orderDiscount = (float) ($data['order_discount_amount'] ?? 0);
        if ($orderDiscount > $subtotal) {
            $this->addError('order_discount_amount', __('Order discount cannot exceed subtotal.'));
            return;
        }

        $order = DB::transaction(function () use ($data, $items, $menuItems, $numberService, $totalsService) {
            $order = Order::create([
                'order_number' => $numberService->generate(),
                'branch_id' => $data['branch_id'],
                'source' => $data['source'] === 'Subscription' ? 'Backoffice' : $data['source'],
                'is_daily_dish' => $this->is_daily_dish,
                'type' => $data['type'],
                'status' => $data['status'],
                'customer_id' => $data['customer_id'],
                'customer_name_snapshot' => $this->customer_name_snapshot,
                'customer_phone_snapshot' => $this->customer_phone_snapshot,
                'delivery_address_snapshot' => $this->delivery_address_snapshot,
                'scheduled_date' => $data['scheduled_date'],
                'scheduled_time' => $data['scheduled_time'],
                'notes' => $data['notes'],
                'order_discount_amount' => (float) ($data['order_discount_amount'] ?? 0),
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);

            foreach ($items as $idx => $row) {
                $menuItem = $menuItems->get($row['menu_item_id']);
                $qty = (float) ($row['quantity'] ?? 1);
                $price = isset($row['unit_price']) ? (float) $row['unit_price'] : (float) ($menuItem->selling_price_per_unit ?? 0);
                $discount = (float) ($row['discount_amount'] ?? 0);
                $lineTotal = max(0, ($qty * $price) - $discount);
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $row['menu_item_id'],
                    'description_snapshot' => trim(($menuItem->code ?? '').' '.$menuItem->name),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => $discount,
                    'line_total' => round($lineTotal, 3),
                    'status' => 'Pending',
                    'sort_order' => $row['sort_order'] ?? $idx,
                ]);
            }

            $totalsService->recalc($order);

            return $order;
        });

        session()->flash('status', __('Order created.'));
        $this->redirectRoute('orders.edit', $order, navigate: true);
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Order') }}</h1>
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="branch_id" type="number" :label="__('Branch ID')" />
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model="source" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Backoffice">{{ __('Backoffice') }}</option>
                    <option value="Phone">{{ __('Phone') }}</option>
                    <option value="WhatsApp">{{ __('WhatsApp') }}</option>
                    <option value="Website">{{ __('Website') }}</option>
                </select>
            </div>
            <div class="flex items-center gap-3 pt-6">
                <flux:checkbox wire:model.live="is_daily_dish" :label="__('Daily Dish')" />
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                <select wire:model="type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Delivery">{{ __('Delivery') }}</option>
                    <option value="Takeaway">{{ __('Takeaway') }}</option>
                    <option value="DineIn">{{ __('Dine In') }}</option>
                    <option value="Pastry">{{ __('Pastry') }}</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Draft">{{ __('Draft') }}</option>
                    <option value="Confirmed">{{ __('Confirmed') }}</option>
                </select>
            </div>
            <flux:input wire:model="scheduled_date" type="date" :label="__('Scheduled Date')" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="scheduled_time" type="time" :label="__('Scheduled Time')" />
            <div class="relative">
                <flux:input
                    wire:model.live.debounce.300ms="customer_search"
                    :label="__('Customer')"
                    placeholder="{{ __('Search by name, phone, or code') }}"
                />
                @if($customer_id === null && $customer_search !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $customer)
                                <button
                                    type="button"
                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                    wire:click="selectCustomer({{ $customer->id }})"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $customer->name }}</span>
                                        @if($customer->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $customer->customer_code }}</span>
                                        @endif
                                    </div>
                                    @if($customer->phone)
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $customer->phone }}</div>
                                    @endif
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
            <flux:input wire:model="customer_name_snapshot" :label="__('Customer Name')" readonly />
        </div>
        <flux:input wire:model="customer_phone_snapshot" :label="__('Customer Phone')" readonly />
        <flux:textarea wire:model="delivery_address_snapshot" :label="__('Delivery Address')" rows="2" />
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
    </div>

    @if (! $is_daily_dish)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>
                <div class="flex items-center gap-2">
                    <flux:button type="button" wire:click="addItemRow" variant="outline" size="sm">{{ __('Add Row') }}</flux:button>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        x-data=""
                        x-on:click.prevent="$wire.prepareMenuItemModal(); $dispatch('modal-show', { name: 'create-menu-item' })"
                    >{{ __('Create Menu Item') }}</flux:button>
                </div>
            </div>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Unit price can be adjusted per order.') }}</p>

            @error('selected_items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                @php
                    $subtotal = 0.0;
                    foreach ($selected_items as $row) {
                        if (empty($row['menu_item_id'])) {
                            continue;
                        }
                        $qty = (float) ($row['quantity'] ?? 0);
                        $price = (float) ($row['unit_price'] ?? 0);
                        $discount = (float) ($row['discount_amount'] ?? 0);
                        $subtotal += max(0, ($qty * $price) - $discount);
                    }
                    $orderDiscount = (float) ($order_discount_amount ?? 0);
                    $total = max(0, $subtotal - $orderDiscount);
                @endphp
                <table class="w-full min-w-[760px] table-fixed">
                    <thead class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                        <tr>
                            <th class="px-3 py-2 w-72">{{ __('Menu Item') }}</th>
                            <th class="px-3 py-2 w-24">{{ __('Qty') }}</th>
                            <th class="px-3 py-2 w-32">{{ __('Unit Price') }}</th>
                            <th class="px-3 py-2 w-28">{{ __('Discount') }}</th>
                            <th class="px-3 py-2 w-32">{{ __('Line Total') }}</th>
                            <th class="px-3 py-2 w-20">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($selected_items as $idx => $row)
                            @php
                                $qty = (float) ($row['quantity'] ?? 0);
                                $price = (float) ($row['unit_price'] ?? 0);
                                $discount = (float) ($row['discount_amount'] ?? 0);
                                $lineTotal = max(0, ($qty * $price) - $discount);
                            @endphp
                            <tr>
                                <td class="px-3 py-2 align-top">
                                    <div class="relative">
                                        <flux:input
                                            wire:model.live.debounce.300ms="item_search.{{ $idx }}"
                                            placeholder="{{ __('Search item') }}"
                                        />
                                        @if(($item_search[$idx] ?? '') !== '' && empty($row['menu_item_id']))
                                            <div x-data="{
                                                positionDropdown() {
                                                    const container = this.$el.closest('.relative');
                                                    const input = container?.querySelector('input');
                                                    if (input) {
                                                        const rect = input.getBoundingClientRect();
                                                        this.$el.style.position = 'fixed';
                                                        this.$el.style.left = rect.left + 'px';
                                                        this.$el.style.bottom = (window.innerHeight - rect.top) + 'px';
                                                        this.$el.style.width = rect.width + 'px';
                                                        this.$el.style.zIndex = '9999';
                                                    }
                                                }
                                            }"
                                            x-init="setTimeout(() => positionDropdown(), 10)"
                                            class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                                <div class="max-h-60 overflow-auto">
                                                    @forelse ($menu_item_options[$idx] ?? [] as $item)
                                                        <button
                                                            type="button"
                                                            class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                            wire:click="selectMenuItem({{ $idx }}, {{ $item->id }})"
                                                        >
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="font-medium">{{ $item->name }}</span>
                                                                @if($item->code)
                                                                    <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $item->code }}</span>
                                                                @endif
                                                            </div>
                                                            @if($item->selling_price_per_unit !== null)
                                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ number_format((float) $item->selling_price_per_unit, 3, '.', '') }}</div>
                                                            @endif
                                                        </button>
                                                    @empty
                                                        <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items found.') }}</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.quantity" type="number" step="0.001" class="w-20" />
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.unit_price" type="number" step="0.001" class="w-28" :placeholder="__('Auto')" />
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.discount_amount" type="number" step="0.001" class="w-24" />
                                </td>
                                <td class="px-3 py-2 align-top text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ number_format($lineTotal, 3, '.', '') }}
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <flux:button type="button" size="xs" variant="danger" wire:click="removeItemRow({{ $idx }})">{{ __('Remove') }}</flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-4 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items added.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end">
                <div class="w-full max-w-xs space-y-2 text-sm">
                    <div class="flex items-center justify-between text-neutral-600 dark:text-neutral-300">
                        <span>{{ __('Subtotal') }}</span>
                        <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ number_format($subtotal, 3, '.', '') }}</span>
                    </div>
                    <div class="flex items-center justify-between text-neutral-600 dark:text-neutral-300">
                        <label for="order_discount_amount" class="text-sm">{{ __('Order Discount') }}</label>
                        <div class="w-32">
                            <flux:input
                                id="order_discount_amount"
                                wire:model.live.debounce.300ms="order_discount_amount"
                                type="number"
                                step="0.001"
                                class="text-right"
                            />
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-t border-neutral-200 pt-2 text-base font-semibold text-neutral-900 dark:border-neutral-700 dark:text-neutral-100">
                        <span>{{ __('Total') }}</span>
                        <span>{{ number_format($total, 3, '.', '') }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($is_daily_dish)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Menu & Items') }}</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Published Menu') }}</label>
                    <select wire:model="menu_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select menu') }}</option>
                        @foreach($menus as $m)
                            <option value="{{ $m->id }}">{{ $m->service_date?->format('Y-m-d') }} (Branch {{ $m->branch_id }})</option>
                        @endforeach
                    </select>
                    @error('menu_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    @error('selected_items') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($menu_id)
                @php $menu = $menus->firstWhere('id', $menu_id); @endphp
                <div class="space-y-3">
                    @foreach($menu?->items->groupBy('role') ?? [] as $role => $rows)
                        <div>
                            <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ ucfirst($role) }}</p>
                            @foreach($rows as $idx => $row)
                                <div class="flex flex-wrap items-center gap-3 py-1">
                                    <flux:checkbox wire:model="selected_items.{{ $idx }}.menu_item_id" :value="$row->menu_item_id" :label="($row->menuItem->code ?? '').' '.$row->menuItem->name" />
                                    <flux:input wire:model="selected_items.{{ $idx }}.quantity" type="number" step="0.001" class="w-28" />
                                    <flux:input wire:model="selected_items.{{ $idx }}.unit_price" type="number" step="0.001" class="w-28" :placeholder="__('Auto')" />
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <flux:modal name="create-menu-item" focusable class="max-w-lg">
        <form wire:submit="createMenuItem" class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Create Menu Item') }}</flux:heading>
                <flux:subheading>{{ __('Add a new item without leaving the order.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-1 gap-4">
                <flux:input wire:model="menu_item_code" :label="__('Item Code')" readonly />
                <flux:input wire:model="menu_item_name" :label="__('Item Name')" required />

                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Category') }}</label>
                    @if ($categories->count())
                        <select wire:model="menu_item_category_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <flux:input disabled placeholder="{{ __('No categories available') }}" />
                    @endif
                </div>

                <flux:input wire:model="menu_item_price" type="number" step="0.001" min="0" :label="__('Selling Price')" />
                <div class="flex items-center gap-3">
                    <flux:checkbox wire:model="menu_item_is_active" :label="__('Active')" />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <div class="flex justify-end gap-3">
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
    </div>
</div>
