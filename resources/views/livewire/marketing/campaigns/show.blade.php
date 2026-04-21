<?php

use App\Models\MarketingAsset;
use App\Models\MarketingAssetUsage;
use App\Models\MarketingCampaign;
use App\Models\MarketingUtm;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingCampaignQueryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public MarketingCampaign $campaign;

    public string $internal_notes = '';

    public bool $editingNotes = false;

    public ?int $asset_id = null;

    public string $asset_note = '';

    public string $utm_source = '';

    public string $utm_medium = '';

    public string $utm_campaign = '';

    public string $utm_content = '';

    public string $utm_term = '';

    public string $landing_page_url = '';

    public string $utm_notes = '';

    public function mount(MarketingCampaign $campaign): void
    {
        $this->campaign = $campaign->load(['platformAccount', 'adSets', 'utms', 'briefs', 'assetUsages.asset']);
        $this->internal_notes = $campaign->internal_notes ?? '';
        $this->fillUtmForm();
    }

    public function saveNotes(MarketingActivityLogService $activityLog): void
    {
        $this->campaign->update(['internal_notes' => $this->internal_notes]);
        $activityLog->log('campaign.notes.updated', auth()->id(), $this->campaign);
        $this->editingNotes = false;
        session()->flash('status', __('Notes saved.'));
    }

    public function linkAsset(MarketingActivityLogService $activityLog): void
    {
        $this->validate([
            'asset_id' => ['required', 'integer', 'exists:marketing_assets,id'],
            'asset_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $usage = MarketingAssetUsage::query()->firstOrCreate([
            'asset_id' => $this->asset_id,
            'usageable_type' => MarketingCampaign::class,
            'usageable_id' => $this->campaign->id,
        ], [
            'note' => $this->asset_note ?: null,
            'created_by' => auth()->id(),
        ]);

        if (! $usage->wasRecentlyCreated && $this->asset_note !== '') {
            $usage->update(['note' => $this->asset_note]);
        }

        $activityLog->log('campaign.asset.linked', auth()->id(), $this->campaign, [
            'asset_id' => $this->asset_id,
        ]);

        $this->asset_id = null;
        $this->asset_note = '';
        $this->campaign->refresh()->load(['platformAccount', 'adSets', 'utms', 'briefs', 'assetUsages.asset']);
        session()->flash('status', __('Asset linked to campaign.'));
    }

    public function unlinkAsset(int $usageId, MarketingActivityLogService $activityLog): void
    {
        $usage = MarketingAssetUsage::query()
            ->where('usageable_type', MarketingCampaign::class)
            ->where('usageable_id', $this->campaign->id)
            ->findOrFail($usageId);

        $assetId = $usage->asset_id;
        $usage->delete();

        $activityLog->log('campaign.asset.unlinked', auth()->id(), $this->campaign, [
            'asset_id' => $assetId,
        ]);

        $this->campaign->refresh()->load(['platformAccount', 'adSets', 'utms', 'briefs', 'assetUsages.asset']);
        session()->flash('status', __('Asset unlinked from campaign.'));
    }

    public function saveUtm(MarketingActivityLogService $activityLog): void
    {
        $validated = $this->validate([
            'utm_source' => ['required', 'string', 'max:255'],
            'utm_medium' => ['required', 'string', 'max:255'],
            'utm_campaign' => ['required', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'landing_page_url' => ['required', 'url', 'max:255'],
            'utm_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $utm = MarketingUtm::query()->updateOrCreate([
            'campaign_id' => $this->campaign->id,
        ], [
            'utm_source' => $validated['utm_source'],
            'utm_medium' => $validated['utm_medium'],
            'utm_campaign' => $validated['utm_campaign'],
            'utm_content' => $validated['utm_content'] ?: null,
            'utm_term' => $validated['utm_term'] ?: null,
            'landing_page_url' => $validated['landing_page_url'],
            'notes' => $validated['utm_notes'] ?: null,
            'created_by' => auth()->id(),
        ]);

        $activityLog->log('campaign.utm.saved', auth()->id(), $this->campaign, [
            'utm_id' => $utm->id,
        ]);

        $this->campaign->refresh()->load(['platformAccount', 'adSets', 'utms', 'briefs', 'assetUsages.asset']);
        $this->fillUtmForm();
        session()->flash('status', __('UTM tracking saved.'));
    }

    public function with(MarketingCampaignQueryService $query): array
    {
        $linkedAssetIds = $this->campaign->assetUsages->pluck('asset_id')->all();
        $performance = $query->getMtdCampaignPerformanceAggregates([$this->campaign->id])->first();

        return [
            'campaign' => $this->campaign,
            'performance' => $performance,
            'availableAssets' => MarketingAsset::query()
                ->active()
                ->whereNotIn('id', $linkedAssetIds)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'status']),
        ];
    }

    private function fillUtmForm(): void
    {
        $utm = $this->campaign->utms->first();

        $this->utm_source = $utm->utm_source ?? strtolower($this->campaign->platformAccount->platform ?? '');
        $this->utm_medium = $utm->utm_medium ?? 'paid_social';
        $this->utm_campaign = $utm->utm_campaign ?? str($this->campaign->name)->slug('_')->toString();
        $this->utm_content = $utm->utm_content ?? '';
        $this->utm_term = $utm->utm_term ?? '';
        $this->landing_page_url = $utm->landing_page_url ?? '';
        $this->utm_notes = $utm->notes ?? '';
    }
}; ?>

