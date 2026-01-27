<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Invoices</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 16px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        h2 { margin: 0 0 6px; font-size: 16px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        .no-print { display: inline-block; margin-bottom: 12px; }
        .invoice { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin-bottom: 12px; background: #fff; page-break-after: always; }
        .invoice:last-child { page-break-after: auto; }
        .row { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; margin-bottom: 6px; }
        .label { font-weight: 600; color: #374151; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        tfoot td { font-weight: 700; background: #f9fafb; }
        .total { text-align: right; font-weight: 700; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 8px; }
            th, td { font-size: 11px; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print All</button>
        <a class="btn" href="{{ route('orders.index') }}">Back to Orders</a>
    </div>

    <h1>Orders Invoices</h1>
    <div class="meta">
        Generated: {{ $generatedAt->format('Y-m-d H:i') }} |
        Status: {{ $filters['status'] ?? 'all' }} |
        Source: {{ $filters['source'] ?? 'all' }} |
        Branch: {{ $filters['branch_id'] ?? 'all' }} |
        Orders: {{ $filters['daily_dish_filter'] ?? 'all' }} |
        Date: {{ $filters['scheduled_date'] ?? 'any' }} |
        Search: {{ $filters['search'] ?? 'none' }}
    </div>

    @php
        $grouped = $orders->groupBy(fn ($o) => $o->customer_name_snapshot ?? 'N/A');
    @endphp

    @forelse ($grouped as $customer => $customerOrders)
        <h2>{{ $customer }}</h2>
        @foreach ($customerOrders as $order)
            <div class="invoice">
                <div class="row">
                    <div><span class="label">Order #:</span> {{ $order->order_number }}</div>
                    <div><span class="label">Type:</span> {{ $order->type }}</div>
                    <div><span class="label">Status:</span> {{ $order->status }}</div>
                    <div><span class="label">Branch:</span> {{ $order->branch_id }}</div>
                    <div><span class="label">Source:</span> {{ $order->source }}</div>
                </div>
                <div class="row">
                    <div><span class="label">Scheduled:</span> {{ $order->scheduled_date?->format('Y-m-d') }} {{ $order->scheduled_time }}</div>
                    <div><span class="label">Contact:</span> {{ $order->customer_phone_snapshot ?? '—' }}</div>
                    <div><span class="label">Address:</span> {{ $order->delivery_address_snapshot ?? '—' }}</div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 48%;">Item</th>
                            <th style="width: 14%;">Qty</th>
                            <th style="width: 16%;">Unit</th>
                            <th style="width: 12%;">Discount</th>
                            <th style="width: 16%; text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->items as $item)
                            <tr>
                                <td>{{ $item->description_snapshot ?? 'Item' }}</td>
                                <td>{{ number_format((float) $item->quantity, 3) }}</td>
                                <td>{{ number_format((float) ($item->unit_price ?? 0), 3) }}</td>
                                <td>{{ number_format((float) ($item->discount_amount ?? 0), 3) }}</td>
                                <td style="text-align:right;">{{ number_format((float) ($item->line_total ?? 0), 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No items.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="total">Order Total</td>
                            <td style="text-align:right;">{{ number_format((float) $order->total_amount, 3) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach
    @empty
        <p>No orders found for the selected filters.</p>
    @endforelse
</body>
</html>
