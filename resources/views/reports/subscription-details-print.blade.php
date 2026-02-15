<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Details Report</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        .meta { font-size: 12px; color: #4b5563; margin-bottom: 16px; }
        .toolbar { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn:hover { background: #f3f4f6; }
        .no-print { display: inline-block; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 13px; text-align: left; }
        th { background: #f9fafb; font-weight: 600; }
        @include('reports.print-header-styles')
        @media print { .no-print { display: none !important; } body { margin: 12px; } th, td { font-size: 12px; } }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.subscription-details') }}">Back to Report</a>
    </div>
    @include('reports.print-header', ['reportTitle' => 'Subscription Details Report'])
    <div class="meta">
        Generated: {{ $generatedAt->format('Y-m-d H:i') }} |
        Status: {{ $filters['status'] ?? 'all' }} |
        Customer: {{ $filters['customer_id'] ?? 'all' }} |
        Branch: {{ $filters['branch_id'] ?? 'all' }} |
        Date from: {{ $filters['date_from'] ?? '—' }} |
        Date to: {{ $filters['date_to'] ?? '—' }} |
        Search: {{ $filters['search'] ?? 'none' }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Subscription Code</th>
                <th>Customer</th>
                <th style="width: 80px;">Status</th>
                <th style="width: 100px;">Start Date</th>
                <th style="width: 100px;">End Date</th>
                <th style="width: 90px;">Order Type</th>
                <th style="width: 100px;">Plan</th>
                <th style="width: 90px;">Meals Used</th>
                <th style="width: 100px;">Created</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subscriptions as $subscription)
                <tr>
                    <td>{{ $subscription->subscription_code }}</td>
                    <td>{{ $subscription->customer->name ?? '—' }}</td>
                    <td>{{ ucfirst($subscription->status) }}</td>
                    <td>{{ $subscription->start_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $subscription->end_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $subscription->default_order_type }}</td>
                    <td>
                        @if ($subscription->plan_meals_total)
                            {{ $subscription->plan_meals_total }} meals
                        @else
                            Unlimited
                        @endif
                    </td>
                    <td>
                        @if ($subscription->plan_meals_total)
                            {{ $subscription->meals_used ?? 0 }} / {{ $subscription->plan_meals_total }}
                        @else
                            {{ $subscription->meals_used ?? 0 }}
                        @endif
                    </td>
                    <td>{{ $subscription->created_at?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No subscriptions found.</td></tr>
            @endforelse
        </tbody>
    </table>
    @include('reports.print-footer')
</body>
</html>
