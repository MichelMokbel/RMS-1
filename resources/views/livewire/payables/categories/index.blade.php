<?php

use App\Models\ExpenseCategory;
use App\Services\AP\ExpenseCategoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = 'all';

    public ?int $editing_category_id = null;

    public string $category_name = '';

    public ?string $category_description = null;

    public bool $category_active = true;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        Gate::authorize('finance.write');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function saveCategory(ExpenseCategoryService $service): void
    {
        Gate::authorize('finance.write');

        $this->category_name = trim($this->category_name);
        $this->category_description = trim((string) $this->category_description) ?: null;

        $data = $this->validate([
            'category_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('expense_categories', 'name')->ignore($this->editing_category_id),
            ],
            'category_description' => ['nullable', 'string', 'max:255'],
            'category_active' => ['boolean'],
        ]);

        $category = $this->editing_category_id
            ? ExpenseCategory::query()->findOrFail($this->editing_category_id)
            : null;
        $wasEditing = $category !== null;

        $service->save([
            'name' => $data['category_name'],
            'description' => $data['category_description'],
            'active' => $data['category_active'],
        ], (int) auth()->id(), $category);

        session()->flash('status', $wasEditing
            ? __('Expense category updated.')
            : __('Expense category created.'));

        $this->resetCategoryForm();
        $this->resetPage();
    }

    public function editCategory(int $categoryId): void
    {
        Gate::authorize('finance.write');

        $category = ExpenseCategory::query()->findOrFail($categoryId);
        $this->editing_category_id = $category->id;
        $this->category_name = $category->name;
        $this->category_description = $category->description;
        $this->category_active = (bool) $category->active;
        $this->resetValidation();
    }

    public function toggleCategory(int $categoryId, ExpenseCategoryService $service): void
    {
        Gate::authorize('finance.write');

        $category = ExpenseCategory::query()->findOrFail($categoryId);
        $category = $service->setActive($category, ! $category->active, (int) auth()->id());

        if ($this->editing_category_id === $category->id) {
            $this->category_active = (bool) $category->active;
        }

        session()->flash('status', $category->active
            ? __('Expense category activated.')
            : __('Expense category deactivated.'));
    }

    public function resetCategoryForm(): void
    {
        $this->editing_category_id = null;
        $this->category_name = '';
        $this->category_description = null;
        $this->category_active = true;
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'categories' => ExpenseCategory::query()
                ->withCount('expenses')
                ->when($this->search !== '', function (Builder $query): void {
                    $search = '%'.trim($this->search).'%';
                    $query->where(function (Builder $nested) use ($search): void {
                        $nested->where('name', 'like', $search)
                            ->orWhere('description', 'like', $search);
                    });
                })
                ->when($this->status !== 'all', fn (Builder $query) => $query->where('active', $this->status === 'active'))
                ->orderBy('name')
                ->paginate(15),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Expense Categories') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manage the categories available for AP expenses and petty cash imports.') }}</p>
        </div>
        <flux:button :href="route('payables.index', ['tab' => 'expenses'])" wire:navigate variant="ghost" icon="arrow-left">
            {{ __('Back to Accounts Payable') }}
        </flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
        <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $editing_category_id ? __('Edit Category') : __('Add Category') }}
            </h2>

            <form wire:submit="saveCategory" class="mt-4 space-y-4">
                <flux:input wire:model="category_name" :label="__('Name')" maxlength="100" required />
                <flux:textarea wire:model="category_description" :label="__('Description (optional)')" maxlength="255" rows="3" />

                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="checkbox" wire:model="category_active" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500">
                    {{ __('Active and available for new expenses') }}
                </label>

                <div class="flex justify-end gap-2">
                    @if ($editing_category_id)
                        <flux:button type="button" wire:click="resetCategoryForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                    @endif
                    <flux:button type="submit" wire:loading.attr="disabled" wire:target="saveCategory">
                        {{ $editing_category_id ? __('Save Changes') : __('Add Category') }}
                    </flux:button>
                </div>
            </form>
        </section>

        <section class="space-y-4 rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Name or description')" class="w-full md:max-w-sm" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                    <select wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
            </div>

            <div class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
                {{ __('Deactivate categories that are no longer used. Existing invoices keep their historical category.') }}
            </div>

            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Expenses') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($categories as $category)
                            <tr wire:key="expense-category-{{ $category->id }}">
                                <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                    <div class="font-semibold">{{ $category->name }}</div>
                                    @if ($category->description)
                                        <div class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $category->description }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs font-medium {{ $category->active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300' }}">
                                        {{ $category->active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right text-sm text-neutral-700 dark:text-neutral-200">{{ $category->expenses_count }}</td>
                                <td class="px-3 py-3 text-right text-sm">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <flux:button type="button" wire:click="editCategory({{ $category->id }})" size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                                        <flux:button
                                            type="button"
                                            wire:click="toggleCategory({{ $category->id }})"
                                            wire:confirm="{{ $category->active ? __('Deactivate this category? It will no longer be available for new expenses.') : __('Activate this category?') }}"
                                            size="xs"
                                            variant="ghost"
                                        >
                                            {{ $category->active ? __('Deactivate') : __('Activate') }}
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No expense categories found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $categories->links() }}
        </section>
    </div>
</div>
