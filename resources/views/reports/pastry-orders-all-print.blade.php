<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pastry Orders - Print All</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 8mm; }
        .tools { margin-bottom: 8px; }
        .btn { display: inline-block; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; color: #111827; text-decoration: none; font-size: 12px; background: #fff; }
        .page { page-break-after: always; max-width: 190mm; }
        .page:last-child { page-break-after: auto; }
        .header { display: flex; justify-content: space-between; gap: 8mm; margin-bottom: 4mm; }
        .title { font-size: 20px; font-weight: 700; margin: 0; }
        .meta { font-size: 12px; color: #4b5563; margin-top: 1mm; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #d1d5db; border-radius: 999px; font-size: 12px; }
        .content { display: grid; grid-template-columns: 62mm 1fr; gap: 6mm; }
        .image-wrap { border: 1px solid #d1d5db; border-radius: 8px; padding: 4px; min-height: 62mm; display: flex; align-items: center; justify-content: center; }
        .image-wrap img { width: 100%; max-height: 60mm; object-fit: cover; border-radius: 6px; }
        .no-image { font-size: 12px; color: #6b7280; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; margin-bottom: 6px; }
        .card h3 { margin: 0 0 6px; font-size: 13px; }
        .kv { font-size: 12px; margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #e5e7eb; padding: 5px; font-size: 11px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        .num { text-align: right; white-space: nowrap; }
        @media print { .tools { display: none !important; } }
    </style>
</head>
<body>
    <div class="tools">
        <button class="btn" onclick="window.print()">Print All</button>
    </div>
    @forelse ($orders as $order)
        <div class="page">
            <div class="header">
                <div>
                    <p class="title">Pastry Order</p>
                    <div class="meta">{{ $order->order_number }} · {{ $order->scheduled_date?->format('Y-m-d') ?? '—' }}</div>
                </div>
                <div class="status">{{ $order->status ?? '—' }}</div>
            </div>

            <div class="content">
                <div class="image-wrap">
                    @php($orderImages = $imageMap[$order->id] ?? [])
                    @if (!empty($orderImages) && !empty($orderImages[0]['url']))
                        <img src="{{ $orderImages[0]['url'] }}" alt="Order image" />
                    @else
                        <div class="no-image">No image</div>
                    @endif
                </div>
                <div>
                    <div class="card">
                        <h3>Customer</h3>
                        <div class="kv"><strong>Name:</strong> {{ $order->customer_name_snapshot ?? '—' }}</div>
                        <div class="kv"><strong>Phone:</strong> {{ $order->customer_phone_snapshot ?? '—' }}</div>
                        <div class="kv"><strong>Type:</strong> {{ $order->type ?? '—' }}</div>
                        <div class="kv"><strong>Branch:</strong> {{ $order->branch_id ?? '—' }}</div>
                        @if($order->notes)
                            <div class="kv"><strong>Notes:</strong> {{ $order->notes }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit Price</th>
                        <th class="num">Discount</th>
                        <th class="num">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>{{ $item->description_snapshot }}</td>
                            <td class="num">{{ number_format((float) $item->quantity, 3) }}</td>
                            <td class="num">{{ number_format((float) $item->unit_price, 3) }}</td>
                            <td class="num">{{ number_format((float) $item->discount_amount, 3) }}</td>
                            <td class="num">{{ number_format((float) $item->line_total, 3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="num"><strong>Total</strong></td>
                        <td class="num"><strong>{{ number_format((float) $order->total_amount, 3) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @empty
        <p>No pastry orders found.</p>
    @endforelse
</body>
</html>
