<?php

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\OpsEvent;
use App\Services\Orders\OrderCreateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
    public array $main_item_search = [];
    public array $salad_item_search = [];
    public array $dessert_item_search = [];

    public function mount(int $branch, string $date): void
    {
        $this->branch = $branch ?: 1;
        $this->date = $date ?: now()->toDateString();

        $this->mains = [
            ['menu_item_id' => null, 'quantity' => 1.0],
        ];
        $this->main_item_search = [''];
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
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user?->hasAnyRole(['admin', 'manager', 'cashier'])) {
            abort(403);
        }
    }

    public function addMain(): void
    {
        $this->mains[] = ['menu_item_id' => null, 'quantity' => 1.0];
        $this->main_item_search[] = '';
    }

    public function removeMain(int $idx): void
    {
        unset($this->mains[$idx]);
        unset($this->main_item_search[$idx]);
        $this->mains = array_values($this->mains);
        $this->main_item_search = array_values($this->main_item_search);
        if (count($this->mains) === 0) {
            $this->mains = [['menu_item_id' => null, 'quantity' => 1.0]];
            $this->main_item_search = [''];
        }
    }

    public function addSalad(): void
    {
        $this->salads[] = ['menu_item_id' => null, 'quantity' => 1.0];
        $this->salad_item_search[] = '';
    }

    public function removeSalad(int $idx): void
    {
        unset($this->salads[$idx]);
        unset($this->salad_item_search[$idx]);
        $this->salads = array_values($this->salads);
        $this->salad_item_search = array_values($this->salad_item_search);
    }

    public function addDessert(): void
    {
        $this->desserts[] = ['menu_item_id' => null, 'quantity' => 1.0];
        $this->dessert_item_search[] = '';
    }

    public function removeDessert(int $idx): void
    {
        unset($this->desserts[$idx]);
        unset($this->dessert_item_search[$idx]);
        $this->desserts = array_values($this->desserts);
        $this->dessert_item_search = array_values($this->dessert_item_search);
    }

    public function setMainItemSelection(int $index, int $menuItemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->mains)) {
            return;
        }

        $this->mains[$index]['menu_item_id'] = $menuItemId;
        $this->main_item_search[$index] = trim($label);
    }

    public function clearMainItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->mains)) {
            return;
        }

        $this->mains[$index]['menu_item_id'] = null;
        $this->main_item_search[$index] = '';
    }

    public function setSaladItemSelection(int $index, int $menuItemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->salads)) {
            return;
        }

        $this->salads[$index]['menu_item_id'] = $menuItemId;
        $this->salad_item_search[$index] = trim($label);
    }

    public function clearSaladItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->salads)) {
            return;
        }

        $this->salads[$index]['menu_item_id'] = null;
        $this->salad_item_search[$index] = '';
    }

    public function setDessertItemSelection(int $index, int $menuItemId, string $label = ''): void
    {
        if (! array_key_exists($index, $this->desserts)) {
            return;
        }

        $this->desserts[$index]['menu_item_id'] = $menuItemId;
        $this->dessert_item_search[$index] = trim($label);
    }

    public function clearDessertItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->desserts)) {
            return;
        }

        $this->desserts[$index]['menu_item_id'] = null;
        $this->dessert_item_search[$index] = '';
    }

    public function save(OrderCreateService $orderCreateService): void
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

        $actorId = (int) (Auth::id() ?? 0);
        if ($actorId <= 0) {
            abort(403);
        }

        $rows = collect($this->mains)
            ->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']])
            ->merge(collect($this->salads)->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']]))
            ->merge(collect($this->desserts)->map(fn ($r) => ['menu_item_id' => (int) $r['menu_item_id'], 'quantity' => (float) $r['quantity']]));

        // Combine duplicate items
        $combined = $rows->groupBy('menu_item_id')->map(fn ($g) => (float) $g->sum('quantity'));

        $selectedItems = [];
        $idx = 0;
        foreach ($combined as $menuItemId => $qty) {
            $menuItem = MenuItem::find($menuItemId);
            $price = (float) ($menuItem?->selling_price_per_unit ?? 0);
            $selectedItems[] = [
                'menu_item_id' => (int) $menuItemId,
                'quantity' => (float) $qty,
                'unit_price' => $price,
                'discount_amount' => 0,
                'sort_order' => $idx++,
            ];
        }

        $payload = [
            'branch_id' => $this->branch,
            'source' => $data['source'],
            'is_daily_dish' => true,
            'type' => $data['type'],
            'status' => $data['status'],
            'customer_id' => null,
            'customer_name_snapshot' => $data['customer_name_snapshot'] ?? null,
            'customer_phone_snapshot' => $data['customer_phone_snapshot'] ?? null,
            'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
            'scheduled_date' => $this->date,
            'scheduled_time' => $data['scheduled_time'] ?? null,
            'notes' => $data['notes'] ?? null,
            'order_discount_amount' => 0,
            'menu_id' => $menu->id,
            'selected_items' => $selectedItems,
        ];

        try {
            $order = $orderCreateService->create($payload, $actorId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        OpsEvent::create([
            'event_type' => 'manual_order_created',
            'branch_id' => $order->branch_id,
            'service_date' => $this->date,
            'order_id' => $order->id,
            'actor_user_id' => $actorId,
            'metadata_json' => [
                'is_daily_dish' => true,
                'source' => $order->source,
            ],
            'created_at' => now(),
        ]);

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
                        <div
                            class="relative"
                            wire:ignore
                            x-data="dailyDishOptionLookup({
                                index: {{ $i }},
                                initial: @js($main_item_search[$i] ?? ''),
                                selectedId: @js($row['menu_item_id'] ?? null),
                                options: @js(collect($mainOptions)->map(function ($opt) {
                                    return [
                                        'id' => (int) $opt->menu_item_id,
                                        'name' => (string) ($opt->menuItem?->name ?? '—'),
                                        'code' => (string) ($opt->menuItem?->code ?? ''),
                                    ];
                                })->values()),
                                selectMethod: 'setMainItemSelection',
                                clearMethod: 'clearMainItemSelection'
                            })"
                            x-on:keydown.escape.stop="close()"
                            x-on:click.outside="close()"
                        >
                            <input
                                type="text"
                                class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:disabled:bg-neutral-700"
                                x-model="query"
                                x-on:input.debounce.150ms="onInput()"
                                x-on:focus="onInput(true)"
                                placeholder="{{ __('Search item') }}"
                                @disabled(! $menu)
                            />
                            <template x-if="open">
                                <div
                                    x-ref="panel"
                                    x-bind:style="panelStyle"
                                    class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="max-h-60 overflow-auto">
                                        <template x-for="item in results" :key="item.id">
                                            <button
                                                type="button"
                                                class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                x-on:click="choose(item)"
                                            >
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-medium" x-text="item.name"></span>
                                                    <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                </div>
                                            </button>
                                        </template>
                                        <div x-show="results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                            {{ __('No items found.') }}
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
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
                            <div
                                class="relative"
                                wire:ignore
                                x-data="dailyDishOptionLookup({
                                    index: {{ $i }},
                                    initial: @js($salad_item_search[$i] ?? ''),
                                    selectedId: @js($row['menu_item_id'] ?? null),
                                    options: @js(collect($saladOptions)->map(function ($opt) {
                                        return [
                                            'id' => (int) $opt->menu_item_id,
                                            'name' => (string) ($opt->menuItem?->name ?? '—'),
                                            'code' => (string) ($opt->menuItem?->code ?? ''),
                                        ];
                                    })->values()),
                                    selectMethod: 'setSaladItemSelection',
                                    clearMethod: 'clearSaladItemSelection'
                                })"
                                x-on:keydown.escape.stop="close()"
                                x-on:click.outside="close()"
                            >
                                <input
                                    type="text"
                                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:disabled:bg-neutral-700"
                                    x-model="query"
                                    x-on:input.debounce.150ms="onInput()"
                                    x-on:focus="onInput(true)"
                                    placeholder="{{ __('Search item') }}"
                                    @disabled(! $menu)
                                />
                                <template x-if="open">
                                    <div
                                        x-ref="panel"
                                        x-bind:style="panelStyle"
                                        class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="max-h-60 overflow-auto">
                                            <template x-for="item in results" :key="item.id">
                                                <button
                                                    type="button"
                                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                    x-on:click="choose(item)"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="font-medium" x-text="item.name"></span>
                                                        <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                    </div>
                                                </button>
                                            </template>
                                            <div x-show="results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                {{ __('No items found.') }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
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
                            <div
                                class="relative"
                                wire:ignore
                                x-data="dailyDishOptionLookup({
                                    index: {{ $i }},
                                    initial: @js($dessert_item_search[$i] ?? ''),
                                    selectedId: @js($row['menu_item_id'] ?? null),
                                    options: @js(collect($dessertOptions)->map(function ($opt) {
                                        return [
                                            'id' => (int) $opt->menu_item_id,
                                            'name' => (string) ($opt->menuItem?->name ?? '—'),
                                            'code' => (string) ($opt->menuItem?->code ?? ''),
                                        ];
                                    })->values()),
                                    selectMethod: 'setDessertItemSelection',
                                    clearMethod: 'clearDessertItemSelection'
                                })"
                                x-on:keydown.escape.stop="close()"
                                x-on:click.outside="close()"
                            >
                                <input
                                    type="text"
                                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:disabled:bg-neutral-700"
                                    x-model="query"
                                    x-on:input.debounce.150ms="onInput()"
                                    x-on:focus="onInput(true)"
                                    placeholder="{{ __('Search item') }}"
                                    @disabled(! $menu)
                                />
                                <template x-if="open">
                                    <div
                                        x-ref="panel"
                                        x-bind:style="panelStyle"
                                        class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="max-h-60 overflow-auto">
                                            <template x-for="item in results" :key="item.id">
                                                <button
                                                    type="button"
                                                    class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                    x-on:click="choose(item)"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="font-medium" x-text="item.name"></span>
                                                        <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                    </div>
                                                </button>
                                            </template>
                                            <div x-show="results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                {{ __('No items found.') }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
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

