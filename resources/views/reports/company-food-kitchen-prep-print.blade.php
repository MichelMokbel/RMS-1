<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Food Kitchen Prep</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 18px; }
        h2 { margin: 0 0 4px; font-size: 16px; }
        h3 { margin: 10px 0 6px; font-size: 14px; }
        h4 { margin: 8px 0 4px; font-size: 12px; color: #374151; }
        .meta { font-size: 12px; color: #4b5563; margin: 0 0 10px; }
        .section { margin-top: 12px; page-break-inside: auto; break-inside: auto; }
        h2, h3, h4 { page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 6px; font-size: 11px; text-align: left; }
        th { background: #f9fafb; font-weight: 700; }
        .empty { color: #6b7280; font-size: 12px; margin-top: 6px; }
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
            <h2>{{ \Carbon\Carbon::parse($dateStr)->format('l, M j, Y') }}</h2>
            @foreach ($lists as $listData)
                <h3>{{ $listData['listName'] }}</h3>
                @foreach ($listData['sections'] as $section)
                    <h4>{{ $section['name'] }}</h4>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 120px;">Category</th>
                                <th>Option</th>
                                <th style="width: 80px;">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $hasRows = false; @endphp
                            @foreach ($categoryLabels as $key => $label)
                                @if (in_array($key, $listData['categories'], true))
                                    @php $rows = $section['counts'][$key] ?? []; @endphp
                                    @forelse ($rows as $row)
                                        @php $hasRows = true; @endphp
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td>{{ $row['name'] }}</td>
                                            <td>{{ $row['count'] }}</td>
                                        </tr>
                                    @empty
                                    @endforelse
                                @endif
                            @endforeach
                            @if (! $hasRows)
                                <tr>
                                    <td colspan="3">No prep items for this location.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                @endforeach
            @endforeach
        </div>
    @empty
        <p class="empty">No orders found for kitchen prep.</p>
    @endforelse

    @include('reports.print-footer')
</body>
</html>
