@php
    $formattedFilters = \App\Support\Reports\PrintFilterFormatter::format($filters ?? []);
@endphp

@if (! empty($formattedFilters))
    | Filters: {{ implode(' | ', $formattedFilters) }}
@endif
