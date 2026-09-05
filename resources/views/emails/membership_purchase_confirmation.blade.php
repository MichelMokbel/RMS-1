<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $audience === 'admin' ? 'New paid membership' : 'Your membership is ready' }}</title>
</head>
<body style="margin:0;background:#f6f3ed;color:#172033;font-family:Arial,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e7dfd1;">
            <h1 style="margin:0 0 12px;font-size:24px;">
                {{ $audience === 'admin' ? 'A Daily Dish membership was paid' : 'Your Daily Dish membership is ready' }}
            </h1>
            <p style="margin:0 0 24px;line-height:1.6;">
                @if($audience === 'admin')
                    The customer payment was verified and the membership allowance was created.
                @else
                    Your payment was verified. You can choose your meals now or return later. Your unused meals do not expire.
                @endif
            </p>
            <table role="presentation" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:10px 0;color:#667085;">Plan</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['meal_count'] ?? 0 }} meals</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Amount paid</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">QAR {{ number_format(((int) ($snapshot['amount_cents'] ?? 0)) / 100, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Reference</td>
                    <td style="padding:10px 0;text-align:right;font-family:monospace;">{{ $snapshot['reference'] ?? '' }}</td>
                </tr>
            </table>
            <p style="margin:24px 0 0;line-height:1.6;color:#667085;">
                Delivery is included. Future meal choices will use this paid allowance and will not ask for payment again.
            </p>
        </div>
    </div>
</body>
</html>
