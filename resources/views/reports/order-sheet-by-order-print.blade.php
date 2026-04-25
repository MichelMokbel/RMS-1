<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ops — By Order</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: 'Times New Roman', Times, serif; margin: 14px; color: #000; font-size: 13px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 12px; cursor: pointer; font-family: Arial, sans-serif; }
        .btn:hover { background: #f3f4f6; }
        @include('reports.print-header-styles')
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #b0b4ba; padding: 5px 7px; font-size: 12px; text-align: left; font-family: 'Times New Roman', Times, serif; }
        th { background: #f3f4f6; font-weight: 700; text-align: center; font-family: Arial, sans-serif; font-size: 11px; }
        td.name { font-weight: 600; min-width: 130px; }
        td.loc  { min-width: 80px; color: #374151; }
        td.qty  { text-align: center; font-weight: 700; width: 46px; }
        td.qty.zero { color: #d1d5db; font-weight: 400; }
        td.extras { font-size: 11px; color: #374151; min-width: 120px; }
        td.remarks { font-size: 11px; color: #6b7280; min-width: 100px; }
        tfoot td { font-weight: 700; background: #f9fafb; font-family: Arial, sans-serif; font-size: 11px; }
        tfoot td.qty { font-size: 13px; }
        .section-label { margin: 16px 0 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; font-family: Arial, sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('order-sheet.index') }}">Back to Order Sheet</a>
    </div>

    @include('reports.print-header', [
        'reportTitle' => 'Kitchen Ops — By Order',
        'generatedAt' => $generatedAt,
        'generatedBy' => $generatedBy,
        'startAt'     => $date,
        'endAt'       => $date,
        'warehouse'   => 'All Branches',
        'salesPerson' => '-',
    ])

    @if ($entries->isEmpty())
        <p style="margin-top:16px; color:#6b7280;">No entries for {{ $date->format('d M Y') }}.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="text-align:left; width:140px;">Customer</th>
                    <th style="text-align:left; width:90px;">Location</th>
                    @foreach ($menuItems as $item)
                        <th title="{{ $item['name'] }}">{{ \Illuminate\Support\Str::limit($item['name'], 12, '…') }}</th>
                    @endforeach
                    <th style="text-align:left;">Extras</th>
                    <th style="text-align:left; width:100px;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $row)
                    <tr>
                        <td class="name">{{ $row['customer_name'] }}</td>
                        <td class="loc">{{ $row['location'] ?: '—' }}</td>
                        @foreach ($menuItems as $item)
                            @php $q = (int) ($row['qty'][$item['id']] ?? 0); @endphp
                            <td class="qty {{ $q === 0 ? 'zero' : '' }}">{{ $q ?: '—' }}</td>
                        @endforeach
                        <td class="extras">
                            @foreach ($row['extras'] as $extra)
                                {{ $extra['name'] }} × {{ $extra['quantity'] }}<br>
                            @endforeach
                        </td>
                        <td class="remarks">{{ $row['remarks'] ?: '' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>Total ({{ $entries->count() }} orders)</strong></td>
                    @foreach ($menuItems as $item)
                        <td class="qty">{{ $dishTotals[$item['id']]['quantity'] ?? 0 }}</td>
                    @endforeach
                    <td colspan="2">
                        @foreach ($extraTotals as $et)
                            {{ $et['name'] }}: {{ $et['quantity'] }}&nbsp;&nbsp;
                        @endforeach
                    </td>
                </tr>
            </tfoot>
        </table>
    @endif

    @include('reports.print-footer')
</body>
</html>
