<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pastry Order {{ $order->order_number }}</title>
    <style>
        :root { color-scheme: light; }

        @page { size: A4 landscape; margin: 12mm; }

        body { font-family: Arial, sans-serif; color: #111827; margin: 0; }

        /* Landscape two-column shell */
        .sheet {
            display: grid;
            grid-template-columns: 100mm 1fr;
            gap: 10mm;
            height: calc(210mm - 24mm); /* A4 landscape height minus margins */
            align-items: start;
        }

        /* ── Left column: images ── */
        .col-image {
            display: flex;
            flex-direction: column;
            gap: 4mm;
        }
        .image-wrap {
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .image-wrap img {
            max-width: 100%;
            height: auto;
            display: block;
        }
        .no-image {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        .no-image-initials { font-size: 56px; font-weight: 800; color: #d1d5db; }

        /* ── Right column: info ── */
        .col-info { display: flex; flex-direction: column; gap: 5mm; }

        /* Header */
        .order-number { font-size: 26px; font-weight: 800; margin: 0 0 1mm; line-height: 1.1; }
        .scheduled    { font-size: 15px; font-weight: 600; color: #374151; margin: 0; }
        .status-badge { display: inline-block; margin-top: 2mm; padding: 2px 10px; border: 1.5px solid #d1d5db; border-radius: 999px; font-size: 12px; font-weight: 600; }

        /* Items */
        .items-section h2 { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin: 0 0 3px; }
        .item-row { display: flex; align-items: baseline; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #e5e7eb; }
        .item-row:last-child { border-bottom: none; }
        .item-name { font-size: 18px; font-weight: 700; }
        .item-qty  { font-size: 16px; font-weight: 700; color: #374151; white-space: nowrap; margin-left: 6mm; }

        /* Notes */
        .notes-section { border: 2px solid #f59e0b; border-radius: 8px; padding: 5px 9px; background: #fffbeb; }
        .notes-section h2 { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #b45309; margin: 0 0 2px; }
        .notes-text { font-size: 14px; font-weight: 600; color: #111827; white-space: pre-wrap; margin: 0; }

        /* Handoff */
        .handoff { border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 10px; }
        .handoff h2 { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin: 0 0 3px; }
        .handoff-name { font-size: 16px; font-weight: 700; margin: 0; }
        .handoff-type { display: inline-block; margin-top: 3px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #e5e7eb; color: #374151; }

        /* Tools (screen only) */
        .tools { margin-bottom: 8px; }
        .btn { display: inline-block; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; color: #111827; text-decoration: none; font-size: 12px; background: #fff; cursor: pointer; }
        @media print { .tools { display: none !important; } }
    </style>
</head>
<body>
    <div class="tools">
        <button class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="sheet">

        {{-- Left: images --}}
        <div class="col-image">
            @php
                $imgList   = array_values(array_filter($images ?? [], fn ($i) => !empty($i['url'])));
                $imgCount  = count($imgList);
                // A4 landscape minus margins = 186mm; distribute evenly with 4mm gaps
                $maxImgMm  = $imgCount > 0 ? floor((186 - ($imgCount - 1) * 4) / $imgCount) : 155;
            @endphp
            @if ($imgCount > 0)
                @foreach ($imgList as $img)
                    <div class="image-wrap">
                        <img src="{{ $img['url'] }}" alt="Order image"
                             style="max-height: {{ $maxImgMm }}mm;" />
                    </div>
                @endforeach
            @else
                @php
                    $pParts    = preg_split('/\s+/', trim($order->customer_name_snapshot ?? '?'));
                    $pInitials = strtoupper(mb_substr($pParts[0], 0, 1) . (count($pParts) > 1 ? mb_substr(end($pParts), 0, 1) : ''));
                @endphp
                <div class="image-wrap" style="min-height: 80mm;">
                    <div class="no-image">
                        <span class="no-image-initials">{{ $pInitials }}</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: order info --}}
        <div class="col-info">

            {{-- Header --}}
            <div>
                <p class="order-number">{{ $order->order_number }}</p>
                @if ($order->sales_order_number)
                    <p style="font-size:13px;color:#6b7280;margin:0 0 1mm;">{{ __('Sales Order #') }}: {{ $order->sales_order_number }}</p>
                @endif
                <p class="scheduled">{{ $order->scheduled_date?->format('D, d M Y') ?? '—' }}{{ $order->scheduled_time ? ' · ' . $order->scheduled_time : '' }}</p>
                <span class="status-badge">{{ $order->status ?? '—' }}</span>
            </div>

            {{-- Items --}}
            <div class="items-section">
                <h2>{{ __('Items') }}</h2>
                @foreach ($order->items as $item)
                    <div class="item-row">
                        <span class="item-name">{{ $item->description_snapshot }}</span>
                        <span class="item-qty">× {{ number_format((float) $item->quantity, 3) }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Notes --}}
            @if ($order->notes)
                <div class="notes-section">
                    <h2>{{ __('Notes') }}</h2>
                    <p class="notes-text">{{ $order->notes }}</p>
                </div>
            @endif

            {{-- Customer handoff --}}
            <div class="handoff">
                <h2>{{ __('Handoff') }}</h2>
                <p class="handoff-name">{{ $order->customer_name_snapshot ?? '—' }}</p>
                <span class="handoff-type">{{ $order->type ?? '—' }}</span>
            </div>

        </div>
    </div>
</body>
</html>
