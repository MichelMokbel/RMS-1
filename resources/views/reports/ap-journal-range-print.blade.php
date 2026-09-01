<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('AP Journal Reports') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #171717; }
        .report { page-break-after: always; }
        .report:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 4px; border: 1px solid #d4d4d4; text-align: left; overflow-wrap: break-word; }
        th, tfoot { background: #f5f5f5; font-weight: bold; }
        tbody tr:nth-child(even) { background: #fafafa; }
        th:last-child, td:last-child { text-align: right; white-space: nowrap; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        h1 { margin-bottom: 4px; }
        .meta { margin: 2px 0; }
    </style>
</head>
<body>
    @foreach ($reports as $report)
        @php($snapshot = $report['snapshot'])
        <section class="report">
            <h1>{{ $snapshot['title'] }}</h1>
            <p class="meta">{{ $company }} · {{ $report['date'] }}</p>
            <p class="meta"><strong>{{ __('Document number') }}: {{ $report['document_number'] }}</strong> · {{ __('Revision') }}: {{ $report['revision'] }}</p>
            <p class="meta">{{ $snapshot['description'] }}</p>
            <p class="meta">{{ __('Report generated') }}: {{ $report['generated_at']->format('Y-m-d H:i') }} · {{ __('Combined PDF generated') }}: {{ $generatedAt->format('Y-m-d H:i') }} ({{ config('app.timezone') }})</p>
            <table>
                <colgroup>
                    @foreach ([12, 13, 14, 14, 18, 18, 11] as $width)
                        <col style="width: {{ $width }}%">
                    @endforeach
                </colgroup>
                <thead>
                    <tr>@foreach ($snapshot['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @forelse ($snapshot['rows'] as $row)
                        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ count($snapshot['headers']) }}">{{ __('No matching records.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    @foreach ($snapshot['totals'] as $row)
                        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                    @endforeach
                </tfoot>
            </table>
        </section>
    @endforeach
</body>
</html>
