<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Plan Request Report</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; background: #f8fafc; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        h2 { margin: 0; font-size: 17px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .toolbar { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; color: #111827; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        .no-print { display: inline-block; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 20px; margin: 18px 0; padding: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        .summary div { font-size: 13px; }
        .summary strong { display: block; font-size: 12px; color: #4b5563; margin-bottom: 4px; }
        .day-card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-top: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .day-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
        .day-head-main { display: flex; flex-direction: column; gap: 4px; }
        .day-orders { color: #4b5563; font-size: 12px; }
        .day-total, .grand-total { font-weight: 700; }
        .day-total { font-size: 14px; white-space: nowrap; }
        .grand-total { margin-top: 22px; padding: 16px 0 0; border-top: 2px solid #d1d5db; font-size: 18px; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 10px; font-size: 13px; text-align: left; vertical-align: top; }
        th { background: #f8fafc; font-weight: 600; color: #374151; }
        .muted { color: #6b7280; font-size: 12px; }
        @include('reports.print-header-styles')
        @media print {
            .no-print { display: none !important; }
            body { margin: 12px; background: #fff; }
            th, td { font-size: 12px; }
            .day-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    @php
        $displayItemName = function ($item): string {
            $name = trim((string) ($item->menuItem?->name ?? ''));
            if ($name !== '') {
                return $name;
            }

            $snapshot = trim((string) ($item->description_snapshot ?? ''));
            if ($snapshot === '') {
                return '—';
            }

            $snapshot = preg_replace('/^Daily Dish\s*\([^)]*\)\s*-\s*/i', '', $snapshot) ?? $snapshot;
            $snapshot = preg_replace('/^MI-\d+\s+/i', '', $snapshot) ?? $snapshot;
            $snapshot = preg_replace('/\bMI-\d+\b\s*/i', '', $snapshot) ?? $snapshot;

            return trim($snapshot) !== '' ? trim($snapshot) : '—';
        };
    @endphp

    <div class="toolbar no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('meal-plan-requests.show', $mealPlanRequest) }}">Back to Request</a>
    </div>

    @include('reports.print-header', ['reportTitle' => 'Meal Plan Request Report'])

    <div class="meta">
        Generated: {{ $generatedAt->format('Y-m-d H:i') }} |
        Request: #{{ $mealPlanRequest->id }} |
        Customer: {{ $mealPlanRequest->customer_name }} |
        Plan: {{ $mealPlanRequest->plan_meals > 0 ? $mealPlanRequest->plan_meals . ' meals' : 'No plan' }}
    </div>

    <div class="summary">
        <div>
            <strong>Customer Name</strong>
            {{ $mealPlanRequest->customer_name ?: '—' }}
        </div>
        <div>
            <strong>Phone</strong>
            {{ $mealPlanRequest->customer_phone ?: '—' }}
        </div>
        <div>
            <strong>Email</strong>
            {{ $mealPlanRequest->customer_email ?: '—' }}
        </div>
        <div>
            <strong>Delivery Address</strong>
            {{ $mealPlanRequest->delivery_address ?: '—' }}
        </div>
        <div>
            <strong>Notes</strong>
            {{ $mealPlanRequest->notes ?: '—' }}
        </div>
    </div>

    @forelse ($days as $day)
        <section class="day-card">
            <div class="day-head">
                <div class="day-head-main">
                    <h2>{{ $day['date'] === 'unscheduled' ? 'Unscheduled' : \Illuminate\Support\Carbon::parse($day['date'])->format('Y-m-d') }}</h2>
                    <div class="day-orders">
                        Orders:
                        {{ $day['orders']->map(fn ($order) => $order->order_number ?: ('#' . $order->id))->join(', ') }}
                    </div>
                    @php($dayNotes = $day['orders']->pluck('notes')->filter(fn ($note) => trim((string) $note) !== '')->unique()->values())
                    @if ($dayNotes->isNotEmpty())
                        <div class="day-orders">
                            Notes:
                            {{ $dayNotes->join(' | ') }}
                        </div>
                    @endif
                </div>
                <div class="day-total">Day Total: {{ number_format((float) $day['day_total'], 3) }}</div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="width: 90px;">Role</th>
                        <th style="width: 80px;">Qty</th>
                        <th style="width: 100px;">Unit Price</th>
                        <th style="width: 100px;">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($day['orders'] as $order)
                        @forelse ($order->items as $item)
                            <tr>
                                <td>{{ $displayItemName($item) }}</td>
                                <td>{{ $item->role ?: '—' }}</td>
                                <td>{{ number_format((float) $item->quantity, 3) }}</td>
                                <td>{{ number_format((float) $item->unit_price, 3) }}</td>
                                <td>{{ number_format((float) $item->line_total, 3) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">No order items found.</td>
                            </tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p>No orders are attached to this meal plan request.</p>
    @endforelse

    <div class="grand-total">
        Total Amount of Cart: {{ number_format((float) $grandTotal, 3) }}
    </div>

    @include('reports.print-footer')
</body>
</html>
