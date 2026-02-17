<?php

use App\Models\CompanyFoodProject;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public CompanyFoodProject $project;
    public string $name = '';
    public string $company_name = '';
    public string $start_date = '';
    public string $end_date = '';
    public string $slug = '';
    public bool $is_active = true;

    public function mount(): void
    {
        $this->name = $this->project->name;
        $this->company_name = $this->project->company_name;
        $this->start_date = $this->project->start_date->format('Y-m-d');
        $this->end_date = $this->project->end_date->format('Y-m-d');
        $this->slug = $this->project->slug;
        $this->is_active = $this->project->is_active;
    }

    public function update(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'slug' => ['required', 'string', 'max:255', 'unique:company_food_projects,slug,' . $this->project->id],
            'is_active' => ['boolean'],
        ]);

        $this->project->update([
            'name' => $this->name,
            'company_name' => $this->company_name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
        ]);

        session()->flash('status', __('Project updated.'));
        $this->redirectRoute('company-food.projects.show', $this->project, navigate: true);
    }

    public function with(): array
    {
        return [];
    }
}; ?>

<div class="w-full max-w-3xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit Company Food Project') }}</h1>
        <flux:button :href="route('company-food.projects.show', $project)" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="update" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Project Name')" required maxlength="255" />
            <flux:input wire:model="company_name" :label="__('Company Name')" required maxlength="255" />
            <flux:input wire:model="start_date" :label="__('Start Date')" type="date" required />
            <flux:input wire:model="end_date" :label="__('End Date')" type="date" required />
            <flux:input wire:model="slug" :label="__('Slug (for API URL)')" required maxlength="255" />
            <div class="sm:col-span-2">
                <flux:checkbox wire:model="is_active" :label="__('Active (accepts orders from external website)')" />
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('company-food.projects.show', $project)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('Update') }}</flux:button>
        </div>
    </form>
</div>
