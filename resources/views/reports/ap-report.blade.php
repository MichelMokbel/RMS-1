<x-layouts.app :title="$title">
    <div class="app-page space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $title }}</h1>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ $description }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('reports.index', ['category' => $reportKey === 'ap-journal' ? 'accounting' : 'expenses'])" variant="ghost" class="min-h-11">{{ __('Back to Reports') }}</flux:button>
                @foreach (['print' => __('Print'), 'csv' => __('Export CSV'), 'pdf' => __('Export PDF')] as $format => $label)
                    <flux:button :href="route('reports.'.$reportKey.'.'.$format, $filters)" variant="ghost" class="min-h-11">{{ $label }}</flux:button>
                @endforeach
            </div>
        </div>
        <form method="get" action="{{ route('reports.'.$reportKey) }}" class="grid gap-4 rounded-lg border border-neutral-200 p-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5 dark:border-neutral-700">
            <flux:select name="company_id" :label="__('Company')" class="min-h-11">
                @foreach ($companies as $option)
                    <option value="{{ $option->id }}" @selected($filters['company_id'] == $option->id)>{{ $option->name }}</option>
                @endforeach
            </flux:select>
            <flux:select name="branch_id" :label="__('Branch')" class="min-h-11">
                <option value="">{{ __('All permitted branches') }}</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </flux:select>
            <flux:input type="date" name="date_from" :value="$filters['date_from']" :label="__('From date')" class="min-h-11" />
            <flux:input type="date" name="date_to" :value="$filters['date_to']" :label="__('To date')" class="min-h-11" />
            <flux:button type="submit" variant="primary" class="min-h-11 self-end">{{ __('Apply filters') }}</flux:button>
        </form>
        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200" role="status">
                {{ session('status') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ session('error') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($reportKey === 'ap-journal' && auth()->user()->isAdmin())
            <div class="flex flex-col gap-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-700 dark:bg-neutral-900">
                <div>
                    <h2 class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Generate and email daily reports') }}</h2>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('Creates one numbered company wide report for each selected day, in date order, then emails all daily reports as one PDF. Maximum range: 366 days.') }}
                    </p>
                    @if ($apRangeEmailSettings?->ap_report_email)
                        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ __('Configured recipient') }}: {{ $apRangeEmailSettings->ap_report_email }}</p>
                    @endif
                </div>
                @if (! $apRangeEmailSettings?->ap_report_enabled || ! $apRangeEmailSettings?->ap_report_email || ! $apRangeEmailSettings?->ap_report_company_id)
                    <flux:button :href="route('finance.settings')" variant="ghost" class="min-h-11 shrink-0">{{ __('Configure AP report email') }}</flux:button>
                @elseif ((int) $apRangeEmailSettings->ap_report_company_id !== (int) $filters['company_id'])
                    <p class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Select the company configured for AP report email delivery.') }}</p>
                @elseif (! empty($filters['branch_id']))
                    <p class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Select All permitted branches to generate the official company wide reports.') }}</p>
                @else
                    <form method="post" action="{{ route('reports.ap-journal.generate-email') }}" class="shrink-0" x-data="{ submitting: false }" x-on:submit="submitting = true">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $filters['company_id'] }}">
                        <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
                        <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
                        @if (session('ap_range_retry_available'))
                            <input type="hidden" name="retry_failed" value="1">
                            <flux:button type="submit" variant="danger" class="min-h-11" x-bind:disabled="submitting" onclick="return confirm(@js(__('Retry only after checking email history and the mail provider for a delivered message. Continue?')))">
                                {{ __('Retry failed email') }}
                            </flux:button>
                        @else
                            <flux:button type="submit" variant="primary" class="min-h-11" x-bind:disabled="submitting">
                                {{ __('Generate daily reports and email one PDF') }}
                            </flux:button>
                        @endif
                    </form>
                @endif
            </div>
        @endif
        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $company }} · {{ $filters['date_from'] }} {{ __('to') }} {{ $filters['date_to'] }} · {{ __('Totals cover all matching rows, across every page.') }}</p>
        @if (!empty($documentNumber))
            <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Document number') }}: {{ $documentNumber }}</p>
        @endif
        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700" tabindex="0" role="region" aria-label="{{ $title }}">
            <table @class([
                'w-full text-left text-sm text-neutral-800 dark:text-neutral-100',
                'min-w-[1000px]' => $reportKey === 'ap-journal',
                'whitespace-nowrap' => $reportKey !== 'ap-journal',
            ])>
                <thead class="bg-neutral-100 dark:bg-neutral-800">
                    <tr>@foreach ($headers as $header)<th scope="col" class="px-4 py-3 font-semibold">{{ $header }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($paginator as $row)
                        <tr>@foreach ($row as $index => $cell)<td @class(['px-4 py-3', 'max-w-64 whitespace-normal' => $reportKey === 'ap-journal' && in_array($index, [3, 4, 5], true), 'text-right whitespace-nowrap' => $reportKey === 'ap-journal' && $index === count($row) - 1])>{{ $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center" colspan="{{ count($headers) }}">{{ __('No matching records.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-neutral-100 font-semibold dark:bg-neutral-800">
                    @foreach ($totals as $row)<tr>@foreach ($row as $cell)<td class="px-4 py-3">{{ $cell }}</td>@endforeach</tr>@endforeach
                </tfoot>
            </table>
        </div>
        {{ $paginator->links() }}
    </div>
</x-layouts.app>