@once
    <script>
        const registerDailyDishOptionLookup = () => {
            if (!window.Alpine || window.__dailyDishOptionLookupRegistered) {
                return;
            }
            window.__dailyDishOptionLookupRegistered = true;

            window.Alpine.data('dailyDishOptionLookup', ({ index, initial, selectedId, options, selectMethod, clearMethod }) => ({
                index,
                query: initial || '',
                selectedId: selectedId || null,
                selectedLabel: initial || '',
                options: options || [],
                selectMethod,
                clearMethod,
                results: [],
                open: false,
                panelStyle: '',
                init() {
                    this.results = this.options;
                },
                onInput(force = false) {
                    if (this.selectedId !== null && this.query !== this.selectedLabel) {
                        this.selectedId = null;
                        this.selectedLabel = '';
                        this.$wire[this.clearMethod](this.index);
                    }

                    const term = this.query.trim().toLowerCase();
                    if (!force && term.length < 1) {
                        this.results = this.options;
                        this.close();
                        return;
                    }

                    this.results = this.options.filter((item) => {
                        const name = (item.name || '').toLowerCase();
                        const code = (item.code || '').toLowerCase();
                        return name.includes(term) || code.includes(term);
                    });
                    this.open = true;
                    this.$nextTick(() => this.positionDropdown());
                },
                choose(item) {
                    this.selectedId = item.id;
                    this.selectedLabel = item.name || '';
                    this.query = this.selectedLabel;
                    this.$wire[this.selectMethod](this.index, item.id, this.selectedLabel);
                    this.close();
                },
                close() {
                    this.open = false;
                },
                positionDropdown() {
                    const input = this.$el.querySelector('input');
                    if (!input) {
                        return;
                    }
                    const rect = input.getBoundingClientRect();
                    this.panelStyle = [
                        'position: fixed',
                        'left: ' + rect.left + 'px',
                        'top: ' + rect.bottom + 'px',
                        'width: ' + rect.width + 'px',
                        'z-index: 9999',
                    ].join('; ');
                },
            }));
        };

        if (window.Alpine) {
            registerDailyDishOptionLookup();
        } else {
            document.addEventListener('alpine:init', registerDailyDishOptionLookup, { once: true });
        }
    </script>
@endonce

