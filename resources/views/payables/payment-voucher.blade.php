<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Payment Voucher') }} {{ $payment->voucherNumber() }}</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; font-family: Arial, Helvetica, sans-serif; font-size: 12px; background: #fff; }
        .tools { width: 190mm; margin: 0 auto 8mm; display: flex; gap: 8px; }
        .button { border: 1px solid #374151; background: white; border-radius: 4px; padding: 8px 16px; cursor: pointer; }
        .voucher { position: relative; width: 190mm; min-height: 267mm; margin: 0 auto; border: 1px solid #9ca3af; padding: 10mm; }
        .void { position: absolute; inset: 36% 0 auto; z-index: 2; text-align: center; color: rgba(185, 28, 28, .18); font-size: 84px; font-weight: 800; transform: rotate(-20deg); pointer-events: none; }
        .header { display: grid; grid-template-columns: 30mm 1fr 45mm; align-items: start; gap: 5mm; border-bottom: 2px solid #111827; padding-bottom: 5mm; }
        .logo { width: 24mm; height: 24mm; object-fit: contain; }
        .company { text-align: center; }
        .company h1 { margin: 0; font-size: 20px; }
        .company p { margin: 2mm 0 0; color: #4b5563; font-size: 10px; white-space: pre-line; }
        .voucher-meta { text-align: right; line-height: 1.65; }
        .title { margin: 7mm 0 5mm; text-align: center; font-size: 18px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .status { display: inline-block; margin-left: 4px; border: 1px solid currentColor; border-radius: 999px; padding: 1px 7px; font-size: 10px; font-weight: 700; }
        .status.voided { color: #b91c1c; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 3mm 10mm; margin-bottom: 6mm; }
        .field { display: grid; grid-template-columns: 35mm 1fr; min-height: 8mm; border-bottom: 1px dotted #9ca3af; padding: 2mm 0; }
        .label { color: #4b5563; font-weight: 700; }
        .value { font-weight: 600; overflow-wrap: anywhere; }
        .amount-box { display: grid; grid-template-columns: 1fr 55mm; margin: 6mm 0; border: 2px solid #111827; }
        .amount-words { padding: 4mm; text-transform: capitalize; }
        .amount { padding: 4mm; border-left: 2px solid #111827; text-align: right; font-size: 18px; font-weight: 800; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 2.5mm; text-align: left; }
        th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .notes { min-height: 20mm; margin-top: 6mm; border: 1px solid #9ca3af; padding: 3mm; white-space: pre-line; }
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12mm; margin-top: 22mm; }
        .signature { border-top: 1px solid #111827; padding-top: 2mm; text-align: center; }
        .audit { position: absolute; right: 10mm; bottom: 7mm; left: 10mm; display: flex; justify-content: space-between; border-top: 1px solid #d1d5db; padding-top: 2mm; color: #6b7280; font-size: 9px; }
        @media print {
            .tools { display: none !important; }
            .voucher { border: 0; width: 100%; min-height: 277mm; padding: 5mm; }
            .audit { right: 5mm; left: 5mm; }
        }
    </style>
</head>
<body>
@php
    $companyName = $profile?->legal_name_en ?: $payment->company?->name ?: config('app.name');
    $companyAddress = $profile?->address_en;
    $allocations = $payment->allAllocations->sortBy('id')->values();
    $activeAllocated = $allocations->whereNull('voided_at')->sum('allocated_amount');
    $unallocated = (float) $payment->amount - (float) $activeAllocated;
@endphp

<div class="tools">
    <button type="button" class="button" onclick="window.print()">{{ __('Print') }}</button>
    <button type="button" class="button" onclick="window.close()">{{ __('Close') }}</button>
</div>

<main class="voucher">
    @if($payment->voided_at)
        <div class="void">VOID</div>
    @endif

    <header class="header">
        <img class="logo" src="{{ asset('logo.png') }}" alt="{{ $companyName }}">
        <div class="company">
            <h1>{{ $companyName }}</h1>
            @if($companyAddress)<p>{{ $companyAddress }}</p>@endif
            @if($profile?->phone || $profile?->email)
                <p>{{ collect([$profile?->phone, $profile?->email])->filter()->implode(' · ') }}</p>
            @endif
        </div>
        <div class="voucher-meta">
            <strong>{{ $payment->voucherNumber() }}</strong><br>
            {{ $payment->payment_date?->format('d M Y') ?: '—' }}<br>
            @if($payment->voided_at)
                <span class="status voided">{{ __('Voided') }}</span>
            @else
                <span class="status">{{ __('Posted') }}</span>
            @endif
        </div>
    </header>

    <div class="title">{{ __('Payment Voucher') }}</div>

    <section class="details">
        <div class="field"><span class="label">{{ __('Payee') }}</span><span class="value">{{ $payment->supplier?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Supplier ID') }}</span><span class="value">{{ $payment->supplier_id ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Payment Method') }}</span><span class="value">{{ str($payment->payment_method ?: '—')->replace('_', ' ')->headline() }}</span></div>
        <div class="field"><span class="label">{{ __('Reference') }}</span><span class="value">{{ $payment->reference ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Bank Account') }}</span><span class="value">{{ $payment->bankAccount?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Branch') }}</span><span class="value">{{ $payment->branch?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Department') }}</span><span class="value">{{ $payment->department?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Job') }}</span><span class="value">{{ $payment->job ? trim($payment->job->code.' '.$payment->job->name) : '—' }}</span></div>
    </section>

    <section class="amount-box">
        <div class="amount-words"><strong>{{ __('Amount in words') }}:</strong><br>{{ $amountInWords }}</div>
        <div class="amount">{{ number_format((float) $payment->amount, 2) }} {{ strtoupper($payment->currency_code ?: $payment->company?->base_currency ?: config('pos.currency', 'QAR')) }}</div>
    </section>

    <table>
        <thead>
            <tr>
                <th>{{ __('Invoice Number') }}</th>
                <th>{{ __('Invoice Date') }}</th>
                <th>{{ __('Supplier Reference') }}</th>
                <th class="right">{{ __('Allocated Amount') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($allocations as $allocation)
                <tr>
                    <td>{{ $allocation->invoice?->invoice_number ?: $allocation->invoice_id }}</td>
                    <td>{{ $allocation->invoice?->invoice_date?->format('d M Y') ?: '—' }}</td>
                    <td>{{ $allocation->invoice?->reference_number ?: '—' }}</td>
                    <td class="right">{{ number_format((float) $allocation->allocated_amount, 2) }}</td>
                    <td>{{ $allocation->voided_at ? __('Voided') : __('Applied') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">{{ __('This payment has no invoice allocations.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="right">{{ __('Active allocated') }}</th>
                <th class="right">{{ number_format((float) $activeAllocated, 2) }}</th>
                <th></th>
            </tr>
            @if(abs($unallocated) >= 0.005)
                <tr>
                    <th colspan="3" class="right">{{ __('Unallocated') }}</th>
                    <th class="right">{{ number_format($unallocated, 2) }}</th>
                    <th></th>
                </tr>
            @endif
        </tfoot>
    </table>

    <div class="notes"><strong>{{ __('Notes') }}:</strong><br>{{ $payment->notes ?: '—' }}</div>

    <section class="signatures">
        <div class="signature">{{ __('Prepared By') }}<br><span class="muted">{{ $payment->createdBy?->name ?: $payment->createdBy?->username ?: '—' }}</span></div>
        <div class="signature">{{ __('Authorized By') }}<br><span class="muted">{{ $payment->postedBy?->name ?: $payment->postedBy?->username ?: '—' }}</span></div>
        <div class="signature">{{ __('Received By') }}<br><span class="muted">{{ $payment->supplier?->name ?: '—' }}</span></div>
    </section>

    <footer class="audit">
        <span>{{ __('System payment ID') }}: {{ $payment->id }}</span>
        <span>{{ __('Printed') }}: {{ now()->format('d M Y H:i') }}</span>
    </footer>
</main>
</body>
</html>
