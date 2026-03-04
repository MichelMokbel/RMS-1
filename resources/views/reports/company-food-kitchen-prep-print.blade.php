<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Food Kitchen Prep</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 10px; font-size: 11px; }
        .meta { font-size: 11px; color: #4b5563; margin: 0 0 8px; line-height: 1.35; }
        .section {
            margin-top: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 8px;
        }
        .section-title { margin: 0; font-size: 20px; font-weight: 700; line-height: 1.2; }
        .section-subtitle { margin: 2px 0 8px; font-size: 13px; font-weight: 600; color: #374151; }
        .location-title { margin: 8px 0 4px; font-size: 12px; font-weight: 700; color: #1f2937; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        th, td {
            border: 1px solid #d1d5db;
            padding: 4px 6px;
            font-size: 11px;
            text-align: left;
            vertical-align: top;
            word-break: break-word;
        }
        th { background: #f3f4f6; font-weight: 700; }
        td.count { width: 64px; text-align: right; font-weight: 700; }
        .empty { color: #6b7280; font-size: 11px; margin-top: 6px; }

        @include('reports.print-header-styles')
        @page { margin: 4mm 10mm 0 10mm; }
        @media print {
            body { margin: 0 !important; padding: 0 0 14mm 0 !important; }
        }
        .report-header { margin-top: 0; margin-bottom: 5px; }
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
            'main' => 'Mains',
            'appetizer' => 'Appetizers',
            'salad' => 'Salads',
            'soup' => 'Soups',
            'sweet' => 'Sweets',
        ];
    @endphp

    @forelse ($kitchenPrep as $dateStr => $lists)
        @foreach ($lists as $listData)
            @foreach ($listData['sections'] as $section)
                <div class="section">
                    <p class="section-title">{{ \Carbon\Carbon::parse($dateStr)->format('l, M j, Y') }}</p>
                    <p class="section-subtitle">{{ $listData['listName'] }}</p>
                    <p class="location-title">{{ $section['name'] }}</p>

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 120px;">Category</th>
                                <th>Option</th>
                                <th style="width: 70px;">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $hasRows = false; @endphp
                            @foreach ($categoryLabels as $key => $label)
                                @if (in_array($key, $listData['categories'], true))
                                    @php
                                        $rows = collect($section['counts'][$key] ?? [])
                                            ->sortByDesc('count')
                                            ->values()
                                            ->all();
                                    @endphp
                                    @foreach ($rows as $row)
                                        @php $hasRows = true; @endphp
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td>{{ $row['name'] }}</td>
                                            <td class="count">{{ $row['count'] }}</td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                            @if (! $hasRows)
                                <tr>
                                    <td colspan="3">No prep items for this location.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endforeach
    @empty
        <p class="empty">No orders found for kitchen prep.</p>
    @endforelse

    @include('reports.print-footer')
</body>
</html>
