<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $audience === 'admin' ? 'New paid advance menu order' : 'Your menu order is confirmed' }}</title>
</head>
<body style="margin:0;background:#f6f3ed;color:#172033;font-family:Arial,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e7dfd1;">
            <h1 style="margin:0 0 12px;font-size:24px;">
                {{ $audience === 'admin' ? 'A menu order was paid' : 'Your menu order is confirmed' }}
            </h1>
            <p style="margin:0 0 24px;line-height:1.6;">
                @if($audience === 'admin')
                    SkipCash verified the payment and RMS created the order and paid invoice.
                @else
                    Thank you, {{ $snapshot['customer_name'] ?? 'Customer' }}. SkipCash verified your payment and we recorded your order for the date below.
                @endif
            </p>

            @if($audience === 'admin')
                <p style="margin:0 0 20px;line-height:1.6;">
                    <strong>Customer:</strong> {{ $snapshot['customer_name'] ?? '—' }}<br>
                    <strong>Phone:</strong> {{ $snapshot['customer_phone'] ?? '—' }}<br>
                    <strong>Email:</strong> {{ $snapshot['customer_email'] ?? '—' }}<br>
                    <strong>Address:</strong> {{ $snapshot['customer_address'] ?? '—' }}
                </p>
            @endif

            <table role="presentation" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:10px 0;color:#667085;">Order</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['order_number'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Service date</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['service_date'] ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Delivery</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">Included</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Amount paid</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">QAR {{ number_format(((int) ($snapshot['amount_cents'] ?? 0)) / 100, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Payment reference</td>
                    <td style="padding:10px 0;text-align:right;font-family:monospace;">{{ $snapshot['payment_reference'] ?? '—' }}</td>
                </tr>
            </table>

            <h2 style="margin:24px 0 10px;font-size:18px;">Items</h2>
            <ul style="margin:0;padding-left:20px;line-height:1.7;">
                @foreach((array) ($snapshot['items'] ?? []) as $item)
                    <li>
                        {{ $item['title'] ?? 'Item' }}
                        · {{ $item['quantity'] ?? '' }} {{ $item['unit'] ?? '' }}
                        · QAR {{ number_format(((int) ($item['line_total_cents'] ?? 0)) / 100, 2) }}
                    </li>
                @endforeach
            </ul>

            <p style="margin:24px 0 0;line-height:1.6;color:#667085;">
                This confirmation records a paid order. It does not mark the order as delivered.
                Payments are not refunded through the website. If Layla Kitchen authorizes a cancellation and voids the linked invoice, the paid amount remains as customer credit for administrator allocation.
                @if(!empty($snapshot['support_phone'])) For help, contact {{ $snapshot['support_phone'] }}.@endif
            </p>

            @if($audience === 'admin' && !empty($snapshot['invoice_id']))
                <p style="margin:24px 0 0;">
                    <a href="{{ route('invoices.show', $snapshot['invoice_id']) }}" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#173f8a;color:#ffffff;text-decoration:none;font-weight:700;">Open invoice in RMS</a>
                </p>
            @endif
        </div>
    </div>
</body>
</html>
