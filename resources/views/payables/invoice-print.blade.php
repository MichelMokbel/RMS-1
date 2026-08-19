<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->documentTypeLabel() }} {{ $invoice->invoice_number }}</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; background: #fff; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .tools { width: 190mm; margin: 0 auto 8mm; display: flex; gap: 8px; }
        .button { border: 1px solid #374151; background: #fff; border-radius: 4px; padding: 8px 16px; cursor: pointer; }
        .document { position: relative; width: 190mm; min-height: 267mm; margin: 0 auto; border: 1px solid #9ca3af; padding: 10mm; }
        .void { position: absolute; inset: 36% 0 auto; z-index: 2; text-align: center; color: rgba(185, 28, 28, .18); font-size: 84px; font-weight: 800; transform: rotate(-20deg); pointer-events: none; }
        .header { display: grid; grid-template-columns: 28mm 1fr 55mm; align-items: start; gap: 5mm; border-bottom: 2px solid #111827; padding-bottom: 5mm; }
        .logo { width: 24mm; height: 24mm; object-fit: contain; }
        .company { text-align: center; }
        .company h1 { margin: 0; font-size: 20px; }
        .company p { margin: 2mm 0 0; color: #4b5563; font-size: 10px; white-space: pre-line; }
        .document-meta { text-align: right; line-height: 1.65; }
        .title { margin: 7mm 0 5mm; text-align: center; font-size: 18px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .status { display: inline-block; border: 1px solid currentColor; border-radius: 999px; padding: 1px 7px; font-size: 10px; font-weight: 700; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 3mm 10mm; margin-bottom: 6mm; }
        .field { display: grid; grid-template-columns: 35mm 1fr; min-height: 8mm; border-bottom: 1px dotted #9ca3af; padding: 2mm 0; }
        .label { color: #4b5563; font-weight: 700; }
        .value { font-weight: 600; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 2.5mm; text-align: left; }
        th { background: #f3f4f6; font-size: 10px; text-transform: uppercase; }
        .right { text-align: right; }
        .summary { width: 75mm; margin: 5mm 0 0 auto; }
        .summary td:first-child { font-weight: 700; }
        .notes { min-height: 18mm; margin-top: 6mm; border: 1px solid #9ca3af; padding: 3mm; white-space: pre-line; }
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12mm; margin-top: 22mm; }
        .signature { border-top: 1px solid #111827; padding-top: 2mm; text-align: center; }
        .muted { color: #6b7280; }
        @media print {
            .tools { display: none !important; }
            .document { width: 100%; min-height: 277mm; border: 0; padding: 5mm; }
        }
    </style>
</head>
<body>
@php
    $companyName = $profile?->legal_name_en ?: $invoice->company?->name ?: config('app.name');
    $companyAddress = $profile?->address_en;
    $currency = strtoupper($invoice->currency_code ?: $invoice->company?->base_currency ?: config('pos.currency', 'QAR'));
    $counterparty = $invoice->expenseProfile?->channel === 'petty_cash'
        ? ($invoice->expenseProfile?->wallet?->driver_name ?: $invoice->expenseProfile?->wallet?->driver_id ?: '—')
        : ($invoice->supplier?->name ?: '—');
@endphp

<div class="tools">
    <button type="button" class="button" onclick="window.print()">{{ __('Print') }}</button>
    <button type="button" class="button" onclick="window.close()">{{ __('Close') }}</button>
</div>

<main class="document">
    @if($invoice->status === 'void')
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
        <div class="document-meta">
            <strong>{{ $invoice->invoice_number }}</strong><br>
            {{ $invoice->invoice_date?->format('d M Y') ?: '—' }}<br>
            <span class="status">{{ str($invoice->status)->replace('_', ' ')->headline() }}</span>
        </div>
    </header>

    <div class="title">{{ $invoice->documentTypeLabel() }}</div>

    <section class="details">
        <div class="field"><span class="label">{{ __('Counterparty') }}</span><span class="value">{{ $counterparty }}</span></div>
        <div class="field"><span class="label">{{ __('Reference') }}</span><span class="value">{{ $invoice->reference_number ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Invoice Date') }}</span><span class="value">{{ $invoice->invoice_date?->format('d M Y') ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Due Date') }}</span><span class="value">{{ $invoice->due_date?->format('d M Y') ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Category') }}</span><span class="value">{{ $invoice->category?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Period') }}</span><span class="value">{{ $invoice->period?->name ?: $invoice->periodFinalizationLabel() }}</span></div>
        <div class="field"><span class="label">{{ __('Department') }}</span><span class="value">{{ $invoice->department?->name ?: '—' }}</span></div>
        <div class="field"><span class="label">{{ __('Job') }}</span><span class="value">{{ $invoice->job ? trim($invoice->job->code.' '.$invoice->job->name) : '—' }}</span></div>
    </section>

    <table>
        <thead>
            <tr>
                <th style="width: 12mm">#</th>
                <th>{{ __('Description') }}</th>
                <th class="right" style="width: 25mm">{{ __('Quantity') }}</th>
                <th class="right" style="width: 34mm">{{ __('Unit Price') }}</th>
                <th class="right" style="width: 34mm">{{ __('Line Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float) $item->unit_price, 4) }}</td>
                    <td class="right">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">{{ __('No line items.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td>{{ __('Subtotal') }}</td><td class="right">{{ number_format((float) $invoice->subtotal, 2) }} {{ $currency }}</td></tr>
        @if((float) $invoice->tax_amount !== 0.0)
            <tr><td>{{ __('Tax') }}</td><td class="right">{{ number_format((float) $invoice->tax_amount, 2) }} {{ $currency }}</td></tr>
        @endif
        <tr><td>{{ __('Total') }}</td><td class="right"><strong>{{ number_format((float) $invoice->total_amount, 2) }} {{ $currency }}</strong></td></tr>
        <tr><td>{{ __('Paid') }}</td><td class="right">{{ number_format((float) $invoice->paidAmount(), 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('Outstanding') }}</td><td class="right">{{ number_format((float) $invoice->outstandingAmount(), 2) }} {{ $currency }}</td></tr>
    </table>

    <div class="notes"><strong>{{ __('Notes') }}:</strong><br>{{ $invoice->notes ?: '—' }}</div>

    <section class="signatures">
        <div class="signature">{{ __('Prepared By') }}<br><span class="muted">{{ $invoice->createdBy?->name ?: '—' }}</span></div>
        <div class="signature">{{ __('Approved By') }}</div>
        <div class="signature">{{ __('Received By') }}<br><span class="muted">{{ $counterparty }}</span></div>
    </section>
</main>
</body>
</html>
