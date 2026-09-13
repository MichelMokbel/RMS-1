<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print order labels') }}</title>
    <style>
        @page order-label-landscape { margin: 0; size: {{ $page_width_mm }}mm {{ $page_height_mm }}mm; }
        * { box-sizing: border-box; }
        html, body { width: {{ $page_width_mm }}mm; margin: 0; padding: 0; color: #000; background: #fff; font-family: Arial, sans-serif; }
        .label { page: order-label-landscape; position: relative; width: {{ $page_width_mm }}mm; height: {{ $page_height_mm }}mm; padding: 1.5mm; break-after: page; page-break-after: always; overflow: hidden; }
        .label:last-of-type { break-after: auto; page-break-after: auto; }
        .heading { position: relative; min-height: 10mm; padding-right: 11mm; border-bottom: .3mm solid #000; }
        .brand { font-size: 5.5pt; font-weight: 700; line-height: 1; text-transform: uppercase; letter-spacing: .35pt; }
        .order { margin-top: .4mm; overflow: hidden; font-size: 12pt; font-weight: 800; line-height: 1; text-overflow: ellipsis; white-space: nowrap; }
        .service { margin-top: .7mm; font-size: 6.5pt; font-weight: 700; line-height: 1; }
        .qr { position: absolute; top: 0; right: 0; width: 9.5mm; height: 9.5mm; }
        .customer { margin-top: .7mm; overflow: hidden; font-size: 9pt; font-weight: 800; line-height: 1.05; text-overflow: ellipsis; white-space: nowrap; }
        .destination { margin-top: .35mm; max-height: 4mm; overflow: hidden; font-size: 6.5pt; line-height: 1.05; }
        .items { max-height: 14mm; margin: .7mm 0 0; padding: 0; overflow: hidden; list-style: none; }
        .items li { overflow: hidden; border-top: .15mm solid #888; padding: .35mm 0; font-size: 6.7pt; font-weight: 700; line-height: 1.05; text-overflow: ellipsis; white-space: nowrap; }
        .qty { display: inline-block; min-width: 5.5mm; font-size: 7.4pt; font-weight: 800; }
        .copy { position: absolute; right: 12mm; bottom: .4mm; font-size: 5.5pt; font-weight: 700; }
        .screen-actions { position: fixed; z-index: 10; right: 16px; bottom: 16px; display: flex; gap: 8px; }
        .screen-actions button { min-height: 44px; border: 0; border-radius: 999px; padding: 0 20px; color: #fff; background: #173878; font: 700 15px Arial, sans-serif; cursor: pointer; box-shadow: 0 8px 24px #0003; }
        @media print { .screen-actions { display: none !important; } }
    </style>
</head>
<body>
@foreach ($labels as $label)
    @for ($copy = 1; $copy <= $label['copies']; $copy++)
        <section class="label">
            <div class="heading">
                <div class="brand">{{ $label['snapshot']['brand'] }}</div>
                <div class="order">{{ $label['snapshot']['order_number'] }}</div>
                <div class="service">
                    {{ $label['snapshot']['service_date'] ?: __('No date') }}
                    @if ($label['snapshot']['service_time']) · {{ $label['snapshot']['service_time'] }} @endif
                </div>
                <img class="qr" src="{{ $label['qr_data_uri'] }}" alt="">
            </div>
            <div class="customer">{{ $label['snapshot']['customer_name'] }}</div>
            <div class="destination">{{ $label['snapshot']['destination'] }}</div>
            <ul class="items">
                @foreach ($label['snapshot']['items'] as $item)
                    <li><span class="qty">{{ $item['quantity'] }}×</span>{{ $item['description'] }}</li>
                @endforeach
            </ul>
            @if ($label['copies'] > 1)<div class="copy">{{ __('Copy :copy of :total', ['copy' => $copy, 'total' => $label['copies']]) }}</div>@endif
        </section>
    @endfor
@endforeach
<div class="screen-actions"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
</body>
</html>
