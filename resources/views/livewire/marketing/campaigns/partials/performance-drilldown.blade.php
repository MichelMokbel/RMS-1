{{-- Reporting filters --}}
<div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('From') }}</label>
            <flux:input type="date" wire:model.live="date_from" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('To') }}</label>
            <flux:input type="date" wire:model.live="date_to" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ __('Ad Set') }}</label>
            <select wire:model.live="selected_ad_set_id" class="min-w-[16rem] rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                <option value="">{{ __('All ad sets') }}</option>
                @foreach($adSetRows as $adSet)
                    <option value="{{ $adSet->id }}">{{ $adSet->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <flux:button type="button" wire:click="setRange(7)" variant="ghost" size="sm">{{ __('7D') }}</flux:button>
            <flux:button type="button" wire:click="setRange(30)" variant="ghost" size="sm">{{ __('30D') }}</flux:button>
            <flux:button type="button" wire:click="setRange(90)" variant="ghost" size="sm">{{ __('90D') }}</flux:button>
            @if($selected_ad_set_id)
                <flux:button type="button" wire:click="clearAdSetFilter" variant="ghost" size="sm">{{ __('Clear Ad Set') }}</flux:button>
            @endif
        </div>
    </div>
</div>

{{-- KPI row --}}
<div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Daily Budget') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ $campaign->daily_budget ? number_format($campaign->daily_budget, 2) : '—' }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Amount Spent') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ number_format(((int) ($performance->spend_micro ?? $performance->mtd_spend_micro ?? 0)) / 1_000_000, 2) }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ number_format((int) ($performance->impressions ?? $performance->mtd_impressions ?? 0)) }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Reach') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ number_format((int) ($performance->reach ?? $performance->mtd_reach ?? 0)) }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ number_format((int) ($performance->clicks ?? $performance->mtd_clicks ?? 0)) }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Conversions') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
            {{ number_format((int) ($performance->mtd_conversions ?? $performance->conversions ?? 0)) }}
        </p>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Ad Sets') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $adSetRows->count() }}</p>
    </div>
</div>

{{-- Charts --}}
<div data-marketing-charts='@json($chartPayload)' class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Spend by Day') }}</h2>
            @if($selectedAdSet)
                <span class="text-xs text-zinc-500">{{ $selectedAdSet->name }}</span>
            @endif
        </div>
        <div data-chart-target="marketing-spend" class="mt-4 min-h-[20rem]"></div>
    </div>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Delivery by Day') }}</h2>
        <div data-chart-target="marketing-metrics" class="mt-4 min-h-[20rem]"></div>
    </div>
</div>

{{-- Ad Sets --}}
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Ad Sets') }}</h2>
        <span class="text-xs text-zinc-500">{{ __('Click a row to filter charts and ads') }}</span>
    </div>
    @if($adSetRows->isEmpty())
        <div class="px-4 py-10 text-center text-sm text-zinc-500">{{ __('No ad sets synced yet.') }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Budget') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Spent') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Reach') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('CTR') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('CPC') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach($adSetRows as $adSet)
                        @php
                            $spend = ((int) $adSet->spend_micro) / 1_000_000;
                            $ctr = ((int) $adSet->impressions) > 0 ? (((int) $adSet->clicks) / ((int) $adSet->impressions)) * 100 : 0;
                            $cpc = ((int) $adSet->clicks) > 0 ? $spend / ((int) $adSet->clicks) : 0;
                        @endphp
                        <tr wire:click="$set('selected_ad_set_id', {{ $adSet->id }})" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/50 @if($selected_ad_set_id === $adSet->id) bg-blue-50 dark:bg-blue-950/30 @endif">
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">{{ $adSet->name }}</td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    @if(in_array($adSet->status, ['ACTIVE', 'ENABLED'], true)) bg-emerald-100 text-emerald-700
                                    @elseif($adSet->status === 'PAUSED') bg-amber-100 text-amber-700
                                    @else bg-zinc-100 text-zinc-600 @endif">
                                    {{ ucfirst(strtolower($adSet->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ $adSet->daily_budget_micro !== null ? number_format(((int) $adSet->daily_budget_micro) / 1_000_000, 2) : '—' }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($spend, 2) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $adSet->impressions) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $adSet->reach) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $adSet->clicks) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($ctr, 2) }}%</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($cpc, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Ads --}}
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Ads') }}</h2>
        @if($selectedAdSet)
            <span class="text-xs text-zinc-500">{{ $selectedAdSet->name }}</span>
        @endif
    </div>
    @if($adRows->isEmpty())
        <div class="px-4 py-10 text-center text-sm text-zinc-500">
            {{ __('No ad-level performance is available yet. Run a fresh sync after this update to populate ad-level metrics.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Ad') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Ad Set') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Asset') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Creative') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Spent') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Impressions') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Reach') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Clicks') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('CTR') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach($adRows as $ad)
                        @php
                            $spend = ((int) $ad->spend_micro) / 1_000_000;
                            $ctr = ((int) $ad->impressions) > 0 ? (((int) $ad->clicks) / ((int) $ad->impressions)) * 100 : 0;
                            $adPlatformData = is_string($ad->platform_data) ? json_decode($ad->platform_data, true) : (array) $ad->platform_data;
                            $adPlatformData = is_array($adPlatformData) ? $adPlatformData : [];
                            $creative = is_array($adPlatformData['creative'] ?? null) ? $adPlatformData['creative'] : [];
                            $previewUrl = $creative['thumbnail_url'] ?? $creative['image_url'] ?? null;
                            $creativeLabel = $creative['title'] ?? $creative['body'] ?? $creative['effective_object_story_id'] ?? null;
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                            <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">{{ $ad->name }}</td>
                            <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $ad->ad_set_name }}</td>
                            <td class="px-4 py-2">
                                @if($previewUrl)
                                    <img src="{{ $previewUrl }}" alt="" class="h-12 w-12 rounded-md object-cover">
                                @elseif($creativeLabel)
                                    <span class="block max-w-[12rem] truncate text-xs text-zinc-500">{{ $creativeLabel }}</span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $ad->creative_type ?: '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    @if(in_array($ad->status, ['ACTIVE', 'ENABLED'], true)) bg-emerald-100 text-emerald-700
                                    @elseif($ad->status === 'PAUSED') bg-amber-100 text-amber-700
                                    @else bg-zinc-100 text-zinc-600 @endif">
                                    {{ ucfirst(strtolower($ad->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($spend, 2) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $ad->impressions) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $ad->reach) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format((int) $ad->clicks) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($ctr, 2) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
