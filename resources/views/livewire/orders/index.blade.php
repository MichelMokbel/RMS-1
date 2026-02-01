<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\Orders\OrderCreateService;
use App\Services\Orders\OrderWorkflowService;
use App\Support\Orders\OrderCreateRules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'all';
    public ?string $source = null;
    public ?int $branch_id = null;
    public string $daily_dish_filter = 'all';
    public ?string $scheduled_date = null;
    public string $search = '';

    public bool $showCreateDrawer = false;
    public int $drawer_branch_id = 1;
    public string $drawer_source = 'Backoffice';
    public bool $drawer_is_daily_dish = false;
    public bool $drawer_is_daily_dish_subscription = false;
    public ?int $drawer_subscription_id = null;
    public ?int $drawer_subscription_main_menu_item_id = null;
    public string $drawer_type = 'Delivery';
    public string $drawer_status = 'Draft';
    public ?int $drawer_customer_id = null;
    public string $drawer_customer_search = '';
    public ?string $drawer_customer_name_snapshot = null;
    public ?string $drawer_customer_phone_snapshot = null;
    public ?string $drawer_delivery_address_snapshot = null;
    public string $drawer_scheduled_date;
    public ?string $drawer_scheduled_time = null;
    public ?string $drawer_notes = null;
    public float $drawer_order_discount_amount = 0.0;
    public ?int $drawer_menu_id = null;
    public string $drawer_daily_dish_portion_type = 'plate';
    public ?int $drawer_daily_dish_portion_quantity = 1;
    public array $drawer_selected_items = [];
    public array $drawer_item_search = [];

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->drawer_scheduled_date = now()->toDateString();
    }

    public function updating($field): void
    {
        if (in_array($field, ['status','source','branch_id','daily_dish_filter','scheduled_date','search'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $orders = $this->query()->paginate(15);
        $drawerMenus = collect();
        $drawerCustomers = collect();
        $drawerMenuItems = collect();
        $drawerSubscriptions = collect();
        $drawerSubscriptionMainDishOptions = collect();
        if ($this->showCreateDrawer) {
            if ($this->drawer_is_daily_dish) {
                $drawerMenus = DailyDishMenu::with('items.menuItem')
                    ->where('status', 'published')
                    ->where('branch_id', $this->drawer_branch_id)
                    ->orderByDesc('service_date')
                    ->limit(30)
                    ->get();
                if ($this->drawer_is_daily_dish_subscription && $this->drawer_scheduled_date) {
                    $drawerSubscriptions = MealSubscription::with(['days', 'pauses', 'customer'])
                        ->where('branch_id', $this->drawer_branch_id)
                        ->where('status', 'active')
                        ->whereDate('start_date', '<=', $this->drawer_scheduled_date)
                        ->where(function ($q) {
                            $q->whereNull('end_date')->orWhereDate('end_date', '>=', $this->drawer_scheduled_date);
                        })
                        ->get()
                        ->filter(fn ($s) => $s->isActiveOn($this->drawer_scheduled_date))
                        ->values();
                    $subscriptionMenu = DailyDishMenu::with('items.menuItem')
                        ->where('branch_id', $this->drawer_branch_id)
                        ->whereDate('service_date', $this->drawer_scheduled_date)
                        ->where('status', 'published')
                        ->first();
                    if ($subscriptionMenu) {
                        $drawerSubscriptionMainDishOptions = $subscriptionMenu->items->filter(
                            fn ($i) => in_array($i->role ?? '', ['main', 'diet', 'vegetarian'], true)
                        )->values();
                    }
                }
            }
            if ($this->drawer_customer_id === null && $this->drawer_customer_search !== '' && Schema::hasTable('customers')) {
                $drawerCustomers = Customer::query()
                    ->search($this->drawer_customer_search)
                    ->orderBy('name')
                    ->limit(25)
                    ->get();
            }
            $menuItemIds = collect($this->drawer_selected_items)->pluck('menu_item_id')->filter()->unique()->values()->all();
            if (! empty($menuItemIds)) {
                $drawerMenuItems = MenuItem::whereIn('id', $menuItemIds)->get()->keyBy('id');
            }
        }
        return [
            'orders' => $orders,
            'drawer_menus' => $drawerMenus,
            'drawer_subscriptions' => $drawerSubscriptions ?? collect(),
            'drawer_subscription_main_dish_options' => $drawerSubscriptionMainDishOptions ?? collect(),
            'drawer_customers' => $drawerCustomers,
            'drawer_menu_items' => $drawerMenuItems,
            'drawer_branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'drawer_categories' => Schema::hasTable('categories') ? Category::orderBy('name')->get() : collect(),
        ];
    }

    public function openCreateDrawer(): void
    {
        $this->drawer_scheduled_date = now()->toDateString();
        $this->drawer_scheduled_time = null;
        $this->drawer_is_daily_dish = false;
        $this->drawer_is_daily_dish_subscription = false;
        $this->drawer_subscription_id = null;
        $this->drawer_subscription_main_menu_item_id = null;
        $this->drawer_menu_id = null;
        $this->drawer_daily_dish_portion_type = 'plate';
        $this->drawer_daily_dish_portion_quantity = 1;
        $this->drawer_selected_items = [];
        $this->drawer_item_search = [];
        $this->drawer_customer_id = null;
        $this->drawer_customer_search = '';
        $this->drawer_customer_name_snapshot = null;
        $this->drawer_customer_phone_snapshot = null;
        $this->drawer_delivery_address_snapshot = null;
        $this->drawer_notes = null;
        $this->drawer_order_discount_amount = 0.0;
        $this->addDrawerItemRow();
        $this->resetValidation();
        $this->showCreateDrawer = true;
    }

    public function closeCreateDrawer(): void
    {
        $this->showCreateDrawer = false;
        $this->resetValidation();
    }

    public function updatedDrawerBranchId(): void
    {
        $this->drawer_menu_id = null;
        $this->drawer_selected_items = [];
        $this->drawer_item_search = [];
        if (! $this->drawer_is_daily_dish) {
            $this->addDrawerItemRow();
        }
    }

    public function updatedDrawerIsDailyDish(): void
    {
        $this->drawer_menu_id = null;
        $this->drawer_selected_items = [];
        $this->drawer_item_search = [];
        if (! $this->drawer_is_daily_dish) {
            $this->addDrawerItemRow();
        }
    }

    public function updatedDrawerSubscriptionId(): void
    {
        $this->drawer_subscription_main_menu_item_id = null;
    }

    public function updatedDrawerMenuId(): void
    {
        if (! $this->drawer_is_daily_dish) {
            return;
        }
        $menu = $this->drawer_menu_id ? DailyDishMenu::with('items')->find($this->drawer_menu_id) : null;
        $this->drawer_selected_items = $menu
            ? $menu->items
                ->pluck('menu_item_id')
                ->map(fn ($id) => [
                    'menu_item_id' => $id,
                    'quantity' => 0,
                    'unit_price' => null,
                    'discount_amount' => 0,
                    'sort_order' => 0,
                ])
                ->values()
                ->toArray()
            : [];
        $this->drawer_item_search = [];
    }

    public function incrementDrawerDailyDishQuantity(int $index): void
    {
        if (! $this->drawer_is_daily_dish || ! array_key_exists($index, $this->drawer_selected_items)) {
            return;
        }
        $qty = (float) ($this->drawer_selected_items[$index]['quantity'] ?? 0);
        $this->drawer_selected_items[$index]['quantity'] = $qty + 1;
    }

    public function clearDrawerDailyDishItem(int $index): void
    {
        if (! $this->drawer_is_daily_dish || ! array_key_exists($index, $this->drawer_selected_items)) {
            return;
        }
        $this->drawer_selected_items[$index]['quantity'] = 0;
    }

    public function updatedDrawerCustomerSearch(): void
    {
        if ($this->drawer_customer_id !== null) {
            $this->drawer_customer_id = null;
            $this->drawer_customer_name_snapshot = null;
            $this->drawer_customer_phone_snapshot = null;
        }
    }

    public function selectDrawerCustomer(int $customerId): void
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }
        $this->drawer_customer_id = $customerId;
        $this->drawer_customer_name_snapshot = $customer->name;
        $this->drawer_customer_phone_snapshot = $customer->phone;
        $this->drawer_customer_search = trim($customer->name.' '.($customer->phone ?? ''));
    }

    public function addDrawerItemRow(): void
    {
        $this->drawer_selected_items[] = [
            'menu_item_id' => null,
            'quantity' => 1,
            'unit_price' => null,
            'discount_amount' => 0,
            'sort_order' => count($this->drawer_selected_items),
        ];
        $this->drawer_item_search[] = '';
    }

    public function removeDrawerItemRow(int $index): void
    {
        unset($this->drawer_selected_items[$index], $this->drawer_item_search[$index]);
        $this->drawer_selected_items = array_values($this->drawer_selected_items);
        $this->drawer_item_search = array_values($this->drawer_item_search);
    }

    public function selectDrawerMenuItemPayload(int $index, int $menuItemId, string $label, ?float $price = null): void
    {
        if (! array_key_exists($index, $this->drawer_selected_items)) {
            return;
        }
        $this->drawer_selected_items[$index]['menu_item_id'] = $menuItemId;
        $this->drawer_selected_items[$index]['quantity'] = $this->drawer_selected_items[$index]['quantity'] ?? 1;
        $this->drawer_selected_items[$index]['unit_price'] = $price !== null ? (float) $price : 0.0;
        $this->drawer_selected_items[$index]['discount_amount'] = $this->drawer_selected_items[$index]['discount_amount'] ?? 0;
        $this->drawer_selected_items[$index]['sort_order'] = $this->drawer_selected_items[$index]['sort_order'] ?? $index;
        $this->drawer_item_search[$index] = $label;
        $this->ensureDrawerTrailingRow();
    }

    public function clearDrawerMenuItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->drawer_selected_items)) {
            return;
        }
        $this->drawer_selected_items[$index]['menu_item_id'] = null;
        $this->drawer_selected_items[$index]['unit_price'] = null;
        $this->drawer_item_search[$index] = '';
    }

    /** Used by Alpine menuItemLookup in the create drawer. */
    public function selectMenuItemPayload(int $index, int $menuItemId, string $label, ?float $price = null): void
    {
        $this->selectDrawerMenuItemPayload($index, $menuItemId, $label, $price);
    }

    /** Used by Alpine menuItemLookup in the create drawer. */
    public function clearMenuItemSelection(int $index): void
    {
        $this->clearDrawerMenuItemSelection($index);
    }

    private function ensureDrawerTrailingRow(): void
    {
        if ($this->drawer_is_daily_dish) {
            return;
        }
        foreach ($this->drawer_selected_items as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $hasPrice = isset($row['unit_price']) && $row['unit_price'] !== '';
            if (empty($row['menu_item_id']) || $qty <= 0 || ! $hasPrice) {
                return;
            }
        }
        $this->addDrawerItemRow();
    }

    public function saveDrawerOrder(OrderCreateService $service, OrderCreateRules $rules): void
    {
        $selectedItems = $this->drawer_selected_items;
        if ($this->drawer_is_daily_dish && ! $this->drawer_is_daily_dish_subscription) {
            $selectedItems = collect($selectedItems)
                ->filter(fn ($row) => (float) ($row['quantity'] ?? 0) > 0)
                ->values()
                ->all();
        }
        if ($this->drawer_is_daily_dish_subscription && $this->drawer_subscription_id) {
            $selectedItems = [];
        }
        $data = [
            'branch_id' => $this->drawer_branch_id,
            'source' => $this->drawer_is_daily_dish_subscription && $this->drawer_subscription_id ? 'Subscription' : $this->drawer_source,
            'is_daily_dish' => $this->drawer_is_daily_dish,
            'type' => $this->drawer_type,
            'status' => $this->drawer_status,
            'customer_id' => $this->drawer_customer_id,
            'customer_name_snapshot' => $this->drawer_customer_name_snapshot,
            'customer_phone_snapshot' => $this->drawer_customer_phone_snapshot,
            'delivery_address_snapshot' => $this->drawer_delivery_address_snapshot,
            'scheduled_date' => $this->drawer_scheduled_date,
            'scheduled_time' => $this->drawer_scheduled_time,
            'notes' => $this->drawer_notes,
            'order_discount_amount' => $this->drawer_order_discount_amount,
            'menu_id' => $this->drawer_menu_id,
            'selected_items' => $selectedItems,
        ];
        if ($this->drawer_is_daily_dish_subscription && $this->drawer_subscription_id) {
            $data['subscription_id'] = $this->drawer_subscription_id;
            $data['subscription_main_menu_item_id'] = $this->drawer_subscription_main_menu_item_id ?: null;
        }
        if ($this->drawer_is_daily_dish_subscription && ! $this->drawer_subscription_id) {
            $this->addError('drawer_subscription_id', __('Select a subscription.'));
            return;
        }
        if ($this->drawer_is_daily_dish_subscription && $this->drawer_subscription_id && ! $this->drawer_subscription_main_menu_item_id) {
            $this->addError('drawer_subscription_main_menu_item_id', __('Select a main dish for this subscription order.'));
            return;
        }
        if ($this->drawer_is_daily_dish && ! $this->drawer_is_daily_dish_subscription) {
            $data['daily_dish_portion_type'] = $this->drawer_daily_dish_portion_type;
            $data['daily_dish_portion_quantity'] = in_array($this->drawer_daily_dish_portion_type, ['full', 'half'], true)
                ? (int) $this->drawer_daily_dish_portion_quantity
                : null;
        }
        try {
            $data = validator($data, $rules->rules())->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            return;
        }
        try {
            $service->create($data, Auth::id());
            session()->flash('status_message', __('Order created.'));
            $this->closeCreateDrawer();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
        }
    }

    public function confirmOrder(int $orderId): void
    {
        $order = Order::find($orderId);
        if (! $order) {
            return;
        }
        if ($order->status !== 'Draft') {
            return;
        }
        try {
            $actorId = Auth::id();
            if (! $actorId) {
                throw ValidationException::withMessages(['auth' => __('Authentication required.')]);
            }
            app(OrderWorkflowService::class)->advanceOrder($order, 'Confirmed', $actorId);
            session()->flash('status_message', __('Order confirmed.'));
        } catch (ValidationException $e) {
            session()->flash('error_message', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error_message', __('Failed to confirm order.'));
        }
    }

    public function cancelOrder(int $orderId): void
    {
        $order = Order::find($orderId);
        if (! $order) {
            return;
        }
        if (in_array($order->status, ['Cancelled', 'Delivered'], true)) {
            session()->flash('error_message', __('Order cannot be cancelled.'));
            return;
        }
        try {
            $actorId = Auth::id();
            if (! $actorId) {
                throw ValidationException::withMessages(['auth' => __('Authentication required.')]);
            }
            app(OrderWorkflowService::class)->advanceOrder($order, 'Cancelled', $actorId);
            session()->flash('status_message', __('Order cancelled.'));
        } catch (ValidationException $e) {
            session()->flash('error_message', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error_message', __('Failed to cancel order.'));
        }
    }

    private function query()
    {
        return Order::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->source, fn ($q) => $q->where('source', $this->source))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->daily_dish_filter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($this->daily_dish_filter === 'exclude', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_daily_dish')
                        ->orWhere('is_daily_dish', 0);
                });
            })
            ->when($this->scheduled_date, fn ($q) => $q->whereDate('scheduled_date', $this->scheduled_date))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                       ->orWhere('customer_name_snapshot', 'like', $term)
                       ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->orderByDesc('created_at');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    @php
        $printParams = array_filter([
            'status' => $status,
            'source' => $source,
            'branch_id' => $branch_id,
            'daily_dish_filter' => $daily_dish_filter,
            'scheduled_date' => $scheduled_date,
            'search' => $search,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Orders') }}</h1>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="openCreateDrawer" variant="primary" class="min-h-[44px] min-w-[44px] touch-manipulation">{{ __('New Order') }}</flux:button>
            <flux:button :href="route('orders.create')" wire:navigate variant="ghost" class="min-h-[44px] touch-manipulation">{{ __('Full Create') }}</flux:button>
            <flux:button :href="route('orders.kitchen')" wire:navigate variant="ghost">{{ __('Kitchen View') }}</flux:button>
            <flux:button :href="route('orders.print', $printParams)" target="_blank" variant="ghost">{{ __('Print Report') }}</flux:button>
            <flux:button :href="route('orders.print.invoices', $printParams)" target="_blank" variant="ghost">{{ __('Print Invoices') }}</flux:button>
            <!-- <flux:button :href="route('orders.daily-dish')" wire:navigate variant="ghost">{{ __('Daily Dish') }}</flux:button>
            <flux:button :href="route('daily-dish.menus.index')" wire:navigate variant="ghost">{{ __('Daily Dish Menus') }}</flux:button>
            <flux:button :href="route('subscriptions.generate')" wire:navigate variant="ghost">{{ __('Generate Subscriptions') }}</flux:button> -->
        </div>
    </div>

    @if (session('status_message'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status_message') }}
        </div>
    @endif
    @if (session('error_message'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error_message') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[220px] flex-1">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Search order number / customer') }}" />
            </div>
            <div class="w-40">
                <flux:input wire:model.live="scheduled_date" type="date" :label="__('Date')" />
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="Draft">{{ __('Draft') }}</option>
                    <option value="Confirmed">{{ __('Confirmed') }}</option>
                    <option value="InProduction">{{ __('In Production') }}</option>
                    <option value="Ready">{{ __('Ready') }}</option>
                    <option value="OutForDelivery">{{ __('Out For Delivery') }}</option>
                    <option value="Delivered">{{ __('Delivered') }}</option>
                    <option value="Cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model.live="source" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="Subscription">{{ __('Subscription') }}</option>
                    <option value="Backoffice">{{ __('Backoffice') }}</option>
                    <option value="POS">{{ __('POS') }}</option>
                    <option value="Phone">{{ __('Phone') }}</option>
                    <option value="WhatsApp">{{ __('WhatsApp') }}</option>
                </select>
            </div>
            <div class="w-28">
                <flux:input wire:model.live="branch_id" type="number" :label="__('Branch')" />
            </div>
            <div class="w-48">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Orders') }}</label>
                <select wire:model.live="daily_dish_filter" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All orders') }}</option>
                    <option value="exclude">{{ __('Hide Daily Dish') }}</option>
                    <option value="only">{{ __('Daily Dish only') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Source') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Scheduled') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    <tr class="min-h-[52px] hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100 align-middle">{{ $order->order_number }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">
                            {{ $order->source }} @if($order->is_daily_dish) <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 dark:bg-blue-900 dark:text-blue-100">DD</span> @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->branch_id }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->type }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->status }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->customer_name_snapshot ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</td>
                        <td class="px-3 py-3 text-sm text-right text-neutral-900 dark:text-neutral-100 align-middle">{{ number_format((float) $order->total_amount, 3) }}</td>
                        <td class="px-3 py-3 text-sm text-right align-middle">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if ($order->status === 'Draft')
                                    <flux:button
                                        size="sm"
                                        type="button"
                                        variant="primary"
                                        wire:click="confirmOrder({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        class="min-h-[44px] touch-manipulation"
                                    >{{ __('Confirm') }}</flux:button>
                                @endif
                                <flux:button size="sm" :href="route('orders.edit', $order)" wire:navigate class="min-h-[44px] touch-manipulation">{{ __('Edit') }}</flux:button>
                                @if (!in_array($order->status, ['Cancelled', 'Delivered'], true))
                                    <flux:button
                                        size="sm"
                                        type="button"
                                        variant="danger"
                                        wire:click="cancelOrder({{ $order->id }})"
                                        wire:confirm="{{ __('Cancel this order?') }}"
                                        wire:loading.attr="disabled"
                                        class="min-h-[44px] touch-manipulation"
                                    >{{ __('Delete') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No orders found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $orders->links() }}
    </div>

    {{-- Create order drawer (tablet/touch friendly) --}}
    <style>
        .orders-create-drawer { position: fixed; inset: 0; z-index: 99999; pointer-events: none; }
        .orders-create-drawer[data-open="1"] { pointer-events: auto; }
        .orders-create-drawer__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); opacity: 0; transition: opacity 200ms ease; }
        .orders-create-drawer[data-open="1"] .orders-create-drawer__backdrop { opacity: 1; }
        .orders-create-drawer__panel { position: absolute; top: 0; right: 0; height: 100%; width: min(56rem, 100%); transform: translateX(100%); transition: transform 250ms ease; overflow-y: auto; overflow-x: hidden; background: #fff; box-shadow: -20px 0 60px rgba(0,0,0,.2); border-left: 1px solid rgba(0,0,0,.08); }
        .orders-create-drawer[data-open="1"] .orders-create-drawer__panel { transform: translateX(0); }
        .dark .orders-create-drawer__panel { background: rgb(23 23 23); border-left-color: rgba(255,255,255,.12); }
        .orders-create-drawer .touch-target { min-height: 44px; min-width: 44px; touch-action: manipulation; }
    </style>

    <div class="orders-create-drawer" data-open="{{ $showCreateDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" aria-hidden="{{ $showCreateDrawer ? 'false' : 'true' }}">
        <div class="orders-create-drawer__backdrop" wire:click="closeCreateDrawer"></div>
        <div class="orders-create-drawer__panel">
            <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Order') }}</h2>
                    <flux:button size="sm" type="button" variant="ghost" wire:click="closeCreateDrawer" class="touch-target">{{ __('Close') }}</flux:button>
                </div>
            </div>
            @if ($showCreateDrawer)
            <form wire:submit="saveDrawerOrder" class="p-4 space-y-4">
                {{-- Row 1: Branch, Type, Status, Daily Dish --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @if ($drawer_branches->count())
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                            <select wire:model.live="drawer_branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                                @foreach ($drawer_branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <flux:input wire:model.live="drawer_branch_id" type="number" :label="__('Branch ID')" class="touch-target" />
                        </div>
                    @endif
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                        <select wire:model="drawer_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                            <option value="Delivery">{{ __('Delivery') }}</option>
                            <option value="Takeaway">{{ __('Takeaway') }}</option>
                            <option value="DineIn">{{ __('Dine In') }}</option>
                            <option value="Pastry">{{ __('Pastry') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                        <select wire:model="drawer_status" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                            <option value="Draft">{{ __('Draft') }}</option>
                            <option value="Confirmed">{{ __('Confirmed') }}</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-1">
                        <flux:checkbox wire:model.live="drawer_is_daily_dish" :label="__('Daily Dish order')" class="touch-target" />
                    </div>
                    @if ($drawer_is_daily_dish)
                        <div class="flex items-end pb-1">
                            <flux:checkbox wire:model.live="drawer_is_daily_dish_subscription" :label="__('Daily dish subscription')" class="touch-target" />
                        </div>
                    @endif
                </div>

                {{-- Row 2: Scheduled date, time --}}
                <div class="grid grid-cols-2 gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <flux:input wire:model="drawer_scheduled_date" type="date" :label="__('Scheduled Date')" class="touch-target" />
                    <flux:input wire:model="drawer_scheduled_time" type="time" :label="__('Scheduled Time')" class="touch-target" />
                </div>

                {{-- Row 3: Customer --}}
                <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <div class="relative">
                        <flux:input wire:model.live.debounce.300ms="drawer_customer_search" :label="__('Customer')" placeholder="{{ __('Search by name or phone') }}" class="touch-target" />
                        @if($drawer_customer_id === null && $drawer_customer_search !== '')
                            <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="max-h-56 overflow-auto">
                                    @forelse ($drawer_customers as $customer)
                                        <button type="button" class="w-full px-3 py-3 text-left text-base text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80 touch-target" wire:click="selectDrawerCustomer({{ $customer->id }})">
                                            <span class="font-medium">{{ $customer->name }}</span>
                                            @if($customer->phone)
                                                <span class="block text-sm text-neutral-500 dark:text-neutral-400">{{ $customer->phone }}</span>
                                            @endif
                                        </button>
                                    @empty
                                        <div class="px-3 py-3 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Row 4: Delivery address, Notes --}}
                <div class="grid grid-cols-1 gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-700 sm:grid-cols-2">
                    <flux:textarea wire:model="drawer_delivery_address_snapshot" :label="__('Delivery Address')" rows="2" class="touch-target" />
                    <flux:textarea wire:model="drawer_notes" :label="__('Notes')" rows="2" class="touch-target" />
                </div>

                @if ($drawer_is_daily_dish)
                    <div class="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                        <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Daily Dish Menu') }}</h3>
                        @if ($drawer_is_daily_dish_subscription)
                            <div class="space-y-3">
                                <div>
                                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Subscription') }}</label>
                                    <select wire:model.live="drawer_subscription_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                                        <option value="">{{ __('Select subscription') }}</option>
                                        @foreach($drawer_subscriptions as $sub)
                                            <option value="{{ $sub->id }}">{{ $sub->subscription_code ?? $sub->id }} — {{ $sub->customer->name ?? __('Customer') }}</option>
                                        @endforeach
                                    </select>
                                    @error('drawer_subscription_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                                @if ($drawer_subscription_id)
                                    <div>
                                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Main dish') }}</label>
                                        <select wire:model.live="drawer_subscription_main_menu_item_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                                            <option value="">{{ __('Select main dish') }}</option>
                                            @foreach($drawer_subscription_main_dish_options as $opt)
                                                <option value="{{ $opt->menu_item_id }}">{{ $opt->menuItem->name ?? $opt->menu_item_id }}</option>
                                            @endforeach
                                        </select>
                                        @error('drawer_subscription_main_menu_item_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Salad, dessert, appetizer and water are added from the subscription.') }}</p>
                                    </div>
                                @endif
                            </div>
                        @else
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Published Menu') }}</label>
                            <select wire:model.live="drawer_menu_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                                <option value="">{{ __('Select menu') }}</option>
                                @foreach($drawer_menus as $m)
                                    <option value="{{ $m->id }}">{{ $m->service_date?->format('Y-m-d') }} (Branch {{ $m->branch_id }})</option>
                                @endforeach
                            </select>
                            @error('drawer_menu_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            @error('drawer_selected_items') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Portion type') }}</label>
                                <select wire:model.live="drawer_daily_dish_portion_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target">
                                    <option value="plate">{{ __('Plate') }} ({{ __('main/salad/dessert combo') }})</option>
                                    <option value="half">{{ __('Half portion') }} (130)</option>
                                    <option value="full">{{ __('Full portion') }} (200)</option>
                                </select>
                            </div>
                            @if (in_array($drawer_daily_dish_portion_type, ['full', 'half'], true))
                                <div>
                                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Quantity') }}</label>
                                    <flux:input wire:model.live="drawer_daily_dish_portion_quantity" type="number" min="1" class="touch-target" />
                                </div>
                            @endif
                        </div>
                        @if ($drawer_menu_id && $drawer_daily_dish_portion_type === 'plate')
                            @php $dmenu = $drawer_menus->firstWhere('id', $drawer_menu_id); @endphp
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Tap an item to add one. Use Clear selection to reset.') }}</p>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                                @foreach($dmenu?->items ?? [] as $flatIdx => $row)
                                    @php
                                        $ddUnit = $row->menuItem->unit ?? 'each';
                                        $ddUnitLabel = \App\Models\MenuItem::unitOptions()[$ddUnit] ?? $ddUnit;
                                        $qty = (int) ($drawer_selected_items[$flatIdx]['quantity'] ?? 0);
                                    @endphp
                                    <div class="flex flex-col rounded-lg border border-neutral-200 bg-neutral-50/50 p-3 dark:border-neutral-700 dark:bg-neutral-800/50">
                                        <button
                                            type="button"
                                            wire:click="incrementDrawerDailyDishQuantity({{ $flatIdx }})"
                                            class="min-h-[72px] w-full touch-target flex flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-neutral-200 py-3 transition hover:border-primary-400 hover:bg-primary-50/50 dark:border-neutral-600 dark:hover:border-primary-500 dark:hover:bg-primary-950/30"
                                        >
                                            <span class="text-center text-sm font-medium text-neutral-800 dark:text-neutral-100 line-clamp-2">{{ $row->menuItem->name ?? '' }}</span>
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $ddUnitLabel }}</span>
                                            <span class="text-xl font-bold tabular-nums text-primary-600 dark:text-primary-400">{{ $qty }}</span>
                                        </button>
                                        <flux:button
                                            type="button"
                                            size="xs"
                                            variant="ghost"
                                            wire:click="clearDrawerDailyDishItem({{ $flatIdx }})"
                                            class="mt-2 w-full touch-target"
                                        >{{ __('Clear selection') }}</flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @endif
                    </div>
                @else
                    <div class="space-y-3 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h3>
                            <flux:button type="button" wire:click="addDrawerItemRow" variant="outline" size="sm" class="touch-target">{{ __('Add Row') }}</flux:button>
                        </div>
                        @error('drawer_selected_items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <div class="overflow-x-auto -mx-2">
                            <table class="w-full min-w-[520px] table-fixed text-sm">
                                <thead class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                                    <tr>
                                        <th class="px-2 py-2 w-40">{{ __('Item') }}</th>
                                        <th class="px-2 py-2 w-16">{{ __('Unit') }}</th>
                                        <th class="px-2 py-2 w-20">{{ __('Qty') }}</th>
                                        <th class="px-2 py-2 w-24">{{ __('Price') }}</th>
                                        <th class="px-2 py-2 w-16">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                    @forelse ($drawer_selected_items as $idx => $row)
                                        @php
                                            $qty = (float) ($row['quantity'] ?? 0);
                                            $price = (float) ($row['unit_price'] ?? 0);
                                            $discount = (float) ($row['discount_amount'] ?? 0);
                                            $lineTotal = max(0, ($qty * $price) - $discount);
                                            $drawerMi = $drawer_menu_items->get($row['menu_item_id'] ?? 0);
                                            $unitLabel = $drawerMi ? (\App\Models\MenuItem::unitOptions()[$drawerMi->unit] ?? $drawerMi->unit) : '—';
                                        @endphp
                                        <tr>
                                            <td class="px-2 py-2 align-top">
                                                <div class="relative" wire:ignore
                                                    x-data="menuItemLookup({
                                                        index: {{ $idx }},
                                                        initial: @js($drawer_item_search[$idx] ?? ''),
                                                        selectedId: @js($row['menu_item_id'] ?? null),
                                                        searchUrl: '{{ route('orders.menu-items.search') }}',
                                                        branchId: @entangle('drawer_branch_id')
                                                    })"
                                                    x-on:keydown.escape.stop="close()"
                                                    x-on:click.outside="close()">
                                                    <input type="text" class="w-full rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target"
                                                        x-model="query" x-on:input.debounce.200ms="onInput()" x-on:focus="onInput(true)"
                                                        placeholder="{{ __('Search item') }}" />
                                                    <template x-if="open">
                                                        <div x-ref="panel" x-bind:style="panelStyle" class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900 z-[10000]">
                                                            <div class="max-h-52 overflow-auto">
                                                                <template x-for="item in results" :key="item.id">
                                                                    <button type="button" class="w-full px-3 py-2.5 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80 touch-target" x-on:click="choose(item)">
                                                                        <span class="font-medium" x-text="item.name"></span>
                                                                        <span class="text-xs text-neutral-500" x-show="item.price_formatted" x-text="item.price_formatted"></span>
                                                                    </button>
                                                                </template>
                                                                <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500">{{ __('Searching...') }}</div>
                                                                <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500">{{ __('No items found.') }}</div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                            <td class="px-2 py-2 align-top text-sm text-neutral-700 dark:text-neutral-200">{{ $unitLabel }}</td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:input wire:model.live.debounce.300ms="drawer_selected_items.{{ $idx }}.quantity" type="number" step="0.001" class="w-18 touch-target" />
                                            </td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:input wire:model.live.debounce.300ms="drawer_selected_items.{{ $idx }}.unit_price" type="number" step="0.001" class="w-22 touch-target" />
                                            </td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:button type="button" size="sm" variant="danger" wire:click="removeDrawerItemRow({{ $idx }})" class="touch-target">{{ __('Remove') }}</flux:button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-2 py-3 text-sm text-neutral-500">{{ __('No items.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Order Discount') }}</label>
                            <flux:input wire:model.live="drawer_order_discount_amount" type="number" step="0.001" class="w-32 touch-target" />
                        </div>
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="flex flex-wrap gap-3 pt-4 border-t border-neutral-200 dark:border-neutral-700">
                    <flux:button type="button" variant="ghost" wire:click="closeCreateDrawer" class="touch-target">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" class="touch-target">{{ __('Create Order') }}</flux:button>
                </div>
            </form>
            @endif
        </div>
    </div>

    @once
        <script>
            document.addEventListener('alpine:init', function() {
                if (typeof Alpine.data('menuItemLookup') === 'function') return;
                Alpine.data('menuItemLookup', ({ index, initial, selectedId, searchUrl, branchId }) => ({
                    index,
                    query: initial || '',
                    selectedId: selectedId || null,
                    selectedLabel: initial || '',
                    searchUrl,
                    branchId,
                    results: [],
                    loading: false,
                    open: false,
                    hasSearched: false,
                    panelStyle: '',
                    controller: null,
                    onInput(force = false) {
                        if (this.selectedId !== null && this.query !== this.selectedLabel) {
                            this.selectedId = null;
                            this.selectedLabel = '';
                            this.$wire.clearMenuItemSelection(this.index);
                        }
                        const term = this.query.trim();
                        if (term.length < 2) {
                            this.open = false;
                            this.results = [];
                            this.hasSearched = false;
                            return;
                        }
                        this.fetchResults(term);
                    },
                    fetchResults(term) {
                        this.loading = true;
                        this.hasSearched = true;
                        this.open = true;
                        if (this.controller) this.controller.abort();
                        this.controller = new AbortController();
                        const params = new URLSearchParams({ q: term });
                        if (this.branchId) params.append('branch_id', this.branchId);
                        fetch(this.searchUrl + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                            signal: this.controller.signal,
                            credentials: 'same-origin',
                        })
                            .then((r) => r.ok ? r.json() : [])
                            .then((data) => {
                                this.results = Array.isArray(data) ? data : [];
                                this.loading = false;
                                this.$nextTick(() => this.positionDropdown());
                            })
                            .catch((e) => { if (e.name !== 'AbortError') { this.loading = false; this.results = []; } });
                    },
                    choose(item) {
                        const label = item.label || item.name || '';
                        this.query = label;
                        this.selectedLabel = label;
                        this.selectedId = item.id;
                        this.open = false;
                        this.results = [];
                        this.loading = false;
                        this.$wire.selectMenuItemPayload(this.index, item.id, label, item.price);
                    },
                    close() { this.open = false; },
                    positionDropdown() {
                        const input = this.$el.querySelector('input');
                        if (!input) return;
                        const rect = input.getBoundingClientRect();
                        this.panelStyle = 'position:fixed;left:' + rect.left + 'px;top:' + rect.bottom + 'px;width:' + rect.width + 'px;z-index:9999;';
                    },
                }));
            });
        </script>
    @endonce
</div>
