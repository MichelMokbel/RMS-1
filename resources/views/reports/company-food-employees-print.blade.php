@php
    $isBrowserPrint = (bool) ($browserPrint ?? false);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Food Employee Orders</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 20px; }
        .meta { font-size: 12px; color: #4b5563; margin: 0 0 12px; }
        .toolbar { margin: 0 0 10px; }
        .btn {
            display: inline-block;
            padding: 7px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            color: #111827;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
        }
        .btn:hover { background: #f3f4f6; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 11px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; font-weight: 700; }
        @include('reports.print-header-styles')
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    @if ($isBrowserPrint)
        <div class="toolbar no-print">
            <button type="button" class="btn" onclick="window.print()">Print</button>
        </div>
    @endif

    @include('reports.print-header', [
        'reportTitle' => 'Company Food Employee Orders',
        'generatedAt' => $generatedAt,
        'startAt' => $project->start_date,
        'endAt' => $project->end_date,
    ])

    <p class="meta">
        Project: {{ $project->name }} | Company: {{ $project->company_name }} |
        Filter date: {{ $filters['order_date'] ?: 'All' }} |
        Filter list: {{ $filters['employee_list_id'] ?: 'All' }} |
        Filter location: {{ $filters['location_option_id'] ?: 'All' }}
    </p>

    <table>
        <thead>
            <tr>
                <th style="width: 90px;">Date</th>
                <th style="width: 160px;">Employee</th>
                <th>Salad</th>
                <th>Appetizer 1</th>
                <th>Appetizer 2</th>
                <th>Main</th>
                <th>Sweet</th>
                <th>Soup</th>
                <th>Location</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ $order->order_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $order->employee_name }}</td>
                    <td>{{ $order->saladOption?->name ?? '—' }}</td>
                    <td>{{ $order->appetizerOption1?->name ?? '—' }}</td>
                    <td>{{ $order->appetizerOption2?->name ?? '—' }}</td>
                    <td>{{ $order->mainOption?->name ?? '—' }}</td>
                    <td>{{ $order->sweetOption?->name ?? '—' }}</td>
                    <td>{{ $order->soupOption?->name ?? '—' }}</td>
                    <td>{{ $order->locationOption?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">No employee orders match the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @include('reports.print-footer')

    @if ($isBrowserPrint)
        <script>
            window.addEventListener('load', function () {
                window.setTimeout(function () {
                    window.print();
                }, 150);
            });
        </script>
    @endif
</body>
</html>
