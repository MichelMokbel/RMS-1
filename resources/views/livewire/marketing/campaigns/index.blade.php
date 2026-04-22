<?php

use App\Jobs\Marketing\SyncMarketingCampaignsJob;
use App\Jobs\Marketing\SyncMarketingSpendJob;
use App\Models\MarketingPlatformAccount;
use App\Services\Marketing\MarketingCampaignQueryService;
use App\Services\Marketing\MarketingSettingsService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $platform = '';

    public string $status = '';

    public string $sort = 'last_synced';

    public string $direction = 'desc';

    public string $date_from = '';

    public string $date_to = '';

    protected $paginationTheme = 'tailwind';

    protected $queryString = [
        'search' => ['except' => ''],
        'platform' => ['except' => ''],
        'status' => ['except' => ''],
        'sort' => ['except' => 'last_synced'],
        'direction' => ['except' => 'desc'],
        'date_from' => ['except' => ''],
        'date_to' => ['except' => ''],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPlatform(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        $allowed = ['name', 'platform', 'status', 'daily_budget', 'mtd_spend', 'impressions', 'clicks', 'conversions', 'last_synced'];

        if (! in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = in_array($column, ['name', 'platform', 'status'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function triggerSync(MarketingSettingsService $settingsService): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);

        $lookbackDays = (int) config('marketing.sync.spend_lookback_days', 3);
        $accounts = MarketingPlatformAccount::query()->active()->get();
        $dispatched = 0;

        foreach ($accounts as $account) {
            if (! $settingsService->isSyncEnabledFor($account->platform)) {
                continue;
            }

            SyncMarketingCampaignsJob::dispatch($account->id);

            for ($i = 1; $i <= $lookbackDays; $i++) {
                SyncMarketingSpendJob::dispatch(
                    $account->id,
                    now()->subDays($i)->toDateString(),
                );
            }

            $dispatched++;
        }

        session()->flash(
            'status',
            $dispatched > 0
                ? __('Sync jobs dispatched for :count account(s). Check Sync Logs for progress.', ['count' => $dispatched])
                : __('No active, enabled platform accounts found. Add a Meta ad account in Settings and enable sync.'),
        );
    }

    public function with(MarketingCampaignQueryService $query): array
    {
        return [
            'campaigns' => $query->paginateWithPerformance(
                search: $this->search ?: null,
                platform: $this->platform ?: null,
                status: $this->status ?: null,
                perPage: 25,
                sort: $this->sort,
                direction: $this->direction,
            ),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Campaigns') }}</h1>
        <div class="flex items-center gap-2">
            <flux:button
                :href="route('marketing.campaigns.export', array_filter([
                    'search' => $search,
                    'platform' => $platform,
                    'status' => $status,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                ], fn ($value) => filled($value)))"
                variant="ghost"
                icon="arrow-down-tray"
            >
                {{ __('Export CSV') }}
            </flux:button>
            @if(auth()->user()->can('marketing.manage'))
            <flux:button
                wire:click="triggerSync"
                variant="ghost"
                icon="arrow-path"
            >
                {{ __('Sync Now') }}
            </flux:button>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search campaigns…') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div>
                <select
                    wire:model.live="platform"
                    class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                >
                    <option value="">{{ __('All Platforms') }}</option>
                    <option value="meta">Meta</option>
                    <option value="google">Google</option>
                </select>
            </div>
            <div>
                <select
                    wire:model.live="status"
                    class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="ACTIVE">{{ __('Active') }}</option>
                    <option value="PAUSED">{{ __('Paused') }}</option>
                    <option value="ARCHIVED">{{ __('Archived') }}</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input
                    type="date"
                    wire:model.live="date_from"
                    aria-label="{{ __('Export from date') }}"
                />
                <flux:input
                    type="date"
                    wire:model.live="date_to"
                    aria-label="{{ __('Export to date') }}"
                />
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        @if($campaigns->isEmpty())
            <div class="px-4 py-16 text-center">
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('No campaigns found') }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    @if($search || $platform || $status)
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __('Campaigns will appear here once platform accounts are connected and synced.') }}
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('name')" class="inline-flex items-center gap-1">
                                    {{ __('Campaign') }}
                                    @if($sort === 'name')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('platform')" class="inline-flex items-center gap-1">
                                    {{ __('Platform') }}
                                    @if($sort === 'platform')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1">
                                    {{ __('Status') }}
                                    @if($sort === 'status')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Objective') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('daily_budget')" class="inline-flex items-center gap-1">
                                    {{ __('Daily Budget') }}
                                    @if($sort === 'daily_budget')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('mtd_spend')" class="inline-flex items-center gap-1">
                                    {{ __('MTD Spend') }}
                                    @if($sort === 'mtd_spend')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('impressions')" class="inline-flex items-center gap-1">
                                    {{ __('Impressions') }}
                                    @if($sort === 'impressions')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('clicks')" class="inline-flex items-center gap-1">
                                    {{ __('Clicks') }}
                                    @if($sort === 'clicks')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('conversions')" class="inline-flex items-center gap-1">
                                    {{ __('Conversions') }}
                                    @if($sort === 'conversions')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">
                                <button type="button" wire:click="sortBy('last_synced')" class="inline-flex items-center gap-1">
                                    {{ __('Last Synced') }}
                                    @if($sort === 'last_synced')<span>{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif
                                </button>
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($campaigns as $campaign)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3">
                                    <a
                                        href="{{ route('marketing.campaigns.show', $campaign) }}"
                                        class="font-medium text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                        wire:navigate
                                    >
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($campaign->platformAccount->platform === 'meta') bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400
                                        @else bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 @endif">
                                        {{ ucfirst($campaign->platformAccount->platform) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($campaign->status === 'ACTIVE') bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400
                                        @elseif($campaign->status === 'PAUSED') bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400
                                        @else bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400 @endif">
                                        {{ ucfirst(strtolower($campaign->status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $campaign->objective ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ $campaign->daily_budget ? number_format($campaign->daily_budget, 2) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ number_format(((int) $campaign->mtd_spend_micro) / 1_000_000, 2) }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ number_format((int) $campaign->mtd_impressions) }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ number_format((int) $campaign->mtd_clicks) }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ number_format((int) $campaign->mtd_conversions) }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-500 text-xs">
                                    {{ $campaign->last_synced_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button
                                        :href="route('marketing.campaigns.show', $campaign)"
                                        variant="ghost"
                                        size="sm"
                                        wire:navigate
                                    >
                                        {{ __('View') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $campaigns->links('pagination::tailwind') }}
            </div>
        @endif
    </div>
</div>
