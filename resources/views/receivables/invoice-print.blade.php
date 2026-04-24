<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Invoice</title>
    <style>
        :root {
            color-scheme: light;
        }

        @page {
            size: A4;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            margin: 0;
        }

        .no-print {
            display: inline-flex;
            gap: 8px;
            margin: 12px 10mm;
        }

        .btn {
            display: inline-block;
            padding: 7px 12px;
            border: 1px solid #bfbfbf;
            border-radius: 6px;
            background: #fff;
            color: #111;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
        }

        .btn:hover {
            background: #f3f3f3;
        }

        .invoice {
            width: 190mm;
            min-height: 296mm;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            page-break-after: auto;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }

        .header {
            display: grid;
            grid-template-columns: 26mm 1fr 26mm;
            align-items: center;
            column-gap: 10mm;
            margin-top: 6mm;
        }

        .logo {
            width: 26mm;
            height: 26mm;
            object-fit: contain;
            justify-self: start;
        }

        .company {
            text-align: center;
            margin: 0;
        }

        .header-spacer {
            width: 26mm;
            height: 1px;
        }

        .company-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin: 0;
        }

        .company-subtitle {
            font-size: 11px;
            margin-top: 2px;
        }

        .invoice-title {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            text-decoration: underline;
            margin: 5mm 0 0;
        }

        .info {
            display: flex;
            justify-content: space-between;
            margin-top: 6mm;
            font-size: 11px;
        }

        .info table {
            width: 88mm;
            border-collapse: collapse;
        }

        .info td {
            padding: 1.6mm 0;
            vertical-align: top;
        }

        .label-en {
            width: 30mm;
            font-weight: 600;
        }

        .label-ar {
            width: 22mm;
            text-align: right;
            font-weight: 600;
        }

        .label-sep {
            width: 4mm;
            text-align: center;
            font-weight: 600;
        }

        .value {
            font-weight: 600;
        }

        .arabic {
            font-family: "Tahoma", Arial, sans-serif;
            direction: rtl;
        }

        .items {
            margin-top: 6mm;
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .items th,
        .items td {
            border: 1px solid #333;
            padding: 3px 4px;
            text-align: left;
        }

        .items th {
            font-weight: 700;
        }

        .items .center {
            text-align: center;
        }

        .items .right {
            text-align: right;
        }

        .items .desc {
            width: 40%;
        }

        .items .barcode {
            width: 18%;
        }

        .items .uom {
            width: 10%;
        }

        .items .qty {
            width: 8%;
        }

        .items .price {
            width: 12%;
        }

        .items .discount {
            width: 8%;
        }

        .items .total {
            width: 12%;
        }

        .item-desc-main {
            font-weight: 600;
        }

        .item-line-note {
            margin-top: 1.2mm;
            font-size: 10px;
            color: #444;
            white-space: pre-wrap;
        }

        .invoice-notes {
            margin-top: 4mm;
            padding: 2.2mm 3mm;
            font-size: 11px;
        }

        .invoice-notes .label {
            font-weight: 700;
            margin-bottom: 1mm;
        }

        .invoice-notes .value {
            font-weight: 400;
            white-space: pre-wrap;
        }

        .totals-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 25mm;
            border-top: 1px solid #333;
            border-bottom: 1px solid #333;
            padding: 2mm 0;
            font-size: 11px;
            gap: 8mm;
        }

        .totals-words {
            flex: 1;
            padding-bottom: 0.9mm;
        }

        .totals-summary {
            min-width: 62mm;
            border-collapse: collapse;
        }

        .totals-summary td {
            padding: 0.9mm 0;
        }

        .totals-summary .label {
            text-align: left;
        }

        .totals-summary .amount {
            text-align: right;
            min-width: 24mm;
        }

        .totals-summary .total td {
            font-weight: 700;
            border-top: 1px solid #333;
            padding-top: 1.4mm;
        }

        .bottom-area {
            margin-top: auto;
            padding-top: 18mm;
            font-size: 11px;
        }

        .signatures-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .signatures-row .col {
            width: 32%;
        }

        .signatures-row .label {
            font-weight: 700;
        }

        .signatures-row .line {
            display: flex;
            gap: 6px;
            align-items: baseline;
            margin-bottom: 8mm;
        }

        .signatures-row .colon {
            margin-left: 10mm;
        }

        .prepared {
            text-align: center;
            font-size: 12px;
            font-weight: 700;
        }

        .prepared small {
            display: block;
            font-weight: 600;
            margin-top: 2px;
        }

        .footer {
            margin-top: 12mm;
            padding-top: 2mm;
            border-top: 1px solid #333;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
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
    $lineDiscountCents = (int) $invoice->items->sum(fn ($item) => (int) ($item->discount_cents ?? 0));
    $invoiceDiscountCents = (int) ($invoice->invoice_discount_cents ?? 0);
    $subtotalCents = (int) ($invoice->subtotal_cents ?? 0);
    $grandTotalCents = (int) ($invoice->total_cents ?? 0);
    $invoiceNote = trim((string) ($invoice->notes ?? ''));

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
        $fraction=str_pad($fraction, $digits, '0' , STR_PAD_RIGHT);

        return $wholeWords.' And '.$fraction.' /'.$scale.' Only.';
        };
        @endphp

        <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('invoices.show', $invoice) }}">Back to Invoice</a>
        </div>

        <div class="invoice">
            <div class="header">
                <img class="logo" src="{{ asset('logo.png') }}" alt="Layla Kitchen Logo">
                <div class="company">
                    <div class="company-title">LAYLA KITCHEN W.L.L</div>
                    <div class="company-subtitle">MAAMOURA AL MAADID STREET, DOHA, QATAR</div>
                </div>
                <div class="header-spacer" aria-hidden="true"></div>
            </div>

            <div class="invoice-title">Sales Invoice</div>

            <div class="info">
        <table>
            <tr>
                <td class="label-en">Invoice No</td>
                <td class="label-ar arabic">رقم الفاتورة</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $invoice->invoice_number ?: ('#'.$invoice->id) }}</td>
            </tr>
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
        <div class="info-right">
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
                        <tr>
                            <td class="label-en">POS Reference</td>
                            <td class="label-ar arabic">مرجع نقطة البيع</td>
                            <td class="label-sep">:</td>
                            <td class="value">{{ $invoice->pos_reference ?? '-' }}</td>
                        </tr>
                    </table>
                </div>
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
                    @php
                    $displayDescription = trim((string) ($item->name_snapshot ?? ''));
                    if ($displayDescription === '') {
                    $rawDescription = trim((string) ($item->description ?? ''));
                    $sku = trim((string) ($item->sku_snapshot ?? ''));
                    if ($rawDescription !== '' && $sku !== '' && str_starts_with($rawDescription, $sku)) {
                    $rawDescription = ltrim(substr($rawDescription, strlen($sku)));
                    }
                    $displayDescription = $rawDescription !== '' ? $rawDescription : 'Item';
                    }
                    $lineNote = trim((string) ($item->line_notes ?? ''));
                    @endphp
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="center">{{ $item->sku_snapshot ?? '-' }}</td>
                        <td>
                            <div class="item-desc-main">{{ $displayDescription }}</div>
                            @if ($lineNote !== '')
                                <div class="item-line-note">{{ $lineNote }}</div>
                            @endif
                        </td>
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

            @if ($invoiceNote !== '')
                <div class="invoice-notes">
                    <div class="label">Notes</div>
                    <div class="value">{{ $invoiceNote }}</div>
                </div>
            @endif

            <div class="totals-bar">
                <div class="totals-words">
                    <span class="label">{{ $currency }} :</span>
                    {{ $amountToWords($grandTotalCents) }}
                </div>
                <table class="totals-summary">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="amount">{{ $fmtCents($subtotalCents) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Line Discount</td>
                        <td class="amount">-{{ $fmtCents($lineDiscountCents) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Invoice Discount</td>
                        <td class="amount">-{{ $fmtCents($invoiceDiscountCents) }}</td>
                    </tr>
                    <tr class="total">
                        <td class="label">Grand Total</td>
                        <td class="amount">{{ $fmtCents($grandTotalCents) }}</td>
                    </tr>
                </table>
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