<div class="app-page space-y-6">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <flux:button :href="route('marketing.campaigns.index')" variant="ghost" icon="arrow-left" size="sm" wire:navigate />
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ $campaign->name }}</h1>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                    @if($campaign->status === 'ACTIVE') bg-emerald-100 text-emerald-700
                    @elseif($campaign->status === 'PAUSED') bg-amber-100 text-amber-700
                    @else bg-zinc-100 text-zinc-600 @endif">
                    {{ ucfirst(strtolower($campaign->status)) }}
                </span>
            </div>
            <p class="mt-1 text-sm text-zinc-500">
                {{ ucfirst($campaign->platformAccount->platform) }} · {{ $campaign->platformAccount->account_name }}
                @if($campaign->objective) · {{ $campaign->objective }}@endif
            </p>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    {{-- KPI row --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Daily Budget') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $campaign->daily_budget ? number_format($campaign->daily_budget, 2) : '—' }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('MTD Spend') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ number_format(((int) ($performance->mtd_spend_micro ?? 0)) / 1_000_000, 2) }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ number_format((int) ($performance->mtd_impressions ?? 0)) }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ number_format((int) ($performance->mtd_clicks ?? 0)) }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Start Date') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $campaign->start_date?->format('M d, Y') ?? '—' }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Ad Sets') }}</p>
            <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $campaign->adSets->count() }}</p>
        </div>
    </div>

    {{-- Ad Sets --}}
    @if($campaign->adSets->isNotEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Ad Sets') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Name') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Daily Budget') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($campaign->adSets as $adSet)
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $adSet->name }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($adSet->status === 'ACTIVE') bg-emerald-100 text-emerald-700
                                        @elseif($adSet->status === 'PAUSED') bg-amber-100 text-amber-700
                                        @else bg-zinc-100 text-zinc-600 @endif">
                                        {{ ucfirst(strtolower($adSet->status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ $adSet->daily_budget ? number_format($adSet->daily_budget, 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- UTM Tracking --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('UTM Tracking') }}</h2>
        </div>
        <div class="space-y-4 p-4">
            @if($campaign->utms->isNotEmpty())
                @foreach($campaign->utms as $utm)
                    <div class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                        <p class="break-all text-xs text-zinc-600 dark:text-zinc-300">{{ $utm->buildUrl() }}</p>
                        @if($utm->notes)
                            <p class="mt-1 text-xs text-zinc-400">{{ $utm->notes }}</p>
                        @endif
                    </div>
                @endforeach
            @else
                <p class="text-sm text-zinc-400">{{ __('No UTM metadata saved yet.') }}</p>
            @endif

            <form wire:submit="saveUtm" class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Source') }}</label>
                        <flux:input wire:model="utm_source" placeholder="meta" />
                        @error('utm_source') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Medium') }}</label>
                        <flux:input wire:model="utm_medium" placeholder="paid_social" />
                        @error('utm_medium') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Campaign') }}</label>
                        <flux:input wire:model="utm_campaign" placeholder="spring_campaign" />
                        @error('utm_campaign') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Content') }}</label>
                        <flux:input wire:model="utm_content" placeholder="{{ __('Optional') }}" />
                        @error('utm_content') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Term') }}</label>
                        <flux:input wire:model="utm_term" placeholder="{{ __('Optional') }}" />
                        @error('utm_term') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Landing Page URL') }}</label>
                    <flux:input wire:model="landing_page_url" placeholder="https://laylakitchen.com/menu" />
                    @error('landing_page_url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Notes') }}</label>
                    <textarea wire:model="utm_notes" rows="2" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"></textarea>
                    @error('utm_notes') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('Save UTM') }}</flux:button>
                </div>
            </form>
        </div>
    </div>

    {{-- Linked Assets --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Linked Assets') }}</h2>
        </div>
        <div class="space-y-4 p-4">
            @if($campaign->assetUsages->isEmpty())
                <p class="text-sm text-zinc-400">{{ __('No assets linked to this campaign yet.') }}</p>
            @else
                <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Asset') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Note') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach($campaign->assetUsages as $usage)
                                <tr>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('marketing.assets.show', $usage->asset) }}" class="font-medium text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" wire:navigate>
                                            {{ $usage->asset->name }}
                                        </a>
                                    </td>
                                    <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ ucfirst($usage->asset->type) }}</td>
                                    <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ ucwords(str_replace('_', ' ', $usage->asset->status)) }}</td>
                                    <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $usage->note ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:button wire:click="unlinkAsset({{ $usage->id }})" variant="ghost" size="sm">{{ __('Unlink') }}</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form wire:submit="linkAsset" class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_1fr_auto]">
                <div>
                    <select wire:model="asset_id" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                        <option value="">{{ __('Select asset') }}</option>
                        @foreach($availableAssets as $asset)
                            <option value="{{ $asset->id }}">{{ $asset->name }} ({{ ucfirst($asset->type) }} · {{ ucwords(str_replace('_', ' ', $asset->status)) }})</option>
                        @endforeach
                    </select>
                    @error('asset_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="asset_note" placeholder="{{ __('Usage note') }}" />
                <flux:button type="submit" variant="primary">{{ __('Link Asset') }}</flux:button>
            </form>
        </div>
    </div>

    {{-- Internal Notes --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Internal Notes') }}</h2>
            @if(!$editingNotes)
                <flux:button wire:click="$set('editingNotes', true)" variant="ghost" size="sm">{{ __('Edit') }}</flux:button>
            @endif
        </div>
        <div class="p-4">
            @if($editingNotes)
                <textarea
                    wire:model="internal_notes"
                    rows="4"
                    class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                    placeholder="{{ __('Add internal notes about this campaign…') }}"
                ></textarea>
                <div class="mt-2 flex gap-2">
                    <flux:button wire:click="saveNotes" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                    <flux:button wire:click="$set('editingNotes', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                </div>
            @else
                @if($campaign->internal_notes)
                    <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $campaign->internal_notes }}</p>
                @else
                    <p class="text-sm text-zinc-400">{{ __('No internal notes yet.') }}</p>
                @endif
            @endif
        </div>
    </div>

    {{-- Last synced --}}
    <p class="text-xs text-zinc-400 text-right">
        {{ __('Last synced:') }} {{ $campaign->last_synced_at?->diffForHumans() ?? __('Never') }}
    </p>
</div>
