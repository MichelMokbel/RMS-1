<section class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">{{ __('Category Definitions') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Declared and invoice categories are matched now; proposed categories are created only inside the final accounting transaction.') }}</p>
        </div>
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            {{ __(':create to create · :matched matched · :review need review', [
                'create' => $categoryProposals->where('status', 'proposed')->count(),
                'matched' => $categoryProposals->where('status', 'matched')->count(),
                'review' => $categoryProposals->whereIn('status', ['inactive', 'ambiguous'])->count(),
            ]) }}
        </p>
    </div>
    @if($categoryProposals->isEmpty())
        <p class="mt-3 text-sm text-neutral-500">{{ __('No category definitions were supplied.') }}</p>
    @else
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach($categoryProposals as $proposal)
                <span @class([
                    'inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium',
                    'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200' => $proposal->status === 'proposed',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $proposal->status === 'matched',
                    'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100' => in_array($proposal->status, ['inactive', 'ambiguous'], true),
                ])>
                    @if(filled($proposal->source_code))<span>{{ $proposal->source_code }}</span><span aria-hidden="true">·</span>@endif
                    <span>{{ $proposal->source_name }}</span>
                    <span aria-hidden="true">—</span>
                    <span>{{ match($proposal->status) {
                        'proposed' => __('Will create'),
                        'matched' => __('Existing'),
                        'inactive' => __('Inactive — review'),
                        'ambiguous' => __('Ambiguous — review'),
                        default => Str::headline($proposal->status),
                    } }}</span>
                </span>
            @endforeach
        </div>
    @endif
</section>
