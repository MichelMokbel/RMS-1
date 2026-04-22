<?php

use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingBriefService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $title = '';
    public string $description = '';
    public string $objectives = '';
    public string $target_audience = '';
    public string $budget_notes = '';
    public ?string $due_date = null;
    public ?int $campaign_id = null;

    public function save(MarketingBriefService $briefService): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'objectives' => ['nullable', 'string'],
            'target_audience' => ['nullable', 'string'],
            'budget_notes' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date'],
            'campaign_id' => ['nullable', 'integer', 'exists:marketing_campaigns,id'],
        ]);

        $brief = $briefService->create([
            'title' => $this->title,
            'description' => $this->description ?: null,
            'objectives' => $this->objectives ?: null,
            'target_audience' => $this->target_audience ?: null,
            'budget_notes' => $this->budget_notes ?: null,
            'due_date' => $this->due_date ?: null,
            'campaign_id' => $this->campaign_id,
        ], auth()->id());

        session()->flash('status', __('Brief created.'));
        $this->redirect(route('marketing.briefs.show', $brief), navigate: true);
    }

    public function with(): array
    {
        return [
            'campaigns' => MarketingCampaign::query()
                ->with('platformAccount')
                ->orderBy('name')
                ->get(['id', 'name', 'platform_account_id']),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center gap-3">
        <flux:button :href="route('marketing.briefs.index')" variant="ghost" icon="arrow-left" size="sm" wire:navigate />
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('New Brief') }}</h1>
    </div>

    <form wire:submit="save" class="space-y-6 max-w-2xl">
        <div class="rounded-lg border border-zinc-200 bg-white p-6 space-y-4 dark:border-zinc-700 dark:bg-zinc-800">

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Title') }} <span class="text-red-500">*</span></label>
                <flux:input wire:model="title" placeholder="{{ __('e.g. Ramadan Campaign — Social Assets') }}" />
                @error('title') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Linked Campaign') }}</label>
                <select wire:model="campaign_id" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                    <option value="">{{ __('None (standalone brief)') }}</option>
                    @foreach($campaigns as $campaign)
                        <option value="{{ $campaign->id }}">
                            {{ $campaign->name }} ({{ ucfirst($campaign->platformAccount->platform) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Due Date') }}</label>
                <flux:input wire:model="due_date" type="date" />
                @error('due_date') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Description') }}</label>
                <textarea wire:model="description" rows="3" placeholder="{{ __('Overall brief summary…') }}"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                </textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Objectives') }}</label>
                <textarea wire:model="objectives" rows="2" placeholder="{{ __('What should this campaign achieve?') }}"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                </textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Target Audience') }}</label>
                <textarea wire:model="target_audience" rows="2" placeholder="{{ __('Who is this campaign targeting?') }}"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                </textarea>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Budget Notes') }}</label>
                <textarea wire:model="budget_notes" rows="2" placeholder="{{ __('Budget guidance, constraints…') }}"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                </textarea>
            </div>
        </div>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">{{ __('Create Brief') }}</flux:button>
            <flux:button :href="route('marketing.briefs.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
