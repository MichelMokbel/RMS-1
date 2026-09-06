<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $audience === 'admin' ? 'New promotional membership request' : 'Membership request received' }}</title>
</head>
<body style="margin:0;background:#f6f3ed;color:#172033;font-family:Arial,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e7dfd1;">
            <h1 style="margin:0 0 12px;font-size:24px;">
                {{ $audience === 'admin' ? 'A promotional membership request was received' : 'Your membership request was received' }}
            </h1>
            <p style="margin:0 0 24px;line-height:1.6;">
                @if($audience === 'admin')
                    Review this request in RMS. No payment, subscription or meal allowance was created automatically.
                @else
                    No payment was required. Your request is waiting for review, and your membership is not active yet.
                @endif
            </p>
            <table role="presentation" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:10px 0;color:#667085;">Customer</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['customer_name'] ?? '' }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Plan</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ data_get($snapshot, 'plan.meal_count', 0) }} meals</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Promotion</td>
                    <td style="padding:10px 0;text-align:right;font-family:monospace;">{{ data_get($snapshot, 'promotion.code', '') }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Saving</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">QAR {{ number_format(((int) ($snapshot['discount_amount_cents'] ?? 0)) / 100, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Request reference</td>
                    <td style="padding:10px 0;text-align:right;font-family:monospace;">{{ $snapshot['reference'] ?? '' }}</td>
                </tr>
            </table>
            <p style="margin:24px 0 0;line-height:1.6;color:#667085;">
                Proposed meals: {{ data_get($snapshot, 'proposed_selections.main_quantity', 0) }}. Delivery is included.
            </p>
        </div>
    </div>
</body>
</html>
