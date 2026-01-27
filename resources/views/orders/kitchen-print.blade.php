<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Orders</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 16px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        .no-print { display: inline-block; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .status { font-size: 11px; padding: 2px 6px; border-radius: 10px; display: inline-block; }
        .status-Confirmed { background: #fff7ed; color: #c2410c; }
        .status-InProduction { background: #ecfeff; color: #0ea5e9; }
        .status-Ready { background: #ecfdf3; color: #15803d; }
        .status-Draft { background: #f3f4f6; color: #374151; }
        .status-Cancelled { background: #fef2f2; color: #b91c1c; }
        .status-Delivered { background: #eef2ff; color: #4338ca; }
        .items { color: #374151; font-size: 12px; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 8px; }
            th, td { font-size: 11px; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('kitchen.ops', [$filters['branch_id'] ?? 1, $filters['date'] ?? now()->toDateString()]) }}">Back to Kitchen</a>
    </div>

    <h1>Kitchen Orders</h1>
    <div class="meta">
        Date: {{ $filters['date'] ?? now()->toDateString() }} |
        Branch: {{ $filters['branch_id'] ?? 'all' }} |
        Subscription: {{ ($filters['show_subscription'] ?? true) ? 'yes' : 'no' }} |
        Manual: {{ ($filters['show_manual'] ?? true) ? 'yes' : 'no' }} |
        Draft: {{ ($filters['include_draft'] ?? false) ? 'yes' : 'no' }} |
        Ready: {{ ($filters['include_ready'] ?? true) ? 'yes' : 'no' }} |
        Cancelled: {{ ($filters['include_cancelled'] ?? false) ? 'yes' : 'no' }} |
        Delivered: {{ ($filters['include_delivered'] ?? false) ? 'yes' : 'no' }} |
        Search: {{ $filters['search'] ?? 'none' }} |
        Generated: {{ $generatedAt->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 90px;">Order #</th>
                <th style="width: 70px;">Branch</th>
                <th style="width: 90px;">Status</th>
                <th style="width: 90px;">Type</th>
                <th>Customer</th>
                <th style="width: 140px;">Time</th>
                <th>Items</th>
                <th style="width: 90px; text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ $order->order_number }}</td>
                    <td>{{ $order->branch_id }}</td>
                    <td><span class="status status-{{ $order->status }}">{{ $order->status }}</span></td>
                    <td>{{ $order->type }}</td>
                    <td>{{ $order->customer_name_snapshot ?? 'N/A' }}</td>
                    <td>{{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</td>
                    <td class="items">
                        @if($order->items && $order->items->count())
                            {{ $order->items->map(fn ($i) => trim(($i->description_snapshot ?? 'Item').' x'.$i->quantity))->join(' • ') }}
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td style="text-align:right;">{{ number_format((float) $order->total_amount, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No orders found for the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
