<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $kind === 'cancelled' ? 'Booking cancelled' : ($kind === 'changed' ? 'Booking updated' : 'Meals booked') }}</title>
</head>
<body style="margin:0;background:#f6f3ed;color:#172033;font-family:Arial,sans-serif;">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e7dfd1;">
            <h1 style="margin:0 0 12px;font-size:24px;">
                {{ $kind === 'cancelled' ? 'Your booking was cancelled' : ($kind === 'changed' ? 'Your booking was updated' : 'Your meals are booked') }}
            </h1>
            <p style="margin:0 0 24px;line-height:1.6;">
                @if ($kind === 'cancelled')
                    The meals from this booking are available in your membership again. No refund was issued; your original membership payment remains as membership balance.
                @else
                    These meals were taken from your existing paid membership. You were not charged again.
                @endif
            </p>
            <table role="presentation" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:10px 0;color:#667085;">Service date</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['service_date'] ?? '' }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Main dishes</td>
                    <td style="padding:10px 0;text-align:right;font-weight:700;">{{ $snapshot['main_quantity'] ?? 0 }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0;color:#667085;">Booking reference</td>
                    <td style="padding:10px 0;text-align:right;font-family:monospace;">{{ $snapshot['booking_uuid'] ?? '' }}</td>
                </tr>
            </table>
            @if ($kind !== 'cancelled')
                <p style="margin:24px 0 0;line-height:1.6;color:#667085;">
                    Changes and cancellation are available online until {{ $snapshot['change_deadline_at'] ?? 'the saved deadline' }}.
                </p>
            @endif
        </div>
    </div>
</body>
</html>
