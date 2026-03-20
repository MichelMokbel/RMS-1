<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Customers Statement</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #111; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; color: #111; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 12px; }
        .section { margin-bottom: 22px; }
        .section-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
        .section-title { font-size: 15px; font-weight: 700; }
        .section-code { font-size: 12px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cfd3d8; padding: 6px; font-size: 11px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; }
        .right { text-align: right; }
        tfoot td { font-weight: 700; background: #f9fafb; }
        .grand-total { border-top: 2px solid #111; margin-top: 16px; padding-top: 10px; }
        .grand-total-grid { width: 380px; margin-left: auto; }
        .grand-total-grid td { border: 1px solid #cfd3d8; padding: 8px; font-size: 12px; }
        .grand-total-grid td:first-child { font-weight: 700; }
        .page-break { page-break-before: always; }
        @include('reports.print-header-styles')
        @media print {
            .no-print { display: none !important; }
            .section { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.customers-statement') }}">Back to Report</a>
    </div>

    @include('reports.print-header', ['reportTitle' => 'All Customers Statement'])
    <div class="meta">Generated: {{ $generatedAt->format('Y-m-d H:i') }} @include('reports.print-filters', ['filters' => $filters])</div>

    @forelse ($sections as $section)
        <div class="section {{ $loop->index > 0 ? 'page-break' : '' }}">
            <div class="section-header">
                <div class="section-title">{{ $section['customer_name'] }}</div>
                <div class="section-code">Code: {{ $section['customer_code'] ?: '-' }}</div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>NO</th>
                        <th>DOCUMENT TYPE</th>
                        <th>LOCATION</th>
                        <th>TYPE</th>
                        <th>DATE</th>
                        <th>DUE DATE</th>
                        <th>REFERENCE NO</th>
                        <th class="right">AMOUNT</th>
                        <th class="right">PAID</th>
                        <th class="right">BALANCE</th>
                        <th>AGING</th>
                        <th>PAYMENT NO</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td>{{ $row['line_no'] }}</td>
                            <td>{{ $row['document_no'] }}</td>
                            <td>{{ $row['document_type'] }}</td>
                            <td>{{ $row['location'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['due_date'] }}</td>
                            <td>{{ $row['reference_no'] }}</td>
                            <td class="right">{{ $formatCents($row['amount_cents']) }}</td>
                            <td class="right">{{ $formatCents($row['paid_cents']) }}</td>
                            <td class="right">{{ $formatCents($row['balance_cents']) }}</td>
                            <td>{{ $row['aging_label'] }}</td>
                            <td>{{ $row['payment_no'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="8" class="right">TOTAL AMOUNT</td>
                        <td class="right">{{ $formatCents($section['summary']['period_amount_cents']) }}</td>
                        <td class="right">{{ $formatCents($section['summary']['period_paid_cents']) }}</td>
                        <td class="right">{{ $formatCents($section['summary']['period_balance_cents']) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @empty
        <div>No customers found.</div>
    @endforelse

    @if (($sections ?? collect())->count() > 0)
        <div class="grand-total">
            <table class="grand-total-grid">
                <tr>
                    <td>Grand Amount</td>
                    <td class="right">{{ $formatCents($grandTotals['period_amount_cents']) }}</td>
                </tr>
                <tr>
                    <td>Grand Paid</td>
                    <td class="right">{{ $formatCents($grandTotals['period_paid_cents']) }}</td>
                </tr>
                <tr>
                    <td>Grand Balance</td>
                    <td class="right">{{ $formatCents($grandTotals['period_balance_cents']) }}</td>
                </tr>
            </table>
        </div>
    @endif

    @include('reports.print-footer')
</body>
</html>
