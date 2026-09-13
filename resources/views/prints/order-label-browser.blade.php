<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print order labels') }}</title>
    <style>
        @page { margin: 0; size: {{ $page_width_mm }}mm {{ $page_height_mm }}mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; color: #000; background: #fff; font-family: Arial, sans-serif; }
        .label { position: relative; width: {{ $page_width_mm }}mm; height: {{ $page_height_mm }}mm; padding: 2.4mm; break-after: page; page-break-after: always; overflow: hidden; }
        .label:last-of-type { break-after: auto; page-break-after: auto; }
        .brand { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .4pt; }
        .order { margin-top: .6mm; font-size: 15pt; font-weight: 800; line-height: 1.05; }
        .service { margin-top: .8mm; border-bottom: .3mm solid #000; padding-bottom: 1mm; font-size: 8.5pt; font-weight: 700; }
        .customer { margin-top: 1mm; font-size: 11pt; font-weight: 800; line-height: 1.1; }
        .destination { margin-top: .7mm; font-size: 7.5pt; line-height: 1.2; }
        .items { margin: 1.2mm 0 0; padding: 0; list-style: none; }
        .items li { border-top: .15mm solid #777; padding: .65mm 0; font-size: 8pt; font-weight: 700; line-height: 1.15; }
        .qty { display: inline-block; min-width: 7mm; font-size: 9.5pt; font-weight: 800; }
        .footer { position: absolute; right: 2.4mm; bottom: 2.2mm; left: 2.4mm; height: 12mm; }
        .qr { position: absolute; right: 0; bottom: 0; width: 11mm; height: 11mm; }
        .reference { position: absolute; right: 12mm; bottom: .5mm; left: 0; font-size: 6pt; line-height: 1.25; }
        .screen-actions { position: fixed; z-index: 10; right: 16px; bottom: 16px; display: flex; gap: 8px; }
        .screen-actions button { min-height: 44px; border: 0; border-radius: 999px; padding: 0 20px; color: #fff; background: #173878; font: 700 15px Arial, sans-serif; cursor: pointer; box-shadow: 0 8px 24px #0003; }
        @media print { .screen-actions { display: none !important; } }
    </style>
</head>
<body>
@foreach ($labels as $label)
    @for ($copy = 1; $copy <= $label['copies']; $copy++)
        <section class="label">
            <div class="brand">{{ $label['snapshot']['brand'] }}</div>
            <div class="order">{{ $label['snapshot']['order_number'] }}</div>
            <div class="service">
                {{ $label['snapshot']['service_date'] ?: __('No date') }}
                @if ($label['snapshot']['service_time']) · {{ $label['snapshot']['service_time'] }} @endif
            </div>
            <div class="customer">{{ $label['snapshot']['customer_name'] }}</div>
            <div class="destination">{{ $label['snapshot']['destination'] }}</div>
            <ul class="items">
                @foreach ($label['snapshot']['items'] as $item)
                    <li><span class="qty">{{ $item['quantity'] }}×</span>{{ $item['description'] }}</li>
                @endforeach
            </ul>
            <div class="footer">
                <div class="reference">
                    {{ strtoupper($label['snapshot']['source_type']) }} #{{ $label['snapshot']['source_id'] }}
                    @if ($label['copies'] > 1)<br>{{ __('Copy :copy of :total', ['copy' => $copy, 'total' => $label['copies']]) }}@endif
                </div>
                <img class="qr" src="{{ $label['qr_data_uri'] }}" alt="">
            </div>
        </section>
    @endfor
@endforeach
<div class="screen-actions"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
</body>
</html>
