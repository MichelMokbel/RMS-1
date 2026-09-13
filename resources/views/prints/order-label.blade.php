<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: {{ $pageWidthMm }}mm {{ $pageHeightMm }}mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: DejaVu Sans, sans-serif; }
        .label { position: relative; width: {{ $pageWidthMm }}mm; height: {{ $pageHeightMm }}mm; padding: 1.5mm; page-break-after: always; overflow: hidden; }
        .label:last-child { page-break-after: avoid; }
        .heading { position: relative; min-height: 10mm; padding-right: 11mm; border-bottom: .3mm solid #000; }
        .brand { font-size: 5.5pt; font-weight: 700; line-height: 1; text-transform: uppercase; letter-spacing: .35pt; }
        .order { margin-top: .4mm; overflow: hidden; font-size: 12pt; font-weight: 800; line-height: 1; white-space: nowrap; }
        .service { margin-top: .7mm; font-size: 6.5pt; font-weight: 700; line-height: 1; }
        .qr { position: absolute; top: 0; right: 0; width: 9.5mm; height: 9.5mm; }
        .customer { margin-top: .7mm; overflow: hidden; font-size: 9pt; font-weight: 800; line-height: 1.05; white-space: nowrap; }
        .destination { margin-top: .35mm; max-height: 4mm; overflow: hidden; font-size: 6.5pt; line-height: 1.05; }
        .items { max-height: 14mm; margin: .7mm 0 0; padding: 0; overflow: hidden; list-style: none; }
        .items li { overflow: hidden; border-top: .15mm solid #888; padding: .35mm 0; font-size: 6.7pt; font-weight: 700; line-height: 1.05; white-space: nowrap; }
        .qty { display: inline-block; min-width: 5.5mm; font-size: 7.4pt; font-weight: 800; }
        .copy { position: absolute; right: 12mm; bottom: .4mm; font-size: 5.5pt; font-weight: 700; }
    </style>
</head>
<body>
@for ($copy = 1; $copy <= $copies; $copy++)
    <section class="label">
        <div class="heading">
            <div class="brand">{{ $snapshot['brand'] }}</div>
            <div class="order">{{ $snapshot['order_number'] }}</div>
            <div class="service">
                {{ $snapshot['service_date'] ?: 'No date' }}
                @if ($snapshot['service_time']) · {{ $snapshot['service_time'] }} @endif
            </div>
            <img class="qr" src="{{ $qrDataUri }}" alt="">
        </div>
        <div class="customer">{{ $snapshot['customer_name'] }}</div>
        <div class="destination">{{ $snapshot['destination'] }}</div>
        <ul class="items">
            @foreach ($snapshot['items'] as $item)
                <li><span class="qty">{{ $item['quantity'] }}×</span>{{ $item['description'] }}</li>
            @endforeach
        </ul>
        @if ($copies > 1)<div class="copy">Copy {{ $copy }} of {{ $copies }}</div>@endif
    </section>
@endfor
</body>
</html>
