<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Invoice</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 8mm 10mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; }

        .invoice { width: 190mm; min-height: 270mm; margin: 0 auto; position: relative; page-break-after: auto; padding-bottom: 45mm; }
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

        .totals-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 25mm; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 2mm 0; font-size: 11px; }
        .totals-words { flex: 1; }
        .grand-total { display: flex; gap: 6mm; align-items: center; }
        .grand-total .label { font-weight: 700; }
        .grand-total .amount { font-weight: 700; min-width: 24mm; text-align: right; }

        .bottom-area { position: absolute; left: 0; right: 0; bottom: 12mm; font-size: 11px; }
        .signatures-row { display: flex; justify-content: space-between; align-items: flex-start; }
        .signatures-row .col { width: 32%; }
        .signatures-row .label { font-weight: 700; }
        .signatures-row .line { display: flex; gap: 6px; align-items: baseline; margin-bottom: 8mm; }
        .signatures-row .colon { margin-left: 10mm; }
        .prepared { text-align: center; font-size: 12px; font-weight: 700; }
        .prepared small { display: block; font-weight: 600; margin-top: 2px; }

        .footer { position: absolute; bottom: 4mm; left: 0; right: 0; border-top: 1px solid #333; padding-top: 2mm; display: flex; justify-content: space-between; font-size: 10px; }
    </style>
</head>
<body>
@php
    use App\Models\PaymentTerm;
    use App\Models\User;
    use App\Support\Money\MinorUnits;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    $scale = MinorUnits::posScale();
    $digits = MinorUnits::scaleDigits($scale);
    $currency = (string) config('pos.currency');
    $fmtCents = function (int $cents) use ($scale): string {
        return MinorUnits::format($cents, $scale);
    };
    $branchName = null;
    if (Schema::hasTable('branches')) {
        $branchName = DB::table('branches')->where('id', $invoice->branch_id)->value('name');
    }
    $paymentTermName = null;
    if ($invoice->payment_term_id && Schema::hasTable('payment_terms')) {
        $paymentTermName = PaymentTerm::query()->where('id', $invoice->payment_term_id)->value('name');
    }
    $paymentTermDisplay = $invoice->payment_type === 'credit'
        ? ($paymentTermName ?? ($invoice->payment_term_days ? $invoice->payment_term_days.' Days' : __('Credit')))
        : __('Immediate');
    $salesPersonName = $invoice->sales_person_id ? User::query()->where('id', $invoice->sales_person_id)->value('username') : null;
    $preparedByName = $invoice->created_by ? User::query()->where('id', $invoice->created_by)->value('username') : null;

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
    $amountToWords = function (int $cents) use ($fmtCents, $numberToWords, $scale, $digits): string {
        $formatted = $fmtCents($cents);
        [$whole, $fraction] = array_pad(explode('.', $formatted), 2, '');
        $wholeWords = $numberToWords((int) $whole);
        if ($digits <= 0) {
            return $wholeWords.' Only.';
        }
        $fraction = str_pad($fraction, $digits, '0', STR_PAD_RIGHT);

        return $wholeWords.' And '.$fraction.'/'.$scale.' Only.';
    };
@endphp

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
                <td class="label-en">Branch</td>
                <td class="label-ar arabic">الفرع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $branchName ?? $invoice->branch_id ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Customer Name</td>
                <td class="label-ar arabic">اسم العميل</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->customer?->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Customer ID</td>
                <td class="label-ar arabic">رقم العميل</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->customer?->customer_code ?? $invoice->customer_id ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Billing Address</td>
                <td class="label-ar arabic">عنوان الفاتورة</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->customer?->billing_address ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Phone</td>
                <td class="label-ar arabic">رقم الهاتف</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->customer?->phone ?? '-' }}</td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="label-en">Invoice Date</td>
                <td class="label-ar arabic">تاريخ الفاتورة</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->issue_date?->format('d-M-Y') ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Payment Type</td>
                <td class="label-ar arabic">طريقة الدفع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->payment_type ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Payment Term</td>
                <td class="label-ar arabic">شروط الدفع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $paymentTermDisplay }}</td>
            </tr>
            <tr>
                <td class="label-en">Sales Person</td>
                <td class="label-ar arabic">مندوب المبيعات</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $salesPersonName ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">LPO Reference</td>
                <td class="label-ar arabic">رقم طلب الشراء</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->lpo_reference ?? '-' }}</td>
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
            @forelse ($invoice->items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="center">{{ $item->sku_snapshot ?? '-' }}</td>
                    <td>{{ $item->description ?? $item->name_snapshot ?? 'Item' }}</td>
                    <td class="center">{{ $item->unit ?? 'EA' }}</td>
                    <td class="center">{{ number_format((float) $item->qty, 3, '.', '') }}</td>
                    <td class="right">{{ $fmtCents((int) $item->unit_price_cents) }}</td>
                    <td class="right">{{ $fmtCents((int) $item->discount_cents) }}</td>
                    <td class="right">{{ $fmtCents((int) $item->line_total_cents) }}</td>
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
            <span class="label">{{ $currency }} :</span>
            {{ $amountToWords((int) $invoice->total_cents) }}
        </div>
        <div class="grand-total">
            <span class="label">Grand Total</span>
            <span class="arabic">المبلغ الإجمالي</span>
            <span class="amount">{{ $fmtCents((int) $invoice->total_cents) }}</span>
        </div>
    </div>

    <div class="bottom-area">
        <div class="signatures-row">
            <div class="col">
                <div class="line">
                    <div>
                        <div class="label">Receiver / Line Manager</div>
                        <div class="arabic">المدير المباشر/المستلم</div>
                    </div>
                    <div class="colon">:</div>
                </div>
                <div class="line">
                    <div>
                        <div class="label">Signature</div>
                        <div class="arabic">التوقيع</div>
                    </div>
                    <div class="colon">:</div>
                </div>
            </div>
            <!-- <div class="col prepared">
                Prepared By :
                <small class="arabic">أعدت بواسطة</small>
                {{ $preparedByName ?? '-' }}
                <small>TOP MANAGEMENT</small>
            </div> -->
            <div class="col" style="text-align: right;">
                <div class="line" style="justify-content: flex-end;">
                    <div>
                        <div class="label">Authorized Signature</div>
                        <div class="arabic">توقيع معتمد</div>
                    </div>
                    <div class="colon">:</div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer">
        <div>Tel : 44413660</div>
        <div>Page 1 of 1</div>
    </div>
</div>
</body>
</html>

