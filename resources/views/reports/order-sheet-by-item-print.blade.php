<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ops — Item Totals</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: 'Times New Roman', Times, serif; margin: 14px; color: #000; font-size: 13px; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 12px; cursor: pointer; font-family: Arial, sans-serif; }
        .btn:hover { background: #f3f4f6; }
        @include('reports.print-header-styles')
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 14px; }
        .section-title { margin: 0 0 6px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #374151; font-family: Arial, sans-serif; border-bottom: 2px solid #111; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #b0b4ba; padding: 6px 8px; font-family: 'Times New Roman', Times, serif; font-size: 13px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; font-family: Arial, sans-serif; font-size: 11px; }
        td.qty { text-align: right; font-weight: 700; font-size: 16px; width: 60px; }
        td.role { font-size: 11px; color: #6b7280; width: 80px; font-family: Arial, sans-serif; }
        tfoot td { background: #f9fafb; font-weight: 700; font-family: Arial, sans-serif; font-size: 11px; }
        tfoot td.qty { font-size: 18px; font-family: 'Times New Roman', Times, serif; }
        .empty { color: #6b7280; font-size: 12px; padding: 8px 0; font-family: Arial, sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; }
            .two-col { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('order-sheet.index') }}">Back to Order Sheet</a>
    </div>

    @include('reports.print-header', [
        'reportTitle' => 'Kitchen Ops — Item Totals',
        'generatedAt' => $generatedAt,
        'generatedBy' => $generatedBy,
        'startAt'     => $date,
        'endAt'       => $date,
        'warehouse'   => 'All Branches',
        'salesPerson' => '-',
    ])

    <div class="two-col">
        {{-- ── Dish totals ── --}}
        <div>
            <p class="section-title">Dish Totals &nbsp;({{ $entries->count() }} orders)</p>

            @php
                $filledDishes = collect($dishTotals)->filter(fn ($d) => $d['quantity'] > 0)->values();
                $grandTotal = collect($dishTotals)->sum('quantity');
            @endphp

            @if ($filledDishes->isEmpty())
                <p class="empty">No dish quantities recorded.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Dish</th>
                            <th style="width:80px;">Category</th>
                            <th style="width:60px; text-align:right;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filledDishes as $dish)
                            <tr>
                                <td>{{ $dish['name'] }}</td>
                                <td class="role">{{ ucfirst($dish['role']) }}</td>
                                <td class="qty">{{ $dish['quantity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>Grand Total</strong></td>
                            <td class="qty">{{ $grandTotal }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </div>

        {{-- ── Extras totals ── --}}
        <div>
            <p class="section-title">Extra Dishes</p>

            @if (empty($extraTotals))
                <p class="empty">No extra dishes recorded.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="width:60px; text-align:right;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($extraTotals as $et)
                            <tr>
                                <td>{{ $et['name'] }}</td>
                                <td class="qty">{{ $et['quantity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Total</strong></td>
                            <td class="qty">{{ collect($extraTotals)->sum('quantity') }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </div>
    </div>

    @include('reports.print-footer')
</body>
</html>
