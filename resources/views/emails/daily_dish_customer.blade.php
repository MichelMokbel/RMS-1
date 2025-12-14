<div style="font-family: Arial, sans-serif; color:#111; line-height:1.5;">
    <h2>Thank you for your Daily Dish order</h2>

    @php
        $first = $orders->first();
    @endphp

    <p>
        Dear {{ $first?->customer_name_snapshot }},<br>
        We received your order. Below are your order details.
    </p>

    @if($mealPlanMeals)
        <p><strong>Meal Plan Request:</strong> {{ $mealPlanMeals }} meals. Our team will contact you to finalize. @if($mealPlanRequestId)(Reference #{{ $mealPlanRequestId }})@endif</p>
    @endif

    @foreach($orders as $order)
        <hr>
        <h3>Order {{ $order->order_number }} ({{ $order->scheduled_date?->format('Y-m-d') }})</h3>
        <p><strong>Total:</strong> QAR {{ number_format((float) $order->total_amount, 3) }}</p>
        <ul>
            @foreach($order->items as $it)
                <li>
                    {{ $it->description_snapshot }} — Qty {{ number_format((float) $it->quantity, 3) }}
                </li>
            @endforeach
        </ul>
    @endforeach

    <p>
        If you need changes, please contact us on WhatsApp/Phone: <strong>55683442</strong>.
    </p>
</div>


