<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Food Kitchen Prep</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 18px; font-size: 14px; }
        .meta { font-size: 12px; color: #4b5563; margin: 0 0 10px; }
        .section { margin-top: 12px; page-break-inside: auto; break-inside: auto; }
        .day-title { margin: 0 0 6px; font-size: 38px; font-weight: 700; line-height: 1.15; page-break-after: avoid; }
        .list-title { margin: 8px 0 6px; font-size: 24px; font-weight: 700; page-break-after: avoid; }
        .location-title { margin: 4px 0 8px; font-size: 16px; font-weight: 700; color: #374151; page-break-after: avoid; }
        .category-block { margin: 0 0 16px; page-break-inside: avoid; break-inside: avoid; }
        .category-name { font-size: 46px; font-weight: 700; line-height: 1.1; margin: 0 0 4px; }
        .dish-line { font-size: 34px; line-height: 1.2; margin: 0 0 2px 10px; }
        .dish-name { display: inline; }
        .dish-count { display: inline; font-weight: 700; }
        .empty { color: #6b7280; font-size: 14px; margin-top: 8px; }
        @include('reports.print-header-styles')

        /* Keep this report tight at the top of each page. */
        @page { margin: 3mm 10mm 0 10mm; }
        @media print {
            body { margin: 0 !important; padding: 0 0 14mm 0 !important; }
        }
        .report-header { margin-top: 0; margin-bottom: 6px; }
        .report-header-bottom {
            margin-top: 3px;
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .report-header-bottom .left,
        .report-header-bottom .right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .report-header-bottom .right { text-align: right; }
    </style>
</head>
<body>
    @include('reports.print-header', [
        'reportTitle' => 'Company Food Kitchen Prep',
        'generatedAt' => $generatedAt,
        'startAt' => $project->start_date,
        'endAt' => $project->end_date,
    ])

    <p class="meta">
        Project: {{ $project->name }} | Company: {{ $project->company_name }} | Date range:
        {{ $project->start_date->format('Y-m-d') }} to {{ $project->end_date->format('Y-m-d') }}
        | Filter date: {{ $filters['order_date'] ?? 'All' }}
        | Filter list: {{ $filters['employee_list_id'] ?? 'All' }}
        | Filter location: {{ $filters['location_option_id'] ?? 'All' }}
    </p>

    @php
        $categoryLabels = [
            'salad' => 'Salads',
            'appetizer' => 'Appetizers',
            'main' => 'Mains',
            'sweet' => 'Sweets',
            'soup' => 'Soups',
        ];
    @endphp

    @forelse ($kitchenPrep as $dateStr => $lists)
        <div class="section">
            <p class="day-title">{{ \Carbon\Carbon::parse($dateStr)->format('l, M j, Y') }}</p>
            @foreach ($lists as $listData)
                <p class="list-title">{{ $listData['listName'] }}</p>
                @foreach ($listData['sections'] as $section)
                    <p class="location-title">{{ $section['name'] }}</p>
                    @php $hasRows = false; @endphp
                    @foreach ($categoryLabels as $key => $label)
                        @if (in_array($key, $listData['categories'], true))
                            @php
                                $rows = collect($section['counts'][$key] ?? [])
                                    ->sortByDesc('count')
                                    ->values()
                                    ->all();
                            @endphp
                            @if (! empty($rows))
                                @php $hasRows = true; @endphp
                                <div class="category-block">
                                    <p class="category-name">{{ $label }}</p>
                                    @foreach ($rows as $row)
                                        <p class="dish-line">
                                            <span class="dish-name">{{ $row['name'] }}:</span>
                                            <span class="dish-count">{{ $row['count'] }}</span>
                                        </p>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    @endforeach
                    @if (! $hasRows)
                        <p class="empty">No prep items for this location.</p>
                    @endif
                @endforeach
            @endforeach
        </div>
    @empty
        <p class="empty">No orders found for kitchen prep.</p>
    @endforelse

    @include('reports.print-footer')
</body>
</html>
