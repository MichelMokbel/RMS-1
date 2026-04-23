<?php

use App\Models\MarketingSyncLog;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'syncLogs' => MarketingSyncLog::query()
                ->with('platformAccount')
                ->recent(50)
                ->get(),
        ];
    }

    public function statusTone(?string $status): string
    {
        return match ($status) {
            'completed' => 'green',
            'running' => 'amber',
            'failed' => 'red',
            default => 'zinc',
        };
    }

    public function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $remainingSeconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }

    public function formatContext(?array $context): string
    {
        if (empty($context)) {
            return '—';
        }

        return json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—';
    }

    public function syncTypeLabel(?string $syncType): string
    {
        return $syncType ? Str::headline($syncType) : '—';
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Sync Logs') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Recent marketing sync runs, their timing, and any errors captured during execution.') }}
            </p>
        </div>

        <flux:button :href="route('marketing.dashboard')" variant="ghost" icon="arrow-left" size="sm" wire:navigate>
            {{ __('Dashboard') }}
        </flux:button>
    </div>

    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        @if($syncLogs->isEmpty())
            <div class="px-4 py-16 text-center">
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('No sync logs found') }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    {{ __('Sync activity will appear here after marketing platform jobs run.') }}
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Platform Account') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Sync Type') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Started') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Completed') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Records Synced') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Duration') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Error / Context') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($syncLogs as $log)
                            <tr class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3">
                                    <div class="space-y-1">
                                        <p class="font-medium text-zinc-900 dark:text-white">
                                            {{ $log->platformAccount?->account_name ?? __('Unknown account') }}
                                        </p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $log->platformAccount?->platform ? Str::headline($log->platformAccount->platform) : '—' }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                    {{ $this->syncTypeLabel($log->sync_type) }}
                                </td>
                                <td class="px-4 py-3">
                                    <flux:badge :color="$this->statusTone($log->status)">
                                        {{ Str::headline($log->status ?? 'unknown') }}
                                    </flux:badge>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                    {{ $log->started_at?->format('M d, Y H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                    {{ $log->completed_at?->format('M d, Y H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ number_format((int) $log->records_synced) }}
                                </td>
                                <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                    {{ $this->formatDuration($log->duration_seconds) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="space-y-2">
                                        @if($log->error_message)
                                            <p class="max-w-[24rem] text-sm text-rose-600 dark:text-rose-400">
                                                {{ $log->error_message }}
                                            </p>
                                        @endif

                                        @if(filled($log->context))
                                            <details class="group rounded-md border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-700 dark:bg-zinc-900/40">
                                                <summary class="cursor-pointer list-none text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ __('Context') }}
                                                </summary>
                                                <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap break-words text-[11px] leading-5 text-zinc-600 dark:text-zinc-300">{{ $this->formatContext($log->context) }}</pre>
                                            </details>
                                        @elseif(! $log->error_message)
                                            <span class="text-zinc-400">{{ __('—') }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
