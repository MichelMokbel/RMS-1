<?php

use App\Models\MarketingAsset;
use App\Models\MarketingAssetUsage;
use App\Models\MarketingBrief;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingBriefService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public MarketingBrief $brief;

    public string $newComment = '';

    public ?int $asset_id = null;

    public string $asset_note = '';

    public function mount(MarketingBrief $brief): void
    {
        $this->brief = $brief->load(['campaign.platformAccount', 'createdBy', 'updatedBy', 'comments.createdBy', 'assetUsages.asset']);
    }

    public function submitForReview(MarketingBriefService $briefService): void
    {
        $briefService->submitForReview($this->brief, auth()->id());
        $this->brief->refresh();
        session()->flash('status', __('Brief submitted for review.'));
    }

    public function approve(MarketingBriefService $briefService): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);
        $briefService->approve($this->brief, auth()->id());
        $this->brief->refresh();
        session()->flash('status', __('Brief approved.'));
    }

    public function reject(MarketingBriefService $briefService): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);
        $briefService->reject($this->brief, auth()->id());
        $this->brief->refresh();
        session()->flash('status', __('Brief rejected.'));
    }

    public function addComment(MarketingActivityLogService $activityLog): void
    {
        $this->validate(['newComment' => ['required', 'string', 'max:2000']]);

        \App\Models\MarketingComment::query()->create([
            'commentable_type' => MarketingBrief::class,
            'commentable_id' => $this->brief->id,
            'body' => $this->newComment,
            'created_by' => auth()->id(),
        ]);

        $activityLog->log('brief.comment.added', auth()->id(), $this->brief);
        $this->newComment = '';
        $this->brief->load('comments.createdBy');
    }

    public function linkAsset(MarketingActivityLogService $activityLog): void
    {
        $this->validate([
            'asset_id' => ['required', 'integer', 'exists:marketing_assets,id'],
            'asset_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $usage = MarketingAssetUsage::query()->firstOrCreate([
            'asset_id' => $this->asset_id,
            'usageable_type' => MarketingBrief::class,
            'usageable_id' => $this->brief->id,
        ], [
            'note' => $this->asset_note ?: null,
            'created_by' => auth()->id(),
        ]);

        if (! $usage->wasRecentlyCreated && $this->asset_note !== '') {
            $usage->update(['note' => $this->asset_note]);
        }

        $activityLog->log('brief.asset.linked', auth()->id(), $this->brief, [
            'asset_id' => $this->asset_id,
        ]);

        $this->asset_id = null;
        $this->asset_note = '';
        $this->brief->refresh()->load(['campaign.platformAccount', 'createdBy', 'updatedBy', 'comments.createdBy', 'assetUsages.asset']);
        session()->flash('status', __('Asset linked to brief.'));
    }

    public function unlinkAsset(int $usageId, MarketingActivityLogService $activityLog): void
    {
        $usage = MarketingAssetUsage::query()
            ->where('usageable_type', MarketingBrief::class)
            ->where('usageable_id', $this->brief->id)
            ->findOrFail($usageId);

        $assetId = $usage->asset_id;
        $usage->delete();

        $activityLog->log('brief.asset.unlinked', auth()->id(), $this->brief, [
            'asset_id' => $assetId,
        ]);

        $this->brief->refresh()->load(['campaign.platformAccount', 'createdBy', 'updatedBy', 'comments.createdBy', 'assetUsages.asset']);
        session()->flash('status', __('Asset unlinked from brief.'));
    }

    public function with(): array
    {
        $linkedAssetIds = $this->brief->assetUsages->pluck('asset_id')->all();

        return [
            'brief' => $this->brief,
            'availableAssets' => MarketingAsset::query()
                ->active()
                ->whereNotIn('id', $linkedAssetIds)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'status']),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-2">
            <flux:button :href="route('marketing.briefs.index')" variant="ghost" icon="arrow-left" size="sm" wire:navigate />
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ $brief->title }}</h1>
                <p class="mt-0.5 text-sm text-zinc-500">
                    {{ __('Created by') }} {{ $brief->createdBy?->username ?? '—' }}
                    @if($brief->campaign)
                        · <a href="{{ route('marketing.campaigns.show', $brief->campaign) }}" class="hover:underline" wire:navigate>
                            {{ $brief->campaign->name }}
                        </a>
                    @endif
                    @if($brief->due_date)
                        · {{ __('Due') }} {{ $brief->due_date->format('M d, Y') }}
                    @endif
                </p>
            </div>
        </div>

        {{-- Workflow actions --}}
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium
                @if($brief->status === 'approved') bg-emerald-100 text-emerald-700
                @elseif($brief->status === 'pending_review') bg-amber-100 text-amber-700
                @elseif($brief->status === 'rejected') bg-red-100 text-red-700
                @else bg-zinc-100 text-zinc-600 @endif">
                {{ ucwords(str_replace('_', ' ', $brief->status)) }}
            </span>

            @if($brief->isDraft())
                <flux:button wire:click="submitForReview" variant="primary" size="sm">{{ __('Submit for Review') }}</flux:button>
            @elseif($brief->isPendingReview() && auth()->user()->can('marketing.manage'))
                <flux:button wire:click="approve" variant="primary" size="sm">{{ __('Approve') }}</flux:button>
                <flux:button wire:click="reject" variant="danger" size="sm">{{ __('Reject') }}</flux:button>
            @endif

            @if($brief->isDraft())
                <flux:button :href="route('marketing.briefs.create') . '?edit=' . $brief->id" variant="ghost" size="sm">
                    {{ __('Edit') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Main content --}}
        <div class="space-y-4 lg:col-span-2">
            @foreach([
                __('Description') => $brief->description,
                __('Objectives') => $brief->objectives,
                __('Target Audience') => $brief->target_audience,
                __('Budget Notes') => $brief->budget_notes,
            ] as $label => $value)
                @if($value)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</h2>
                        </div>
                        <div class="px-4 py-4">
                            <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $value }}</p>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Linked assets --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Linked Assets') }}</h2>
                </div>
                @if($brief->assetUsages->isEmpty())
                    <p class="px-4 py-4 text-sm text-zinc-400">{{ __('No assets linked to this brief yet.') }}</p>
                @else
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($brief->assetUsages as $usage)
                            <li class="flex items-center justify-between px-4 py-2">
                                <div>
                                    <a href="{{ route('marketing.assets.show', $usage->asset) }}" class="text-sm font-medium text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" wire:navigate>
                                        {{ $usage->asset->name }}
                                    </a>
                                    <p class="text-xs text-zinc-500">{{ ucfirst($usage->asset->type) }} · {{ ucwords(str_replace('_', ' ', $usage->asset->status)) }}@if($usage->note) · {{ $usage->note }}@endif</p>
                                </div>
                                <flux:button wire:click="unlinkAsset({{ $usage->id }})" variant="ghost" size="sm">{{ __('Unlink') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="linkAsset" class="grid grid-cols-1 gap-3 border-t border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-[1fr_1fr_auto]">
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

        {{-- Sidebar: comments --}}
        <div class="space-y-4">
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Comments') }}</h2>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @forelse($brief->comments->sortBy('created_at') as $comment)
                        <div class="px-4 py-3">
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ $comment->createdBy?->username ?? __('System') }}
                                </span>
                                <span class="text-xs text-zinc-400">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $comment->body }}</p>
                        </div>
                    @empty
                        <p class="px-4 py-4 text-sm text-zinc-400">{{ __('No comments yet.') }}</p>
                    @endforelse
                </div>
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <textarea
                        wire:model="newComment"
                        rows="2"
                        placeholder="{{ __('Add a comment…') }}"
                        class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                    ></textarea>
                    @error('newComment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    <div class="mt-2 flex justify-end">
                        <flux:button wire:click="addComment" size="sm" variant="primary">{{ __('Comment') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
