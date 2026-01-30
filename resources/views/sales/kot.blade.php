<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KOT - Kitchen Order Ticket</title>
    <style>
        body { font-family: ui-monospace, monospace; margin: 16px; font-size: 14px; color: #111; max-width: 320px; }
        h1 { font-size: 16px; margin: 0 0 8px; text-align: center; }
        .meta { font-size: 11px; color: #666; margin-bottom: 12px; text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px dashed #ccc; text-align: left; }
        .qty { width: 48px; text-align: right; font-weight: 700; }
        .note { font-size: 11px; color: #444; font-style: italic; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
<h1>KITCHEN ORDER TICKET</h1>
<div class="meta">
    #{{ $sale->id }} • {{ strtoupper($sale->order_type ?? 'takeaway') }} • {{ $sale->created_at?->format('Y-m-d H:i:s') }}
    @if ($sale->reference)
        <br>Ref: {{ $sale->reference }}
    @endif
</div>

<table>
    <thead>
        <tr>
            <th class="qty">Qty</th>
            <th>Item</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sale->items as $item)
            <tr>
                <td class="qty">{{ $item->qty }}</td>
                <td>
                    {{ $item->name_snapshot }}
                    @if (!empty($item->note))
                        <div class="note">{{ $item->note }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="meta" style="margin-top: 12px;">
    Printed {{ now()->format('Y-m-d H:i:s') }}
</div>
</body>
</html>
