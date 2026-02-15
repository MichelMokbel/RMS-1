<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Wise Branch Sales Summary</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.session-branch-sales') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Session Wise Branch Sales Summary'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Shift #</th>
                <th>Branch</th>
                <th>Cashier</th>
                <th>Opened</th>
                <th>Closed</th>
                <th class="right">Card Closing</th>
                <th class="right">Invoices</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>#{{ $row->id }}</td>
                    <td>{{ $row->branch_id }}</td>
                    <td>{{ $row->cashier_name ?? '—' }}</td>
                    <td>{{ optional($row->opened_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ optional($row->closed_at)->format('Y-m-d H:i') }}</td>
                    <td class="right">{{ $formatCents((int) ($row->closing_card_cents ?? 0)) }}</td>
                    <td class="right">{{ (int) ($row->invoice_count ?? 0) }}</td>
                    <td class="right">{{ $formatCents((int) ($row->total_cents ?? 0)) }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No sessions found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
