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
        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $company }} · {{ $filters['date_from'] }} {{ __('to') }} {{ $filters['date_to'] }} · {{ __('Totals cover all matching rows, across every page.') }}</p>
        @if (!empty($documentNumber))
            <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Document number') }}: {{ $documentNumber }}</p>
        @endif
        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700" tabindex="0" role="region" aria-label="{{ $title }}">
            <table class="w-full whitespace-nowrap text-left text-sm text-neutral-800 dark:text-neutral-100">
                <thead class="bg-neutral-100 dark:bg-neutral-800">
                    <tr>@foreach ($headers as $header)<th scope="col" class="px-4 py-3 font-semibold">{{ $header }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($paginator as $row)
                        <tr>@foreach ($row as $cell)<td class="px-4 py-3">{{ $cell }}</td>@endforeach</tr>
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
