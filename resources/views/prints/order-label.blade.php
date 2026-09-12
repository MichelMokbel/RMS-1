<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: {{ $pageWidthMm }}mm {{ $pageHeightMm }}mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: DejaVu Sans, sans-serif; }
        .label { position: relative; width: {{ $pageWidthMm }}mm; height: {{ $pageHeightMm }}mm; padding: 2.4mm; page-break-after: always; overflow: hidden; }
        .label:last-child { page-break-after: avoid; }
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
    </style>
</head>
<body>
@for ($copy = 1; $copy <= $copies; $copy++)
    <section class="label">
        <div class="brand">{{ $snapshot['brand'] }}</div>
        <div class="order">{{ $snapshot['order_number'] }}</div>
        <div class="service">
            {{ $snapshot['service_date'] ?: 'No date' }}
            @if ($snapshot['service_time']) · {{ $snapshot['service_time'] }} @endif
        </div>
        <div class="customer">{{ $snapshot['customer_name'] }}</div>
        <div class="destination">{{ $snapshot['destination'] }}</div>
        <ul class="items">
            @foreach ($snapshot['items'] as $item)
                <li><span class="qty">{{ $item['quantity'] }}×</span>{{ $item['description'] }}</li>
            @endforeach
        </ul>
        <div class="footer">
            <div class="reference">
                {{ strtoupper($snapshot['source_type']) }} #{{ $snapshot['source_id'] }} · Print {{ $sequence }}
                @if ($copies > 1)<br>Copy {{ $copy }} of {{ $copies }}@endif
            </div>
            <img class="qr" src="{{ $qrDataUri }}" alt="">
        </div>
    </section>
@endfor
</body>
</html>
