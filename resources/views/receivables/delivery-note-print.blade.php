<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Delivery Note') }} {{ $deliveryNote->delivery_note_number }}</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: Arial, Helvetica, sans-serif; }
        .no-print { display: inline-flex; gap: 8px; margin: 12px 10mm; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #bfbfbf; border-radius: 6px; background: #fff; color: #111; text-decoration: none; font-size: 12px; cursor: pointer; }
        .document { width: 190mm; min-height: 296mm; margin: 0 auto; display: flex; flex-direction: column; }
        .header { display: grid; grid-template-columns: 26mm 1fr 26mm; align-items: center; column-gap: 10mm; margin-top: 6mm; }
        .logo { width: 26mm; height: 26mm; object-fit: contain; justify-self: start; }
        .company { margin: 0; text-align: center; }
        .header-spacer { width: 26mm; height: 1px; }
        .company-title { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.4px; }
        .company-subtitle { margin-top: 2px; font-size: 11px; }
        .document-title { margin: 5mm 0 0; text-align: center; font-size: 16px; font-weight: 700; text-decoration: underline; }
        .info { display: flex; justify-content: space-between; gap: 10mm; margin-top: 6mm; font-size: 11px; }
        .info table { width: 88mm; border-collapse: collapse; }
        .info td { padding: 1.6mm 0; vertical-align: top; }
        .label-en { width: 30mm; font-weight: 600; }
        .label-ar { width: 22mm; text-align: right; font-weight: 600; }
        .label-sep { width: 4mm; text-align: center; font-weight: 600; }
        .value { font-weight: 600; white-space: pre-line; }
        .arabic { font-family: Tahoma, Arial, sans-serif; direction: rtl; }
        .items { width: 100%; margin-top: 6mm; border-collapse: collapse; font-size: 11px; }
        .items th, .items td { border: 1px solid #333; padding: 5px 4px; text-align: left; }
        .items th { font-weight: 700; }
        .center { text-align: center !important; }
        .right { text-align: right !important; }
        .line-note { margin-top: 1.2mm; color: #444; font-size: 10px; white-space: pre-wrap; }
        .notes { margin-top: 5mm; padding: 2.2mm 3mm; font-size: 11px; white-space: pre-line; }
        .bottom-area { margin-top: auto; padding-top: 22mm; font-size: 11px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 50mm; }
        .signature { padding-top: 2mm; border-top: 1px solid #111; text-align: center; font-size: 12px; }
        .footer { display: flex; justify-content: space-between; margin-top: 12mm; padding-top: 2mm; border-top: 1px solid #333; font-size: 10px; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>

<body>
    @php
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;

        $branchName = Schema::hasTable('branches')
            ? DB::table('branches')->where('id', $deliveryNote->branch_id)->value('name')
            : null;
    @endphp

    <div class="no-print">
        <button class="btn" onclick="window.print()">{{ __('Print') }}</button>
        <a class="btn" href="{{ route('delivery-notes.show', $deliveryNote) }}">{{ __('Back to Delivery Note') }}</a>
    </div>

    <div class="document">
        <div class="header">
            <img class="logo" src="{{ asset('logo.png') }}" alt="Layla Kitchen Logo">
            <div class="company">
                <div class="company-title">LAYLA KITCHEN W.L.L</div>
                <div class="company-subtitle">MAAMOURA AL MAADID STREET, DOHA, QATAR</div>
            </div>
            <div class="header-spacer" aria-hidden="true"></div>
        </div>

        <div class="document-title">DELIVERY NOTE</div>

        <div class="info">
            <table>
                <tr>
                    <td class="label-en">Delivery Note No</td>
                    <td class="label-ar arabic">رقم سند التسليم</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->delivery_note_number ?: '#'.$deliveryNote->id }}</td>
                </tr>
                <tr>
                    <td class="label-en">Branch</td>
                    <td class="label-ar arabic">الفرع</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $branchName ?? $deliveryNote->branch_id }}</td>
                </tr>
                <tr>
                    <td class="label-en">Customer Name</td>
                    <td class="label-ar arabic">اسم العميل</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->customer_name_snapshot }}</td>
                </tr>
                <tr>
                    <td class="label-en">Customer ID</td>
                    <td class="label-ar arabic">رقم العميل</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->customer?->customer_code ?? $deliveryNote->customer_id }}</td>
                </tr>
                <tr>
                    <td class="label-en">Phone</td>
                    <td class="label-ar arabic">رقم الهاتف</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->customer?->phone ?? '-' }}</td>
                </tr>
            </table>

            <table>
                <tr>
                    <td class="label-en">Delivery Date</td>
                    <td class="label-ar arabic">تاريخ التسليم</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->delivery_date->format('d-M-Y') }}</td>
                </tr>
                <tr>
                    <td class="label-en">Reference</td>
                    <td class="label-ar arabic">المرجع</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->reference ?: '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Delivery Address</td>
                    <td class="label-ar arabic">عنوان التسليم</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $deliveryNote->delivery_address_snapshot ?: '-' }}</td>
                </tr>
            </table>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th class="center" style="width: 8%;">S.NO<br><span class="arabic">الرقم</span></th>
                    <th style="width: 47%;">DESCRIPTIONS<br><span class="arabic">التفاصيل</span></th>
                    <th class="center" style="width: 15%;">UOM<br><span class="arabic">وحدة</span></th>
                    <th class="right" style="width: 15%;">QTY<br><span class="arabic">كمية</span></th>
                    <th style="width: 15%;">NOTES<br><span class="arabic">ملاحظات</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deliveryNote->items as $index => $item)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td>
                            {{ $item->description }}
                            @if ($item->sku_snapshot)
                                <div class="line-note">{{ $item->sku_snapshot }}</div>
                            @endif
                        </td>
                        <td class="center">{{ $item->unit ?: '-' }}</td>
                        <td class="right">{{ rtrim(rtrim(number_format((float) $item->qty, 3, '.', ''), '0'), '.') }}</td>
                        <td>{{ $item->line_notes }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($deliveryNote->notes)
            <div class="notes"><strong>{{ __('Notes') }}:</strong><br>{{ $deliveryNote->notes }}</div>
        @endif

        <div class="bottom-area">
            <div class="signatures">
                <div class="signature">{{ __('Prepared By') }}</div>
                <div class="signature">{{ __('Received By / Signature') }}</div>
            </div>
            <div class="footer">
                <span>LAYLA KITCHEN W.L.L</span>
                <span>MAAMOURA AL MAADID STREET, DOHA, QATAR</span>
            </div>
        </div>
    </div>
</body>

</html>
