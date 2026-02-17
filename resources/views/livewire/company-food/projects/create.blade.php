<?php

use App\Models\CompanyFoodProject;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $company_name = '';
    public string $start_date = '';
    public string $end_date = '';
    public string $slug = '';
    public bool $is_active = true;

    public function updatedName(): void
    {
        if (empty($this->slug) && !empty($this->name)) {
            $this->slug = Str::slug($this->name . '-' . now()->format('Y-m'));
        }
    }

    public function create(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'slug' => ['required', 'string', 'max:255', 'unique:company_food_projects,slug'],
            'is_active' => ['boolean'],
        ]);

        CompanyFoodProject::create($data);

        session()->flash('status', __('Project created.'));
        $this->redirectRoute('company-food.projects.index', navigate: true);
    }

    public function with(): array
    {
        return [];
    }
}; ?>

<div class="w-full max-w-3xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Company Food Project') }}</h1>
        <flux:button :href="route('company-food.projects.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="create" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Project Name')" required maxlength="255" />
            <flux:input wire:model="company_name" :label="__('Company Name')" required maxlength="255" />
            <flux:input wire:model="start_date" :label="__('Start Date')" type="date" required />
            <flux:input wire:model="end_date" :label="__('End Date')" type="date" required />
            <flux:input wire:model="slug" :label="__('Slug (for API URL)')" required maxlength="255" placeholder="acme-corp-march-2026" />
            <div class="sm:col-span-2">
                <flux:checkbox wire:model="is_active" :label="__('Active (accepts orders from external website)')" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('company-food.projects.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
        </div>
    </form>
</div>
