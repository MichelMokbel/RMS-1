<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Advances Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        tfoot td { font-weight: 700; background: #f9fafb; }
        .right { text-align: right; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.customer-advances') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Customer Advances Report'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} | Filters: {{ json_encode($filters) }}</div>
    <table>
        <thead>
            <tr>
                <th>Payment #</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Method</th>
                <th class="right">Amount</th>
                <th class="right">Allocated</th>
                <th class="right">Unallocated</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $pay)
                @php
                    $allocated = (int) ($pay->allocated_sum ?? 0);
                    $remaining = (int) $pay->amount_cents - $allocated;
                @endphp
                <tr>
                    <td>#{{ $pay->id }}</td>
                    <td>{{ $pay->customer?->name ?? '—' }}</td>
                    <td>{{ $pay->received_at?->format('Y-m-d') }}</td>
                    <td>{{ strtoupper($pay->method ?? '—') }}</td>
                    <td class="right">{{ $formatCents($pay->amount_cents) }}</td>
                    <td class="right">{{ $formatCents($allocated) }}</td>
                    <td class="right">{{ $formatCents($remaining) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No advances found.</td></tr>
            @endforelse
        </tbody>
        @if ($payments->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td class="right">{{ $formatCents($payments->sum('amount_cents')) }}</td>
                    <td class="right">{{ $formatCents($payments->sum(fn ($p) => (int) ($p->allocated_sum ?? 0))) }}</td>
                    <td class="right">{{ $formatCents($payments->sum(fn ($p) => (int) $p->amount_cents - (int) ($p->allocated_sum ?? 0))) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
    @include('reports.print-footer')
</body>
</html>
