<div style="font-family: Arial, sans-serif; color:#111; line-height:1.5;">
    <h2>New Daily Dish Order</h2>

    @php
        $first = $orders->first();
    @endphp

    <p>
        <strong>Customer:</strong> {{ $first?->customer_name_snapshot }}<br>
        <strong>Phone:</strong> {{ $first?->customer_phone_snapshot }}<br>
        <strong>Email:</strong> {{ $first?->customer_email_snapshot ?? '—' }}<br>
        <strong>Address:</strong> {{ $first?->delivery_address_snapshot ?? '—' }}<br>
        @if($first?->notes)
            <strong>Notes:</strong> {{ $first->notes }}<br>
        @endif
    </p>

    @if($mealPlanMeals)
        <p><strong>Meal Plan Request:</strong> {{ $mealPlanMeals }} meals @if($mealPlanRequestId)(Lead #{{ $mealPlanRequestId }})@endif</p>
    @endif

    @foreach($orders as $order)
        <hr>
        <h3>Order {{ $order->order_number }} ({{ $order->scheduled_date?->format('Y-m-d') }})</h3>
        <p><strong>Total:</strong> QAR {{ number_format((float) $order->total_amount, 3) }}</p>
        <ul>
            @foreach($order->items as $it)
                <li>
                    {{ $it->description_snapshot }} — Qty {{ number_format((float) $it->quantity, 3) }} × QAR {{ number_format((float) $it->unit_price, 3) }}
                    = QAR {{ number_format((float) $it->line_total, 3) }}
                </li>
            @endforeach
        </ul>
    @endforeach
</div>


