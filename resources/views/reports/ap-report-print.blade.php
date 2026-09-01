<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #171717; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 5px; border: 1px solid #d4d4d4; text-align: left; overflow-wrap: break-word; }
        th, tfoot { background: #f5f5f5; font-weight: bold; }
        tbody tr:nth-child(even) { background: #fafafa; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .ap-journal th:last-child, .ap-journal td:last-child { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p>
        {{ $company }} · {{ $filters['date_from'] }}
        @if ($filters['date_from'] !== $filters['date_to'])
            {{ __('to') }} {{ $filters['date_to'] }}
        @endif
    </p>
    @if (!empty($documentNumber))
        <p><strong>{{ __('Document number') }}: {{ $documentNumber }}</strong></p>
    @endif
    <p>{{ $description }}</p>
    <p>{{ __('Generated') }}: {{ $generatedAt->format('Y-m-d H:i') }} ({{ config('app.timezone') }})</p>
    <table @class(['ap-journal' => $reportKey === 'ap-journal'])>
        @if ($reportKey === 'ap-journal')
            <colgroup>
                @foreach ((count($headers) === 7 ? [12, 13, 14, 14, 18, 18, 11] : [10, 12, 13, 13, 12, 16, 16, 8]) as $width)
                    <col style="width: {{ $width }}%">
                @endforeach
            </colgroup>
        @endif
        <thead><tr>@foreach ($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">{{ __('No matching records.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>@foreach ($totals as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tfoot>
    </table>
</body>
</html>
