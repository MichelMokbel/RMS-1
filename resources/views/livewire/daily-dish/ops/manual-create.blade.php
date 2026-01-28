<?php

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\OpsEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch = 1;
    public string $date;

    public string $type = 'Delivery';
    public string $source = 'Backoffice';
    public string $status = 'Confirmed';
    public ?string $scheduled_time = null;

    public ?string $customer_name_snapshot = null;
    public ?string $customer_phone_snapshot = null;
    public ?string $delivery_address_snapshot = null;
    public ?string $notes = null;

    /** @var array<int,array{menu_item_id:?int,quantity:float}> */
    public array $mains = [];
    /** @var array<int,array{menu_item_id:?int,quantity:float}> */
    public array $salads = [];
    /** @var array<int,array{menu_item_id:?int,quantity:float}> */
    public array $desserts = [];

    public function mount(int $branch, string $date): void
    {
        $this->branch = $branch ?: 1;
        $this->date = $date ?: now()->toDateString();

        $this->mains = [
            ['menu_item_id' => null, 'quantity' => 1.0],
        ];
    }

    public function with(): array
    {
        $menu = DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $this->branch)
            ->whereDate('service_date', $this->date)
            ->where('status', 'published')
            ->first();

        $itemsByRole = collect();
        if ($menu) {
            $itemsByRole = $menu->items->groupBy('role')->toBase();
        }

        $mains = $itemsByRole->only(['main', 'diet', 'vegetarian'])->flatten(1)->values();
        $salads = $itemsByRole->get('salad', collect())->values();
        $desserts = $itemsByRole->get('dessert', collect())->values();

        return [
            'menu' => $menu,
            'mainOptions' => $mains,
            'saladOptions' => $salads,
            'dessertOptions' => $desserts,
        ];
    }

    private function authorizeCashier(): void
    {
        if (! auth()->user()?->hasAnyRole(['admin', 'manager', 'cashier'])) {
            abort(403);
        }
    }

    public function addMain(): void
    {
        $this->mains[] = ['menu_item_id' => null, 'quantity' => 1.0];
    }

    public function removeMain(int $idx): void
    {
        unset($this->mains[$idx]);
        $this->mains = array_values($this->mains);
        if (count($this->mains) === 0) {
            $this->mains = [['menu_item_id' => null, 'quantity' => 1.0]];
        }
    }

    public function addSalad(): void
    {
        $this->salads[] = ['menu_item_id' => null, 'quantity' => 1.0];
    }

    public function removeSalad(int $idx): void
    {
        unset($this->salads[$idx]);
        $this->salads = array_values($this->salads);
    }

    public function addDessert(): void
    {
        $this->desserts[] = ['menu_item_id' => null, 'quantity' => 1.0];
    }

    public function removeDessert(int $idx): void
    {
        unset($this->desserts[$idx]);
        $this->desserts = array_values($this->desserts);
    }

    public function save(OrderNumberService $numberService, OrderTotalsService $totalsService): void
    {
        $this->authorizeCashier();

        $menu = DailyDishMenu::with(['items.menuItem'])
            ->where('branch_id', $this->branch)
            ->whereDate('service_date', $this->date)
            ->where('status', 'published')
            ->first();

        if (! $menu) {
            $this->addError('date', __('A published daily dish menu is required.'));
            return;
        }

        $data = $this->validate([
            'type' => ['required', 'in:DineIn,Takeaway,Delivery'],
            'source' => ['required', 'in:POS,Phone,WhatsApp,Backoffice'],
            'status' => ['required', 'in:Confirmed,Draft'],
            'scheduled_time' => ['nullable'],
            'customer_name_snapshot' => ['nullable', 'string', 'max:255'],
            'customer_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'delivery_address_snapshot' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'mains' => ['required', 'array', 'min:1'],
            'mains.*.menu_item_id' => ['required', 'integer'],
            'mains.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'salads' => ['array'],
            'salads.*.menu_item_id' => ['required', 'integer'],
            'salads.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'desserts' => ['array'],
            'desserts.*.menu_item_id' => ['required', 'integer'],
            'desserts.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $allowedIds = $menu->items->pluck('menu_item_id')->unique()->values()->all();
        $selectedIds = collect($this->mains)->pluck('menu_item_id')
            ->merge(collect($this->salads)->pluck('menu_item_id'))
            ->merge(collect($this->desserts)->pluck('menu_item_id'))
            ->filter()
            ->values()
            ->all();

        foreach ($selectedIds as $id) {
            if (! in_array((int) $id, $allowedIds, true)) {
                $this->addError('mains', __('Selected items must be from the published menu.'));
                return;
            }
        }

        /** @var Order $order */
        $order = DB::transaction(function () use ($data, $numberService, $totalsService) {
            $order = Order::create([
                'order_number' => $numberService->generate(),
                'branch_id' => $this->branch,
                'source' => $data['source'],
                'is_daily_dish' => 1,
                'type' => $data['type'],
                'status' => $data['status'],
                'customer_id' => null,
                'customer_name_snapshot' => $data['customer_name_snapshot'] ?? null,
                'customer_phone_snapshot' => $data['customer_phone_snapshot'] ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'scheduled_date' => $this->date,
                'scheduled_time' => $data['scheduled_time'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total_before_tax' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'created_by' => Illuminate\Support\Facades\Auth::id(),
                'created_at' => now(),
            ]);

            $rows = collect($this->mains)
                ->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']])
                ->merge(collect($this->salads)->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']]))
                ->merge(collect($this->desserts)->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']]));

            // Combine duplicate items
            $combined = $rows->groupBy('menu_item_id')->map(fn ($g) => (float) $g->sum('quantity'));

            $idx = 0;
            foreach ($combined as $menuItemId => $qty) {
                $menuItem = MenuItem::find($menuItemId);
                $price = (float) ($menuItem->selling_price_per_unit ?? 0);
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $menuItemId,
                    'description_snapshot' => trim(($menuItem->code ?? '').' '.$menuItem->name),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'discount_amount' => 0,
                    'line_total' => round($qty * $price, 3),
                    'status' => 'Pending',
                    'sort_order' => $idx++,
                ]);
            }

            $totalsService->recalc($order);

            OpsEvent::create([
                'event_type' => 'manual_order_created',
                'branch_id' => $order->branch_id,
                'service_date' => $this->date,
                'order_id' => $order->id,
                'actor_user_id' => (int) Illuminate\Support\Facades\Auth::id(),
                'metadata_json' => [
                    'is_daily_dish' => true,
                    'source' => $order->source,
                ],
                'created_at' => now(),
            ]);

            return $order;
        });

        session()->flash('status', __('Manual daily dish order created.'));
        $this->redirectRoute('daily-dish.ops.day', [$this->branch, $this->date], navigate: true);
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manual Daily Dish Order') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Branch') }} {{ $branch }} · {{ $date }}</h1>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('daily-dish.ops.day', [$branch, $date])" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if(! $menu)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ __('A published daily dish menu is required to create manual daily dish orders.') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                <select wire:model="type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Delivery">{{ __('Delivery') }}</option>
                    <option value="Takeaway">{{ __('Takeaway') }}</option>
                    <option value="DineIn">{{ __('Dine In') }}</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Source') }}</label>
                <select wire:model="source" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="Backoffice">{{ __('Backoffice') }}</option>
                    <option value="Phone">{{ __('Phone') }}</option>
                    <option value="WhatsApp">{{ __('WhatsApp') }}</option>
                    <option value="POS">{{ __('POS') }}</option>
                </select>
            </div>
            <flux:input wire:model="scheduled_time" type="time" :label="__('Time (optional)')" />
        </div>

        <flux:input wire:model="customer_name_snapshot" :label="__('Customer Name')" />
        <flux:input wire:model="customer_phone_snapshot" :label="__('Customer Phone')" />
        <flux:textarea wire:model="delivery_address_snapshot" :label="__('Delivery Address')" rows="2" />
        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Main Dishes') }}</h2>
            <flux:button type="button" wire:click="addMain">{{ __('Add Main') }}</flux:button>
        </div>

        <div class="space-y-3">
            @foreach($mains as $i => $row)
                <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_140px_auto] items-end">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Main item') }}</label>
                        <select wire:model="mains.{{ $i }}.menu_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $menu)>
                            <option value="">{{ __('Select item') }}</option>
                            @foreach($mainOptions as $opt)
                                <option value="{{ $opt->menu_item_id }}">{{ $opt->menuItem?->name ?? '—' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="mains.{{ $i }}.quantity" type="number" step="0.001" min="0.001" :label="__('Qty')" @disabled(! $menu) />
                    <div class="pt-6">
                        <flux:button type="button" wire:click="removeMain({{ $i }})" variant="ghost">{{ __('Remove') }}</flux:button>
                    </div>
                </div>
            @endforeach
            @error('mains') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Salad (optional)') }}</h2>
                <flux:button type="button" wire:click="addSalad" variant="ghost">{{ __('Add') }}</flux:button>
            </div>
            <div class="space-y-3">
                @forelse($salads as $i => $row)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_140px_auto] items-end">
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Salad item') }}</label>
                            <select wire:model="salads.{{ $i }}.menu_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $menu)>
                                <option value="">{{ __('Select item') }}</option>
                                @foreach($saladOptions as $opt)
                                    <option value="{{ $opt->menu_item_id }}">{{ $opt->menuItem?->name ?? '—' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <flux:input wire:model="salads.{{ $i }}.quantity" type="number" step="0.001" min="0.001" :label="__('Qty')" @disabled(! $menu) />
                        <div class="pt-6">
                            <flux:button type="button" wire:click="removeSalad({{ $i }})" variant="ghost">{{ __('Remove') }}</flux:button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No salad items.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Dessert (optional)') }}</h2>
                <flux:button type="button" wire:click="addDessert" variant="ghost">{{ __('Add') }}</flux:button>
            </div>
            <div class="space-y-3">
                @forelse($desserts as $i => $row)
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_140px_auto] items-end">
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Dessert item') }}</label>
                            <select wire:model="desserts.{{ $i }}.menu_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled(! $menu)>
                                <option value="">{{ __('Select item') }}</option>
                                @foreach($dessertOptions as $opt)
                                    <option value="{{ $opt->menu_item_id }}">{{ $opt->menuItem?->name ?? '—' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <flux:input wire:model="desserts.{{ $i }}.quantity" type="number" step="0.001" min="0.001" :label="__('Qty')" @disabled(! $menu) />
                        <div class="pt-6">
                            <flux:button type="button" wire:click="removeDessert({{ $i }})" variant="ghost">{{ __('Remove') }}</flux:button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No dessert items.') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button :href="route('daily-dish.ops.day', [$branch, $date])" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
        <flux:button type="button" wire:click="save" variant="primary" :disabled="!$menu">{{ __('Create Order') }}</flux:button>
    </div>
</div>


