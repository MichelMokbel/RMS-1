<?php

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use App\Services\DailyDish\DailyDishMenuService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch;
    public string $serviceDate;
    public ?DailyDishMenu $menu = null;
    public string $status = 'draft';
    public ?string $notes = null;

    public array $items = [];

    public function mount(): void
    {
        $this->loadMenu();
    }

    public function with(): array
    {
        $menuItems = Schema::hasTable('menu_items')
            ? MenuItem::where('is_active', 1)->orderBy('name')->get()
            : collect();

        return [
            'menuItems' => $menuItems,
        ];
    }

    private function loadMenu(): void
    {
        $existing = DailyDishMenu::where('branch_id', $this->branch)
            ->whereDate('service_date', $this->serviceDate)
            ->with('items')
            ->first();

        if ($existing) {
            $this->menu = $existing;
            $this->status = $existing->status;
            $this->notes = $existing->notes;
            $this->items = $existing->items->map(function (DailyDishMenuItem $item) {
                return [
                    'menu_item_id' => $item->menu_item_id,
                    'role' => $item->role,
                    'sort_order' => $item->sort_order,
                    'is_required' => $item->is_required,
                ];
            })->values()->toArray();
        } else {
            $this->items = [];
            $this->status = 'draft';
            $this->notes = null;
        }
    }

    public function addItem(): void
    {
        if (! $this->isEditable()) {
            return;
        }

        $this->items[] = [
            'menu_item_id' => null,
            'role' => 'main',
            'sort_order' => 0,
            'is_required' => false,
        ];
    }

    public function removeItem(int $idx): void
    {
        if (! $this->isEditable()) {
            return;
        }

        unset($this->items[$idx]);
        $this->items = array_values($this->items);
    }

    public function save(DailyDishMenuService $service): void
    {
        if (! $this->isEditable()) {
            session()->flash('status', __('Menu is not editable.'));
            return;
        }

        $data = $this->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.role' => ['required', 'in:main,diet,vegetarian,salad,dessert,addon'],
            'items.*.sort_order' => ['nullable', 'integer'],
            'items.*.is_required' => ['boolean'],
        ]);

        try {
            $menu = $service->upsertMenu(
                $this->branch,
                $this->serviceDate,
                [
                    'notes' => $data['notes'] ?? null,
                    'items' => $data['items'],
                ],
                Illuminate\Support\Facades\Auth::id()
            );

            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu saved.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Save failed.');
            session()->flash('status', $message);
        }
    }

    public function publish(DailyDishMenuService $service): void
    {
        if (! $this->menu) {
            session()->flash('status', __('Create menu first.'));
            return;
        }
        try {
            $menu = $service->publish($this->menu, Illuminate\Support\Facades\Auth::id());
            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu published.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Publish failed.');
            session()->flash('status', $message);
        }
    }

    public function unpublish(DailyDishMenuService $service): void
    {
        if (! $this->menu) {
            session()->flash('status', __('Create menu first.'));
            return;
        }
        try {
            $menu = $service->unpublish($this->menu, Illuminate\Support\Facades\Auth::id());
            $this->menu = $menu;
            $this->status = $menu->status;
            session()->flash('status', __('Menu reverted to draft.'));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? __('Unpublish failed.');
            session()->flash('status', $message);
        }
    }

    private function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Daily Dish Menu') }}</p>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Branch') }} {{ $branch }} · {{ $serviceDate }}
            </h1>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('daily-dish.menus.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                {{ $status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($status === 'published' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100') }}">
                {{ ucfirst($status) }}
            </span>
        </div>

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" :disabled="$status !== 'draft'" />

        <div class="flex gap-2">
            @if($status === 'draft')
                <flux:button type="button" wire:click="addItem">{{ __('Add Item') }}</flux:button>
            @endif
            @if($status === 'draft')
                <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
            @endif
            @if($status === 'draft')
                <flux:button type="button" wire:click="publish" variant="primary">{{ __('Publish') }}</flux:button>
            @elseif($status === 'published')
                <flux:button type="button" wire:click="unpublish">{{ __('Unpublish') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>

        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Menu Item') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Role') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Sort') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Required') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($items as $index => $row)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.menu_item_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled($status !== 'draft')>
                                    <option value="">{{ __('Select item') }}</option>
                                    @foreach($menuItems as $mi)
                                        <option value="{{ $mi->id }}">{{ $mi->name }} ({{ $mi->price ?? '' }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <select wire:model="items.{{ $index }}.role" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" @disabled($status !== 'draft')>
                                    <option value="main">{{ __('Main') }}</option>
                                    <option value="diet">{{ __('Diet') }}</option>
                                    <option value="vegetarian">{{ __('Vegetarian') }}</option>
                                    <option value="salad">{{ __('Salad') }}</option>
                                    <option value="dessert">{{ __('Dessert') }}</option>
                                    <option value="addon">{{ __('Addon') }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:input wire:model="items.{{ $index }}.sort_order" type="number" class="w-24" :disabled="$status !== 'draft'" />
                            </td>
                            <td class="px-3 py-2 text-sm">
                                <flux:checkbox wire:model="items.{{ $index }}.is_required" :label="__('Required')" :disabled="$status !== 'draft'" />
                            </td>
                            <td class="px-3 py-2 text-sm text-right">
                                @if($status === 'draft')
                                    <flux:button type="button" wire:click="removeItem({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No items yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

