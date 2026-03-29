<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 8mm 10mm 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: Arial, Helvetica, sans-serif; }
        .no-print { display: inline-flex; gap: 8px; margin: 12px 10mm; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #bfbfbf; border-radius: 6px; background: #fff; color: #111; text-decoration: none; font-size: 12px; cursor: pointer; }
        .btn:hover { background: #f3f3f3; }

        .sheet { width: 190mm; margin: 0 auto; min-height: 276mm; position: relative; padding-bottom: 10mm; }
        .header { display: grid; grid-template-columns: 30mm 1fr 30mm; align-items: start; margin-top: 3mm; }
        .logo-wrap { text-align: left; }
        .logo { width: 24mm; height: 24mm; object-fit: contain; }
        .logo-label { margin-top: 1.5mm; font-size: 9px; font-style: italic; }
        .company { text-align: center; }
        .company-title { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.3px; }
        .company-subtitle { margin-top: 1.2mm; font-size: 12px; }
        .doc-title { margin-top: 3.5mm; text-align: center; font-size: 15px; font-weight: 700; text-decoration: underline; }

        .meta { display: grid; grid-template-columns: 1fr 68mm; column-gap: 10mm; margin-top: 7mm; font-size: 11px; }
        .supplier p { margin: 0 0 1.6mm; }
        .supplier .name { font-weight: 700; text-transform: uppercase; letter-spacing: 0.2px; }
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { padding: 1.4mm 0; vertical-align: top; }
        .meta-table .label { width: 26mm; font-size: 12px; }
        .meta-table .sep { width: 4mm; text-align: center; font-weight: 700; }
        .meta-table .value { font-size: 11px; font-weight: 700; }

        .items { width: 100%; border-collapse: collapse; margin-top: 10mm; font-size: 11px; table-layout: fixed; border-top: 1px solid #444; border-left: 1px solid #444; border-right: 1px solid #444; }
        .items th, .items td { border: 1px solid #444; padding: 2.2mm 1.5mm; }
        .items th { font-weight: 700; text-align: center; }
        .items td { vertical-align: top; }
        .items .c { text-align: center; }
        .items .r { text-align: right; }
        .items .desc { font-weight: 600; }
        .items .no-bottom td { border-bottom: 0; }
        .items .spacer td { border-top: 0; border-bottom: 0; padding: 0; }

        .remarks-wrap {
            border-top: 1px solid #444;
            border-left: 1px solid #444;
            border-right: 1px solid #444;
            border-bottom: 1px solid #444;
            display: grid;
            grid-template-columns: 1fr 44mm;
            min-height: 36mm;
        }
        .remarks { padding: 3mm 4mm; font-size: 11px; }
        .remarks h4 { margin: 0 0 2mm; font-size: 13px; font-weight: 700; }
        .remark-row { display: grid; grid-template-columns: 30mm 4mm 1fr; margin-bottom: 1.2mm; }
        .remark-row .label { font-weight: 400; }
        .remark-row .value { font-weight: 500; }
        .sub-total {
            padding: 3mm 4mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
        }

        .amount-line {
            border-bottom: 1px solid #444;
            padding: 2mm 0;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            gap: 10mm;
        }

        .signatures {
            margin-top: 8mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24mm;
            font-size: 11px;
        }
        .sign-line { border-top: 1px solid #444; margin-bottom: 2.2mm; height: 1px; }
        .sign-label { font-weight: 700; }
        .sign-name { margin-top: 1.4mm; font-size: 10px; }
        .footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            border-top: 1px solid #444;
            padding-top: 2mm;
            font-size: 10px;
        }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $supplier = $purchaseOrder->supplier;
    $items = $purchaseOrder->items;
    $targetRows = 14;
    $rowHeightMm = 7;
    $missingRows = max($targetRows - $items->count(), 0);
    $hasSpacer = $missingRows > 0;
    $total = (float) $purchaseOrder->total_amount;
    $creatorName = $purchaseOrder->creator->name
        ?? $purchaseOrder->creator->username
        ?? $purchaseOrder->creator->email
        ?? '-';

    $defaultAddress = 'P.O. BOX : , AL MAADID STREET, ZONE 56 - STREET 980 - BUILDING NO. 238, DOHA - QATAR';
    $deliveryAddress = trim((string) ($supplier->address ?? '')) ?: $defaultAddress;

    $numberToWords = function (int $number) use (&$numberToWords): string {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
        $teens = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number === 0) {
            return 'Zero';
        }

        $words = '';
        if ($number >= 1000000) {
            $words .= $numberToWords(intdiv($number, 1000000)).' Million ';
            $number %= 1000000;
        }
        if ($number >= 1000) {
            $words .= $numberToWords(intdiv($number, 1000)).' Thousand ';
            $number %= 1000;
        }
        if ($number >= 100) {
            $words .= $numberToWords(intdiv($number, 100)).' Hundred ';
            $number %= 100;
        }
        if ($number >= 20) {
            $words .= $tens[intdiv($number, 10)].' ';
            $number %= 10;
        }
        if ($number >= 10) {
            $words .= $teens[$number - 10].' ';
            $number = 0;
        }
        if ($number > 0) {
            $words .= $ones[$number].' ';
        }

        return trim($words);
    };

    $whole = (int) floor($total);
    $fraction = (int) round(($total - $whole) * 100);
    if ($fraction === 100) {
        $whole += 1;
        $fraction = 0;
    }
    $amountWords = $numberToWords($whole).' And '.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).'/100 Only.';
@endphp

<div class="no-print">
    <button class="btn" onclick="window.print()">Print</button>
    <a class="btn" href="{{ route('purchase-orders.show', $purchaseOrder) }}">Back to Purchase Order</a>
</div>

<div class="sheet">
    <div class="header">
        <div class="logo-wrap">
            <img class="logo" src="{{ asset('logo.png') }}" alt="Layla Kitchen Logo">
            <div class="logo-label">Layla Kitchen</div>
        </div>
        <div class="company">
            <h1 class="company-title">LAYLA KITCHEN W.L.L</h1>
            <div class="company-subtitle">MAAMOURA AL MAADID STREET, DOHA, QATAR, Tel : 44413660</div>
            <div class="doc-title">Purchase Order</div>
        </div>
        <div></div>
    </div>

    <div class="meta">
        <div class="supplier">
            <p class="name">M/S {{ $supplier->name ?? '-' }}</p>
            <p>{{ $supplier->address ?? 'Doha, Doha, Qatar' }}</p>
            <p>Tel: {{ $supplier->phone ?? '-' }}</p>
            <p>Email: {{ $supplier->email ?? '-' }}</p>
            <p><strong>Attn</strong> {{ $supplier->contact_person ? ': '.$supplier->contact_person : ': -' }}</p>
        </div>
        <table class="meta-table">
            <tr>
                <td class="label">No</td>
                <td class="sep">:</td>
                <td class="value">{{ $purchaseOrder->po_number ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Date</td>
                <td class="sep">:</td>
                <td class="value">{{ $purchaseOrder->order_date?->format('d-M-Y') ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Reference</td>
                <td class="sep">:</td>
                <td class="value">{{ $purchaseOrder->notes ? \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim((string) $purchaseOrder->notes)), 35, '') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Project No</td>
                <td class="sep">:</td>
                <td class="value">-</td>
            </tr>
        </table>
    </div>

    <table class="items">
        <colgroup>
            <col style="width: 5%;">
            <col style="width: 13%;">
            <col style="width: 45%;">
            <col style="width: 6%;">
            <col style="width: 7%;">
            <col style="width: 12%;">
            <col style="width: 12%;">
        </colgroup>
        <thead>
            <tr>
                <th>S.NO</th>
                <th>PART #</th>
                <th>DESCRIPTIONS</th>
                <th>UOM</th>
                <th>QTY</th>
                <th>UNIT PRICE (QAR)</th>
                <th>TOTAL (QAR)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $index => $line)
                <tr class="{{ $hasSpacer && $loop->last ? 'no-bottom' : '' }}">
                    <td class="c">{{ $index + 1 }}</td>
                    <td>{{ $line->item?->item_code ?? '' }}</td>
                    <td class="desc">
                        <div>{{ $line->item?->name ?? '' }}</div>
                        @if (filled($line->line_notes))
                            <div style="margin-top: 2mm; font-size: 10px; font-weight: 400; white-space: pre-wrap;">{{ $line->line_notes }}</div>
                        @endif
                    </td>
                    <td class="c">{{ $line->item?->unit_of_measure ?? 'EA' }}</td>
                    <td class="r">{{ number_format((float) $line->quantity, 2) }}</td>
                    <td class="r">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="r">{{ number_format((float) $line->total_price, 2) }}</td>
                </tr>
            @empty
                <tr class="{{ $hasSpacer ? 'no-bottom' : '' }}">
                    <td class="c" colspan="7">No items.</td>
                </tr>
            @endforelse
            @if ($hasSpacer)
                <tr class="spacer">
                    <td style="height: {{ $missingRows * $rowHeightMm }}mm;"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="remarks-wrap">
        <div class="remarks">
            <h4>Remarks:</h4>
            <div class="remark-row">
                <div class="label">Delivery Term</div>
                <div>:</div>
                <div class="value">-</div>
            </div>
            <div class="remark-row">
                <div class="label">Delivery Period</div>
                <div>:</div>
                <div class="value">{{ $purchaseOrder->expected_delivery_date?->format('d-M-Y') ?? '-' }}</div>
            </div>
            <div class="remark-row">
                <div class="label">Payment Term</div>
                <div>:</div>
                <div class="value">{{ $purchaseOrder->payment_terms ?? '-' }}</div>
            </div>
            <div class="remark-row">
                <div class="label">Delivery Address</div>
                <div>:</div>
                <div class="value">{{ $deliveryAddress }}</div>
            </div>
        </div>
        <div class="sub-total">
            <span>Sub Total</span>
            <span>{{ number_format($total, 2) }}</span>
        </div>
    </div>

    <div class="amount-line">
        <span>QAR : {{ $amountWords }}</span>
        <span>{{ number_format($total, 2) }}</span>
    </div>

    <div class="signatures">
        <div>
            <div class="sign-line"></div>
            <div class="sign-label">Prepared By</div>
            <div class="sign-name">{{ $creatorName }}</div>
        </div>
        <div>
            <div class="sign-line"></div>
            <div class="sign-label">Approved By</div>
            <div class="sign-name">-</div>
        </div>
    </div>

    <div class="footer">CAPITAL. QAR: 200,000.00</div>
</div>
</body>
</html>
