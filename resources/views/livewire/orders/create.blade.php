<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public string $source = 'Backoffice';
    public bool $is_daily_dish = true;
    public string $type = 'Delivery';
    public string $status = 'Confirmed';
    public ?int $customer_id = null;
    public ?string $customer_name_snapshot = null;
    public ?string $customer_phone_snapshot = null;
    public ?string $delivery_address_snapshot = null;
    public string $scheduled_date;
    public ?string $scheduled_time = null;
    public ?string $notes = null;

    public ?int $menu_id = null;
    public array $selected_items = [];

    public function mount(): void
    {
        $this->scheduled_date = now()->toDateString();
    }

    public function with(): array
    {
        $menus = DailyDishMenu::with('items.menuItem')
            ->where('status', 'published')
            ->where('branch_id', $this->branch_id)
            ->orderByDesc('service_date')
            ->limit(30)
            ->get();

        return [
            'customers' => Schema::hasTable('customers') ? Customer::orderBy('name')->get() : collect(),
            'menus' => $menus,
        ];
    }

    public function updatedBranchId(): void
    {
        $this->menu_id = null;
        $this->selected_items = [];
    }

    public function updatedMenuId(): void
    {
        $menu = $this->menu_id ? DailyDishMenu::with('items')->find($this->menu_id) : null;
        $this->selected_items = $menu ? $menu->items->pluck('menu_item_id')->map(fn ($id) => ['menu_item_id' => $id, 'quantity' => 1, 'unit_price' => null, 'sort_order' => 0])->values()->toArray() : [];
    }

    public function save(OrderNumberService $numberService, OrderTotalsService $totalsService): void
    {
        $data = $this->validate([
            'branch_id' => ['required', 'integer'],
            'source' => ['required', 'in:POS,Phone,WhatsApp,Subscription,Backoffice'],
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
            'menu_id' => ['nullable', 'integer'],
            'selected_items' => ['array'],
            'selected_items.*.menu_item_id' => ['required', 'integer'],
            'selected_items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'selected_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
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
            if (empty($this->selected_items)) {
                $this->addError('selected_items', __('Select at least one menu item.'));
                return;
            }
        }

        $order = DB::transaction(function () use ($data, $numberService, $totalsService) {
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
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            foreach ($this->selected_items as $idx => $row) {
                $menuItem = \App\Models\MenuItem::find($row['menu_item_id']);
                $qty = (float) ($row['quantity'] ?? 1);
                $price = isset($row['unit_price']) ? (float) $row['unit_price'] : (float) ($menuItem->selling_price_per_unit ?? 0);
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $row['menu_item_id'],
                    'description_snapshot' => trim(($menuItem->code ?? '').' '.$menuItem->name),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => 0,
                    'line_total' => round($qty * $price, 3),
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
                </select>
            </div>
            <div class="flex items-center gap-3 pt-6">
                <flux:checkbox wire:model="is_daily_dish" :label="__('Daily Dish')" />
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
            <flux:input wire:model="customer_id" type="number" :label="__('Customer ID (optional)')" />
            <flux:input wire:model="customer_name_snapshot" :label="__('Customer Name')" />
        </div>
        <flux:input wire:model="customer_phone_snapshot" :label="__('Customer Phone')" />
        <flux:textarea wire:model="delivery_address_snapshot" :label="__('Delivery Address')" rows="2" />
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
    </div>

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

    <div class="flex justify-end gap-3">
        <flux:button :href="route('orders.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
    </div>
</div>

