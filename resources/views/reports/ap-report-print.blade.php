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
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">{{ __('Print') }}</button>
    <h1>{{ $title }}</h1>
    <p>{{ $company }} · {{ $filters['date_from'] }} {{ __('to') }} {{ $filters['date_to'] }}</p>
    @if (!empty($documentNumber))
        <p><strong>{{ __('Document number') }}: {{ $documentNumber }}</strong></p>
    @endif
    <p>{{ $description }}</p>
    <p>{{ __('Generated') }}: {{ $generatedAt->format('Y-m-d H:i') }} ({{ config('app.timezone') }})</p>
    <table>
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
