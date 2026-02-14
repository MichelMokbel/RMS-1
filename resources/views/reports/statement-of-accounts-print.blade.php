<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement of Accounts</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        .cards { display: flex; gap: 12px; margin: 12px 0; }
        .card { border: 1px solid #e5e7eb; padding: 8px 12px; border-radius: 6px; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.statement-of-accounts') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Statement of Accounts'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <div class="cards">
        <div class="card">Opening: {{ $formatCents($summary['opening']) }}</div>
        <div class="card">Invoices: {{ $formatCents($summary['invoices']) }}</div>
        <div class="card">Payments: {{ $formatCents($summary['payments']) }}</div>
        <div class="card">Closing: {{ $formatCents($summary['closing']) }}</div>
    </div>
</body>
</html>
