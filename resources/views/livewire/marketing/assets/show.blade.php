<?php

use App\Models\MarketingAdSet;
use App\Models\MarketingAsset;
use App\Models\MarketingAssetUsage;
use App\Models\MarketingBrief;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingAssetService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public MarketingAsset $asset;

    public ?string $readUrl = null;

    public ?string $downloadUrl = null;

    public string $newComment = '';

    public function mount(MarketingAsset $asset, MarketingAssetService $assetService): void
    {
        $this->asset = $asset;
        $this->loadAsset($assetService);
    }

    public function approve(
        MarketingAssetService $assetService,
    ): void {
        abort_unless(auth()->user()->can('marketing.manage'), 403);
        $assetService->updateStatus($this->asset, 'approved', auth()->id());
        $this->loadAsset($assetService);
        session()->flash('status', __('Asset approved.'));
    }

    public function reject(
        MarketingAssetService $assetService,
    ): void {
        abort_unless(auth()->user()->can('marketing.manage'), 403);
        $assetService->updateStatus($this->asset, 'rejected', auth()->id());
        $this->loadAsset($assetService);
        session()->flash('status', __('Asset rejected.'));
    }

    public function archive(MarketingAssetService $assetService): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);
        $assetService->updateStatus($this->asset, 'archived', auth()->id());
        $this->loadAsset($assetService);
        session()->flash('status', __('Asset archived.'));
    }

    public function addComment(
        MarketingActivityLogService $activityLog,
        MarketingAssetService $assetService,
    ): void {
        $this->validate(['newComment' => ['required', 'string', 'max:2000']]);

        \App\Models\MarketingComment::query()->create([
            'commentable_type' => MarketingAsset::class,
            'commentable_id' => $this->asset->id,
            'body' => $this->newComment,
            'created_by' => auth()->id(),
        ]);

        $activityLog->log('asset.comment.added', auth()->id(), $this->asset);
        $this->newComment = '';
        $this->loadAsset($assetService);
    }

    public function with(): array
    {
        return ['asset' => $this->asset];
    }

    private function loadAsset(MarketingAssetService $assetService): void
    {
        $this->asset = $this->asset->refresh()->load([
            'versions',
            'uploadedBy',
            'usages',
            'comments.createdBy',
            'approvals.reviewer',
        ]);

        $this->asset->usages->loadMorph('usageable', [
            MarketingCampaign::class => ['platformAccount'],
            MarketingBrief::class => ['campaign'],
            MarketingAdSet::class => ['campaign'],
        ]);

        try {
            $this->readUrl = $assetService->getPresignedReadUrl($this->asset);
            $this->downloadUrl = $assetService->getPresignedReadUrl($this->asset, true);
        } catch (\Throwable) {
            $this->readUrl = null;
            $this->downloadUrl = null;
        }
    }

    public function usageLabel(MarketingAssetUsage $usage): string
    {
        return match (true) {
            $usage->usageable instanceof MarketingCampaign => __('Campaign'),
            $usage->usageable instanceof MarketingBrief => __('Brief'),
            $usage->usageable instanceof MarketingAdSet => __('Ad set'),
            default => __('Usage'),
        };
    }

    public function usageTitle(MarketingAssetUsage $usage): string
    {
        return match (true) {
            $usage->usageable instanceof MarketingCampaign => $usage->usageable->name,
            $usage->usageable instanceof MarketingBrief => $usage->usageable->title,
            $usage->usageable instanceof MarketingAdSet => $usage->usageable->name,
            default => class_basename($usage->usageable_type).' #'.$usage->usageable_id,
        };
    }

    public function usageSummary(MarketingAssetUsage $usage): ?string
    {
        return match (true) {
            $usage->usageable instanceof MarketingCampaign => $usage->usageable->platformAccount?->account_name,
            $usage->usageable instanceof MarketingBrief => $usage->usageable->campaign?->name,
            $usage->usageable instanceof MarketingAdSet => $usage->usageable->campaign?->name,
            default => $usage->note ?: null,
        };
    }

    public function usageHref(MarketingAssetUsage $usage): ?string
    {
        return match (true) {
            $usage->usageable instanceof MarketingCampaign => route('marketing.campaigns.show', $usage->usageable),
            $usage->usageable instanceof MarketingBrief => route('marketing.briefs.show', $usage->usageable),
            $usage->usageable instanceof MarketingAdSet => $usage->usageable->campaign
                ? route('marketing.campaigns.show', $usage->usageable->campaign)
                : null,
            default => null,
        };
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-2">
            <flux:button :href="route('marketing.assets.index')" variant="ghost" icon="arrow-left" size="sm" wire:navigate />
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ $asset->name }}</h1>
                <p class="text-sm text-zinc-500 capitalize">{{ $asset->type }}</p>
            </div>
        </div>

        {{-- Status badge + actions --}}
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium
                @if($asset->status === 'approved') bg-emerald-100 text-emerald-700
                @elseif($asset->status === 'pending_review') bg-amber-100 text-amber-700
                @elseif($asset->status === 'rejected') bg-red-100 text-red-700
                @else bg-zinc-100 text-zinc-600 @endif">
                {{ ucwords(str_replace('_', ' ', $asset->status)) }}
            </span>

            @if(auth()->user()->can('marketing.manage') && $asset->status === 'pending_review')
                <flux:button wire:click="approve" variant="primary" size="sm">{{ __('Approve') }}</flux:button>
                <flux:button wire:click="reject" variant="danger" size="sm">{{ __('Reject') }}</flux:button>
            @endif
            @if(auth()->user()->can('marketing.manage') && $asset->status !== 'archived')
                <flux:button wire:click="archive" variant="ghost" size="sm">{{ __('Archive') }}</flux:button>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left: preview + metadata --}}
        <div class="space-y-4 lg:col-span-2">
            {{-- Preview --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                @if($readUrl && $asset->isImage())
                    <img
                        src="{{ $readUrl }}"
                        alt="{{ $asset->name }}"
                        class="max-h-[32rem] w-full bg-zinc-50 object-contain dark:bg-zinc-900"
                    >
                @elseif($readUrl && $asset->isVideo())
                    <video controls playsinline preload="metadata" class="w-full bg-black">
                        <source src="{{ $readUrl }}" type="{{ $asset->mime_type ?? 'video/mp4' }}">
                        {{ __('Your browser does not support the video tag.') }}
                    </video>
                @elseif($readUrl && in_array($asset->type, ['document', 'copy'], true))
                    <div class="flex min-h-[18rem] flex-col justify-between gap-6 p-6">
                        <div class="space-y-3">
                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                {{ ucfirst($asset->type) }}
                            </div>
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $asset->name }}</h2>
                            <p class="max-w-prose text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('This asset is best viewed or downloaded using the signed S3 link below.') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ $readUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-700"
                            >
                                {{ __('Open') }}
                            </a>
                            <a
                                href="{{ $downloadUrl ?? $readUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                download
                                class="inline-flex items-center rounded-md bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                            >
                                {{ __('Download') }}
                            </a>
                        </div>
                    </div>
                @else
                    <div class="flex min-h-[18rem] items-center justify-center bg-zinc-50 px-6 py-10 text-center dark:bg-zinc-900">
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $asset->name }}</p>
                            <p class="text-sm text-zinc-500">
                                {{ $readUrl ? __('Preview unavailable for this asset type.') : __('Read URL unavailable.') }}
                            </p>
                            @if($readUrl)
                                <div class="mt-4 flex flex-wrap justify-center gap-2">
                                    <a
                                        href="{{ $readUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-700"
                                    >
                                        {{ __('Open') }}
                                    </a>
                                    <a
                                        href="{{ $downloadUrl ?? $readUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        download
                                        class="inline-flex items-center rounded-md bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                                    >
                                        {{ __('Download') }}
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Metadata --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Details') }}</h2>
                </div>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach([
                        __('Type') => ucfirst($asset->type),
                        __('MIME type') => $asset->mime_type ?? '—',
                        __('File size') => $asset->file_size ? number_format($asset->file_size / 1024, 1) . ' KB' : '—',
                        __('Dimensions') => ($asset->width && $asset->height) ? "{$asset->width} × {$asset->height} px" : '—',
                        __('Duration') => $asset->duration_seconds ? $asset->duration_seconds . 's' : '—',
                        __('Version') => 'v' . $asset->current_version,
                        __('Uploaded by') => $asset->uploadedBy?->username ?? '—',
                        __('Uploaded') => $asset->created_at->format('M d, Y'),
                    ] as $label => $value)
                        <div class="flex px-4 py-2">
                            <dt class="w-36 shrink-0 text-xs font-medium text-zinc-500">{{ $label }}</dt>
                            <dd class="text-sm text-zinc-700 dark:text-zinc-300">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Version history --}}
            @if($asset->versions->isNotEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Version History') }}</h2>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($asset->versions->sortByDesc('version_number') as $version)
                            <li class="flex items-center justify-between px-4 py-2">
                                <div>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">v{{ $version->version_number }}</span>
                                    @if($version->note)
                                        <span class="ml-2 text-xs text-zinc-500">{{ $version->note }}</span>
                                    @endif
                                </div>
                                <span class="text-xs text-zinc-400">{{ $version->created_at->format('M d, Y') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Right: usages + comments --}}
        <div class="space-y-4">
            {{-- Campaign usages --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Used In') }}</h2>
                </div>
                @if($asset->usages->isEmpty())
                    <p class="px-4 py-4 text-sm text-zinc-400">{{ __('Not linked to any campaigns or briefs yet.') }}</p>
                @else
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($asset->usages as $usage)
                            <li class="flex items-start justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                        {{ $this->usageLabel($usage) }}
                                    </div>
                                    <div class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $this->usageTitle($usage) }}
                                    </div>
                                    @if($summary = $this->usageSummary($usage))
                                        <div class="truncate text-xs text-zinc-500">{{ $summary }}</div>
                                    @endif
                                </div>

                                @if($usageHref = $this->usageHref($usage))
                                    <a
                                        href="{{ $usageHref }}"
                                        wire:navigate
                                        class="shrink-0 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {{ __('View') }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Comments --}}
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Comments') }}</h2>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @forelse($asset->comments->sortBy('created_at') as $comment)
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
