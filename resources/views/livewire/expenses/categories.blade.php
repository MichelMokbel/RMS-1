<?php

use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $search = '';
    public string $active = 'all';
    public array $form = ['id' => null, 'name' => '', 'description' => '', 'active' => true];

    public function with(): array
    {
        $query = ExpenseCategory::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->active !== 'all', fn ($q) => $q->where('active', $this->active === '1'));

        return [
            'categories' => Schema::hasTable('expense_categories') ? $query->orderBy('name')->get() : collect(),
        ];
    }

    public function edit(int $id): void
    {
        $cat = ExpenseCategory::findOrFail($id);
        $this->form = [
            'id' => $cat->id,
            'name' => $cat->name,
            'description' => $cat->description,
            'active' => (bool) $cat->active,
        ];
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'form.name' => ['required', 'string', 'max:100'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.active' => ['boolean'],
        ])['form'];

        $id = $data['id'] ?? null;
        unset($data['id']);

        if ($id) {
            $cat = ExpenseCategory::findOrFail($id);
            $cat->update($data);
        } else {
            ExpenseCategory::create($data);
        }

        $this->form = ['id' => null, 'name' => '', 'description' => '', 'active' => true];
        session()->flash('status', __('Saved.'));
    }

    public function delete(int $id): void
    {
        $cat = ExpenseCategory::findOrFail($id);
        if ($cat->isInUse()) {
            $this->addError('delete', __('Category in use and cannot be deleted.'));
            return;
        }
        $cat->delete();
        session()->flash('status', __('Deleted.'));
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Expense Categories') }}</h1>
        <flux:button :href="route('expenses.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('delete')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ $message }}</div>
    @enderror

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name') }}" />
            </div>
            <div class="w-40">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Active') }}</label>
                <select wire:model="active" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="1">{{ __('Active') }}</option>
                    <option value="0">{{ __('Inactive') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $form['id'] ? __('Edit Category') : __('New Category') }}</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <flux:input wire:model="form.name" :label="__('Name')" />
            <flux:input wire:model="form.description" :label="__('Description')" />
            <div class="md:col-span-2">
                <flux:checkbox wire:model="form.active" :label="__('Active')" />
            </div>
        </div>
        <div class="flex justify-end">
            <flux:button type="button" wire:click="save" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Active') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse($categories as $cat)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $cat->name }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $cat->description }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $cat->active ? __('Yes') : __('No') }}</td>
                        <td class="px-3 py-2 text-sm">
                            <div class="flex gap-2">
                                <flux:button size="xs" wire:click="edit({{ $cat->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" wire:click="delete({{ $cat->id }})" variant="ghost">{{ __('Delete') }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No categories') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
