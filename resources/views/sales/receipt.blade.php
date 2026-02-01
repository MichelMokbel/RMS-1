<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; margin: 24px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        .muted { color: #666; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #eee; font-size: 12px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #444; }
        .right { text-align: right; }
        .totals { margin-top: 12px; display: flex; justify-content: flex-end; }
        .totals table { width: auto; }
        .totals td { border: none; padding: 4px 6px; }
        .total-row td { font-weight: 700; border-top: 1px solid #ddd; padding-top: 8px; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
@php
    $scale = \App\Support\Money\MinorUnits::posScale();
    $fmt = function (int $cents) use ($scale): string {
        return \App\Support\Money\MinorUnits::format($cents, $scale, false);
    };
@endphp

<h1>Layla Kitchen</h1>
<div class="muted">
    Receipt {{ $sale->sale_number ?: ('#'.$sale->id) }} • {{ $sale->created_at?->format('Y-m-d H:i') }} • Branch {{ $sale->branch_id }} • {{ $sale->currency ?? config('pos.currency') }}
</div>

<table>
    <thead>
        <tr>
            <th>Item</th>
            <th class="right">Qty</th>
            <th class="right">Unit</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sale->items as $item)
            <tr>
                <td>{{ $item->name_snapshot }}</td>
                <td class="right">{{ $item->qty }}</td>
                <td class="right">{{ $fmt((int) $item->unit_price_cents) }}</td>
                <td class="right">{{ $fmt((int) $item->line_total_cents) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="totals">
    <table>
        <tr>
            <td class="right muted">Subtotal</td>
            <td class="right">{{ $fmt((int) $sale->subtotal_cents) }}</td>
        </tr>
        <tr>
            <td class="right muted">Discount</td>
            <td class="right">-{{ $fmt((int) $sale->discount_total_cents) }}</td>
        </tr>
        <tr>
            <td class="right muted">Tax</td>
            <td class="right">{{ $fmt((int) $sale->tax_total_cents) }}</td>
        </tr>
        <tr class="total-row">
            <td class="right">Total</td>
            <td class="right">{{ $fmt((int) $sale->total_cents) }}</td>
        </tr>
        <tr>
            <td class="right muted">Paid</td>
            <td class="right">{{ $fmt((int) $sale->paid_total_cents) }}</td>
        </tr>
    </table>
</div>

<div style="margin-top: 12px;">
    <div class="muted">Payments</div>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->paymentAllocations as $alloc)
                <tr>
                    <td>{{ strtoupper($alloc->payment?->method ?? '—') }}</td>
                    <td class="right">{{ $fmt((int) $alloc->amount_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

</body>
</html>

