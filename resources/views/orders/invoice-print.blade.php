<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Invoice</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 8mm 10mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; }
        .no-print { display: inline-flex; gap: 8px; margin: 12px 10mm; }
        .btn { display: inline-block; padding: 7px 12px; border: 1px solid #bfbfbf; border-radius: 6px; background: #fff; color: #111; text-decoration: none; font-size: 12px; cursor: pointer; }
        .btn:hover { background: #f3f3f3; }

        .invoice { width: 190mm; min-height: 270mm; margin: 0 auto; position: relative; page-break-after: always; }
        .invoice:last-child { page-break-after: auto; }

        .header { display: flex; align-items: flex-start; gap: 10mm; margin-top: 6mm; }
        .logo { width: 26mm; height: 26mm; object-fit: contain; }
        .company { flex: 1; text-align: center; margin-right: 26mm; }
        .company-title { font-size: 18px; font-weight: 700; letter-spacing: 0.4px; margin: 0; }
        .company-subtitle { font-size: 11px; margin-top: 2px; }
        .invoice-title { text-align: center; font-size: 16px; font-weight: 700; text-decoration: underline; margin: 5mm 0 0; }

        .info { display: flex; justify-content: space-between; margin-top: 6mm; font-size: 11px; }
        .info table { width: 88mm; border-collapse: collapse; }
        .info td { padding: 1.6mm 0; vertical-align: top; }
        .label-en { width: 30mm; font-weight: 600; }
        .label-ar { width: 22mm; text-align: right; font-weight: 600; }
        .label-sep { width: 4mm; text-align: center; font-weight: 600; }
        .value { font-weight: 600; }
        .arabic { font-family: "Tahoma", Arial, sans-serif; direction: rtl; }

        .items { margin-top: 6mm; width: 100%; border-collapse: collapse; font-size: 11px; }
        .items th, .items td { border: 1px solid #333; padding: 3px 4px; text-align: left; }
        .items th { font-weight: 700; }
        .items .center { text-align: center; }
        .items .right { text-align: right; }
        .items .desc { width: 40%; }
        .items .barcode { width: 18%; }
        .items .uom { width: 10%; }
        .items .qty { width: 8%; }
        .items .price { width: 12%; }
        .items .discount { width: 8%; }
        .items .total { width: 12%; }

        .totals-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 3mm; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 2mm 0; font-size: 11px; }
        .totals-words { flex: 1; }
        .grand-total { display: flex; gap: 6mm; align-items: center; }
        .grand-total .label { font-weight: 700; }
        .grand-total .amount { font-weight: 700; min-width: 24mm; text-align: right; }

        .signatures { margin-top: 20mm; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12mm; font-size: 11px; }
        .signatures .box { text-align: left; }
        .signatures .label { font-weight: 700; }
        .prepared { margin-top: 6mm; text-align: center; font-size: 11px; font-weight: 700; }
        .prepared small { display: block; font-weight: 600; margin-top: 2px; }

        .footer { position: absolute; bottom: 4mm; left: 0; right: 0; display: flex; justify-content: space-between; font-size: 10px; }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $fmt = function ($value): string {
        return number_format((float) ($value ?? 0), 3, '.', '');
    };
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
    $amountToWords = function ($amount) use ($fmt, $numberToWords): string {
        $formatted = $fmt($amount);
        [$whole, $fraction] = array_pad(explode('.', $formatted), 2, '000');
        $wholeWords = $numberToWords((int) $whole);
        $fraction = str_pad($fraction, 3, '0', STR_PAD_RIGHT);

        return $wholeWords.' And '.$fraction.'/1000 Only.';
    };
@endphp

<div class="no-print">
    <button class="btn" onclick="window.print()">Print All</button>
    <a class="btn" href="{{ route('orders.index') }}">Back to Orders</a>
</div>

@forelse ($orders as $order)
    <div class="invoice">
        <div class="header">
            <img class="logo" src="{{ asset('logo.png') }}" alt="Layla Kitchen Logo">
            <div class="company">
                <div class="company-title">LAYLA KITCHEN W.L.L</div>
                <div class="company-subtitle">MAAMOURA AL MAADID STREET, DOHA, QATAR</div>
            </div>
        </div>

        <div class="invoice-title">Sales Invoice</div>

        <div class="info">
            <table>
                <tr>
                    <td class="label-en">Customer Name</td>
                    <td class="label-ar arabic">اسم العميل</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->customer_name_snapshot ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Customer No</td>
                    <td class="label-ar arabic">رقم العميل</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->customer_id ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Address</td>
                    <td class="label-ar arabic">عنوان</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->delivery_address_snapshot ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Tel. No</td>
                    <td class="label-ar arabic">رقم هاتف</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->customer_phone_snapshot ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Fax.</td>
                    <td class="label-ar arabic">رقم الفاكس</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
                <tr>
                    <td class="label-en">Sales Person</td>
                    <td class="label-ar arabic">مندوب المبيعات</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
                <tr>
                    <td class="label-en">LPO Reference</td>
                    <td class="label-ar arabic">رقم طلب الشراء</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
                <tr>
                    <td class="label-en">D.N. No</td>
                    <td class="label-ar arabic">رقم سند التسليم</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
            </table>
            <table>
                <tr>
                    <td class="label-en">Invoice No</td>
                    <td class="label-ar arabic">رقم الفاتورة</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->order_number ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Date</td>
                    <td class="label-ar arabic">التاريخ</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->scheduled_date?->format('d-M-Y') ?? $order->created_at?->format('d-M-Y') ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Date Ordered</td>
                    <td class="label-ar arabic">تاريخ الطلب</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->created_at?->format('d-M-Y') ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Order</td>
                    <td class="label-ar arabic">رقم الطلب</td>
                    <td class="label-sep">:</td>
                    <td class="value">{{ $order->type ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label-en">Payment Term</td>
                    <td class="label-ar arabic">شروط الدفع</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
                <tr>
                    <td class="label-en">Payment Type</td>
                    <td class="label-ar arabic">طريقة الدفع</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
                <tr>
                    <td class="label-en">POS Reference</td>
                    <td class="label-ar arabic">مرجع نقاط البيع</td>
                    <td class="label-sep">:</td>
                    <td class="value">-</td>
                </tr>
            </table>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th class="center" style="width: 5%;">S.NO<br><span class="arabic">الرقم</span></th>
                    <th class="barcode center">BARCODE<br><span class="arabic">الباركود</span></th>
                    <th class="desc">DESCRIPTIONS<br><span class="arabic">التفاصيل</span></th>
                    <th class="uom center">UOM<br><span class="arabic">وحدة</span></th>
                    <th class="qty center">QTY<br><span class="arabic">كمية</span></th>
                    <th class="price right">UNIT PRICE<br><span class="arabic">سعر الوحدة</span></th>
                    <th class="discount right">DIS.<br><span class="arabic">خصم</span></th>
                    <th class="total right">TOTAL<br><span class="arabic">المجموع</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($order->items as $index => $item)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="center">{{ $item->menuItem?->code ?? '-' }}</td>
                        <td>{{ $item->description_snapshot ?? 'Item' }}</td>
                        <td class="center">EA</td>
                        <td class="center">{{ $fmt($item->quantity) }}</td>
                        <td class="right">{{ $fmt($item->unit_price) }}</td>
                        <td class="right">{{ $fmt($item->discount_amount) }}</td>
                        <td class="right">{{ $fmt($item->line_total) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="center">No items.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals-bar">
            <div class="totals-words">
                <span class="label">QAR :</span>
                {{ $amountToWords($order->total_amount) }}
            </div>
            <div class="grand-total">
                <span class="label">Grand Total</span>
                <span class="arabic">المبلغ الإجمالي</span>
                <span class="amount">{{ $fmt($order->total_amount) }}</span>
            </div>
        </div>

        <div class="signatures">
            <div class="box">
                <div class="label">Receiver / Line Manager</div>
                <div class="arabic">المدير المباشر/المستلم</div>
            </div>
            <div class="box">
                <div class="label">Signature</div>
                <div class="arabic">التوقيع</div>
            </div>
            <div class="box">
                <div class="label">Authorized Signature</div>
                <div class="arabic">توقيع معتمد</div>
            </div>
        </div>

        <div class="prepared">
            Prepared By :
            <small class="arabic">أعدت بواسطة</small>
            LAYLATPM
            <small>TOP MANAGEMENT</small>
        </div>

        <div class="footer">
            <div>Tel : 44413660</div>
            <div>Page 1 of 1</div>
        </div>
    </div>
@empty
    <div class="invoice">
        <div>No orders found for the selected filters.</div>
    </div>
@endforelse
</body>
</html>
