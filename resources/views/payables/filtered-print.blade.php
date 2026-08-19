<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $type === 'payments' ? __('Payments') : __('Accounts Payable') }} — {{ __('Filtered Print') }}</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4 landscape; margin: 9mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; background: #fff; font-family: Arial, Helvetica, sans-serif; font-size: 10px; }
        .tools { margin: 0 0 7mm; display: flex; gap: 8px; }
        .button { border: 1px solid #374151; background: #fff; border-radius: 4px; padding: 8px 16px; cursor: pointer; }
        h1 { margin: 0; font-size: 20px; }
        .meta { display: flex; justify-content: space-between; align-items: end; border-bottom: 2px solid #111827; padding-bottom: 4mm; }
        .meta p { margin: 1mm 0 0; color: #4b5563; }
        .filters { display: flex; flex-wrap: wrap; gap: 2mm; margin: 4mm 0; }
        .filter { border: 1px solid #d1d5db; border-radius: 999px; padding: 1.5mm 3mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 2mm; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .voided { color: #b91c1c; font-weight: 700; }
        @media print { .tools { display: none !important; } }
    </style>
</head>
<body>
<div class="tools">
    <button type="button" class="button" onclick="window.print()">{{ __('Print') }}</button>
    <button type="button" class="button" onclick="window.close()">{{ __('Close') }}</button>
</div>

<header class="meta">
    <div>
        <h1>{{ $type === 'payments' ? __('Payments') : __('Accounts Payable') }}</h1>
        <p>{{ __('Filtered table print') }} · {{ trans_choice(':count record|:count records', $records->count(), ['count' => $records->count()]) }}</p>
    </div>
    <p>{{ __('Printed') }}: {{ now()->format('d M Y H:i') }}</p>
</header>

@if($filterLabels !== [])
    <div class="filters">
        @foreach($filterLabels as $label => $value)
            <span class="filter"><strong>{{ __($label) }}:</strong> {{ $value }}</span>
        @endforeach
    </div>
@endif

@if($type === 'payments')
    <table>
        <thead>
            <tr>
                <th>{{ __('Voucher') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th>{{ __('Method') }}</th>
                <th>{{ __('Reference') }}</th>
                <th class="right">{{ __('Amount') }}</th>
                <th class="right">{{ __('Allocated') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $payment)
                <tr>
                    <td>{{ $payment->voucherNumber() }}</td>
                    <td>{{ $payment->payment_date?->format('Y-m-d') ?: '—' }}</td>
                    <td>{{ $payment->supplier?->name ?: '—' }}</td>
                    <td>{{ str($payment->payment_method ?: '—')->replace('_', ' ')->headline() }}</td>
                    <td>{{ $payment->reference ?: '—' }}</td>
                    <td class="right">{{ number_format((float) $payment->amount, 2) }} {{ strtoupper($payment->currency_code ?: config('pos.currency', 'QAR')) }}</td>
                    <td class="right">{{ number_format((float) $payment->alloc_sum, 2) }}</td>
                    <td class="{{ $payment->voided_at ? 'voided' : '' }}">{{ $payment->voided_at ? __('Voided') : __('Posted') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">{{ __('No payments match the selected filters.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@else
    <table>
        <thead>
            <tr>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Counterparty') }}</th>
                <th>{{ __('Approval') }}</th>
                <th>{{ __('Accounting') }}</th>
                <th>{{ __('Payment') }}</th>
                <th>{{ __('Period Finalization') }}</th>
                <th class="right">{{ __('Total') }}</th>
                <th class="right">{{ __('Open') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $invoice)
                @php
                    $counterparty = $invoice->expenseProfile?->channel === 'petty_cash'
                        ? ($invoice->expenseProfile?->wallet?->driver_name ?: $invoice->expenseProfile?->wallet?->driver_id ?: '—')
                        : ($invoice->supplier?->name ?: '—');
                @endphp
                <tr>
                    <td><strong>{{ $invoice->invoice_number }}</strong>@if($invoice->reference_number)<br><span class="muted">{{ __('Ref') }}: {{ $invoice->reference_number }}</span>@endif</td>
                    <td>{{ $invoice->invoice_date?->format('Y-m-d') ?: '—' }}</td>
                    <td>{{ $invoice->documentTypeLabel() }}</td>
                    <td>{{ $counterparty }}</td>
                    <td>{{ str($invoice->approvalStatusLabel())->replace('_', ' ')->headline() }}</td>
                    <td>{{ str($invoice->workflowStateLabel())->replace('_', ' ')->headline() }}</td>
                    <td>{{ str($invoice->paymentStateLabel())->replace('_', ' ')->headline() }}</td>
                    <td>{{ $invoice->periodFinalizationLabel() }}</td>
                    <td class="right">{{ number_format((float) $invoice->total_amount, 2) }}</td>
                    <td class="right">{{ number_format((float) $invoice->total_amount - (float) $invoice->paid_sum, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">{{ __('No AP documents match the selected filters.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endif
</body>
</html>
