<?php

use App\Services\Marketing\MarketingCampaignQueryService;
use App\Services\Marketing\MarketingSettingsService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public int $days = 30;

    protected $queryString = [
        'days' => ['except' => 30],
    ];

    public function mount(): void
    {
        if (! in_array($this->days, [7, 30, 90], true)) {
            $this->days = 30;
        }
    }

    public function with(
        MarketingCampaignQueryService $campaigns,
        MarketingSettingsService $settings,
    ): array {
        $dailySeries = $campaigns->getDailySpendSeries($this->days);
        $mtdSpend = $campaigns->getMtdSpendByPlatform();

        return [
            'mtdSpend' => $mtdSpend,
            'activeCounts' => $campaigns->getActiveCampaignCounts(),
            'dailySeries' => $dailySeries,
            'topCampaigns' => $campaigns->getTopCampaignsByMtdPerformance(),
            'chartPayload' => [
                'currency' => config('marketing.reporting.currency', 'USD'),
                'digits' => 2,
                'spend' => [
                    'categories' => $dailySeries->map(fn ($row) => \Carbon\Carbon::parse($row->date)->format('M d'))->values(),
                    'series' => [
                        [
                            'name' => __('Spend'),
                            'data' => $dailySeries->map(fn ($row) => round((float) $row->spend, 2))->values(),
                        ],
                    ],
                ],
                'platforms' => [
                    'labels' => ['Meta', 'Google'],
                    'series' => [
                        round((float) ($mtdSpend['meta'] ?? 0), 2),
                        round((float) ($mtdSpend['google'] ?? 0), 2),
                    ],
                ],
            ],
            'metaConfigured' => $settings->isMetaConfigured(),
            'googleConfigured' => $settings->isGoogleConfigured(),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Marketing Dashboard') }}</h1>
        <div class="flex items-center gap-2">
            <select
                wire:model.live="days"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
            >
                <option value="7">{{ __('Last 7 days') }}</option>
                <option value="30">{{ __('Last 30 days') }}</option>
                <option value="90">{{ __('Last 90 days') }}</option>
            </select>
            @if(auth()->user()->hasRole('admin'))
                <flux:button :href="route('marketing.settings')" variant="ghost" icon="cog-6-tooth" wire:navigate>
                    {{ __('Settings') }}
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Platform setup warnings --}}
    @if(!$metaConfigured && !$googleConfigured)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            <p class="text-sm font-medium">{{ __('No platforms configured yet.') }}</p>
            <p class="mt-1 text-sm">
                @if(auth()->user()->hasRole('admin'))
                    {{ __('Configure Meta and Google Ads credentials in') }}
                    <a href="{{ route('marketing.settings') }}" class="underline" wire:navigate>{{ __('Settings') }}</a>
                    {{ __('to start syncing campaign data.') }}
                @else
                    {{ __('Ask an admin to configure platform integrations.') }}
                @endif
            </p>
        </div>
    @endif

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Meta Spend MTD') }}</p>
            <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">
                {{ number_format($mtdSpend['meta'], 2) }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Google Spend MTD') }}</p>
            <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">
                {{ number_format($mtdSpend['google'], 2) }}
            </p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Active Meta Campaigns') }}</p>
            <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">{{ $activeCounts['meta'] }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Active Google Campaigns') }}</p>
            <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">{{ $activeCounts['google'] }}</p>
        </div>
    </div>

    {{-- Charts --}}
    <div data-marketing-charts='@json($chartPayload)' class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Spend by Day') }}</h2>
                <span class="text-xs text-zinc-500">{{ __('Last :days days', ['days' => $days]) }}</span>
            </div>
            <div data-chart-target="marketing-spend" class="mt-4 min-h-[20rem]"></div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Cross-Platform Spend MTD') }}</h2>
            <div data-chart-target="marketing-platforms" class="mt-4 min-h-[20rem]"></div>
        </div>
    </div>

    {{-- Top campaigns --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Top Campaigns MTD') }}</h2>
            <flux:button :href="route('marketing.campaigns.export')" variant="ghost" size="sm" icon="arrow-down-tray">
                {{ __('Export CSV') }}
            </flux:button>
        </div>

        @if($topCampaigns->isEmpty())
            <div class="px-4 py-10 text-center text-sm text-zinc-500">
                {{ __('No campaigns synced yet.') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Campaign') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Platform') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Spend') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($topCampaigns as $campaign)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-2">
                                    <a href="{{ route('marketing.campaigns.show', $campaign) }}" class="font-medium text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400" wire:navigate>
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ ucfirst($campaign->platformAccount->platform) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format(((int) $campaign->mtd_spend_micro) / 1_000_000, 2) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $campaign->mtd_impressions) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $campaign->mtd_clicks) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Spend table (last 30 days) --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Daily Spend — Last 30 Days') }}</h2>
            <flux:button :href="route('marketing.campaigns.index')" variant="ghost" size="sm" wire:navigate>
                {{ __('View Campaigns') }}
            </flux:button>
        </div>

        @if($dailySeries->isEmpty())
            <div class="px-4 py-10 text-center text-sm text-zinc-500">
                {{ __('No spend data yet. Sync will populate this once platform accounts are configured.') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Date') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Spend') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($dailySeries as $row)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ \Carbon\Carbon::parse($row->date)->format('M d, Y') }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($row->spend, 2) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($row->impressions) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($row->clicks) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
