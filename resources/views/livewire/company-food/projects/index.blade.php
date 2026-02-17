<?php

use App\Models\CompanyFoodProject;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'all';

    protected $paginationTheme = 'tailwind';

    public function updating($field): void
    {
        if (in_array($field, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'projects' => $this->query()->withCount('orders')->paginate(10),
        ];
    }

    private function query()
    {
        return CompanyFoodProject::query()
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->search, function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('slug', 'like', $term);
                });
            })
            ->orderByDesc('start_date');
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Company Food Projects') }}</h1>
        <flux:button :href="route('company-food.projects.create')" wire:navigate variant="primary">{{ __('New Project') }}</flux:button>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name, company, slug') }}" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>
        </div>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Company') }}</flux:table.column>
            <flux:table.column>{{ __('Dates') }}</flux:table.column>
            <flux:table.column>{{ __('Orders') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($projects as $project)
                <flux:table.row>
                    <flux:table.cell>{{ $project->name }}</flux:table.cell>
                    <flux:table.cell>{{ $project->company_name }}</flux:table.cell>
                    <flux:table.cell>{{ $project->start_date->format('M j, Y') }} – {{ $project->end_date->format('M j, Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $project->orders_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if($project->is_active)
                            <flux:badge color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('company-food.projects.show', $project)" wire:navigate size="sm" variant="ghost">{{ __('View') }}</flux:button>
                        <flux:button :href="route('company-food.projects.edit', $project)" wire:navigate size="sm" variant="ghost">{{ __('Edit') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No projects found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $projects->links() }}
    </div>
</div>
