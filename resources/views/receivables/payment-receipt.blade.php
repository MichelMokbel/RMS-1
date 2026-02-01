<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Receipt</title>
    <style>
        :root { color-scheme: light; }
        @page { size: A4; margin: 8mm 10mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; }

        .receipt { width: 190mm; min-height: 270mm; margin: 0 auto; position: relative; page-break-after: auto; padding-bottom: 45mm; }
        .header { display: flex; align-items: flex-start; gap: 10mm; margin-top: 6mm; }
        .logo { width: 26mm; height: 26mm; object-fit: contain; }
        .company { flex: 1; text-align: center; margin-right: 26mm; }
        .company-title { font-size: 18px; font-weight: 700; letter-spacing: 0.4px; margin: 0; }
        .company-subtitle { font-size: 11px; margin-top: 2px; }
        .receipt-title { text-align: center; font-size: 16px; font-weight: 700; text-decoration: underline; margin: 5mm 0 0; }

        .info { display: flex; justify-content: space-between; margin-top: 6mm; font-size: 11px; }
        .info table { width: 88mm; border-collapse: collapse; }
        .info td { padding: 1.6mm 0; vertical-align: top; }
        .label-en { width: 30mm; font-weight: 600; }
        .label-ar { width: 22mm; text-align: right; font-weight: 600; }
        .label-sep { width: 4mm; text-align: center; font-weight: 600; }
        .value { font-weight: 600; }
        .arabic { font-family: "Tahoma", Arial, sans-serif; direction: rtl; }

        .allocations { margin-top: 6mm; width: 100%; border-collapse: collapse; font-size: 11px; }
        .allocations th, .allocations td { border: 1px solid #333; padding: 3px 4px; text-align: left; }
        .allocations th { font-weight: 700; }
        .allocations .center { text-align: center; }
        .allocations .right { text-align: right; }

        .summary { margin-top: 6mm; width: 100%; font-size: 11px; }
        .summary-row { display: flex; justify-content: flex-end; gap: 4mm; padding: 2mm 0; }
        .summary-label { font-weight: 600; min-width: 40mm; text-align: right; }
        .summary-value { font-weight: 700; min-width: 30mm; text-align: right; }
        .summary-total { border-top: 2px solid #333; border-bottom: 2px solid #333; margin-top: 2mm; }
        .summary-advance { color: #0066cc; }

        .totals-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 10mm; border-top: 1px solid #333; border-bottom: 1px solid #333; padding: 2mm 0; font-size: 11px; }
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

        .footer { position: absolute; bottom: 4mm; left: 0; right: 0; border-top: 1px solid #333; padding-top: 2mm; display: flex; justify-content: space-between; font-size: 10px; }

        .no-print { margin-bottom: 10mm; }
        .btn { display: inline-block; padding: 8px 16px; border: 1px solid #333; background: #fff; cursor: pointer; font-size: 12px; margin-right: 8px; }
        .btn:hover { background: #f0f0f0; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
@php
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
    if (Schema::hasTable('branches') && $payment->branch_id) {
        $branchName = DB::table('branches')->where('id', $payment->branch_id)->value('name');
    }

    $createdByName = $payment->created_by ? User::query()->where('id', $payment->created_by)->value('username') : null;

    $allocatedCents = (int) $payment->allocations->sum('amount_cents');
    $unallocatedCents = (int) $payment->amount_cents - $allocatedCents;

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

<div class="no-print">
    <button class="btn" onclick="window.print()">Print</button>
    <button class="btn" onclick="window.close()">Close</button>
</div>

<div class="receipt">
    <div class="header">
        <img class="logo" src="{{ asset('logo.png') }}" alt="Layla Kitchen Logo">
        <div class="company">
            <div class="company-title">LAYLA KITCHEN W.L.L</div>
            <div class="company-subtitle">MAAMOURA AL MAADID STREET, DOHA, QATAR</div>
        </div>
    </div>

    <div class="receipt-title">Payment Receipt<br><span class="arabic">إيصال دفع</span></div>

    <div class="info">
        <table>
            <tr>
                <td class="label-en">Receipt No</td>
                <td class="label-ar arabic">رقم الإيصال</td>
                <td class="label-sep">:</td>
                <td class="value">RCP-{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</td>
            </tr>
            <tr>
                <td class="label-en">Branch</td>
                <td class="label-ar arabic">الفرع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $branchName ?? $payment->branch_id ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Customer Name</td>
                <td class="label-ar arabic">اسم العميل</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->customer?->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Customer ID</td>
                <td class="label-ar arabic">رقم العميل</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->customer?->customer_code ?? $payment->customer_id ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Phone</td>
                <td class="label-ar arabic">رقم الهاتف</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->customer?->phone ?? '-' }}</td>
            </tr>
        </table>
        <table>
            <tr>
                <td class="label-en">Payment Date</td>
                <td class="label-ar arabic">تاريخ الدفع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->received_at?->format('d-M-Y') ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Payment Method</td>
                <td class="label-ar arabic">طريقة الدفع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ strtoupper($payment->method ?? '-') }}</td>
            </tr>
            <tr>
                <td class="label-en">Reference</td>
                <td class="label-ar arabic">المرجع</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->reference ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label-en">Received By</td>
                <td class="label-ar arabic">استلم بواسطة</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $createdByName ?? '-' }}</td>
            </tr>
            @if ($payment->notes)
            <tr>
                <td class="label-en">Notes</td>
                <td class="label-ar arabic">ملاحظات</td>
                <td class="label-sep">:</td>
                <td class="value">{{ $payment->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="summary">
        <div class="summary-row summary-total">
            <span class="summary-label">Amount Received / المبلغ المستلم :</span>
            <span class="summary-value">{{ $fmtCents((int) $payment->amount_cents) }}</span>
        </div>
        @if ($unallocatedCents > 0)
        <div class="summary-row summary-advance">
            <span class="summary-label">Advance Balance / رصيد مقدم :</span>
            <span class="summary-value">{{ $fmtCents($unallocatedCents) }}</span>
        </div>
        @endif
    </div>

    @if ($payment->allocations->count() > 0)
    <h4 style="margin-top: 6mm; margin-bottom: 2mm; font-size: 12px;">Allocation Details / تفاصيل التخصيص</h4>
    <table class="allocations">
        <thead>
            <tr>
                <th class="center" style="width: 8%;">S.NO<br><span class="arabic">الرقم</span></th>
                <th style="width: 25%;">INVOICE NO<br><span class="arabic">رقم الفاتورة</span></th>
                <th style="width: 20%;">INVOICE DATE<br><span class="arabic">تاريخ الفاتورة</span></th>
                <th class="right" style="width: 22%;">INVOICE TOTAL<br><span class="arabic">إجمالي الفاتورة</span></th>
                <th class="right" style="width: 25%;">AMOUNT PAID<br><span class="arabic">المبلغ المدفوع</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payment->allocations as $index => $alloc)
                @php
                    $invoice = $alloc->allocatable;
                @endphp
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $invoice?->invoice_number ?? ($invoice ? 'INV-'.$invoice->id : '-') }}</td>
                    <td>{{ $invoice?->issue_date?->format('d-M-Y') ?? '-' }}</td>
                    <td class="right">{{ $invoice ? $fmtCents((int) $invoice->total_cents) : '-' }}</td>
                    <td class="right">{{ $fmtCents((int) $alloc->amount_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals-bar">
        <div class="totals-words">
            <span class="label">{{ $currency }} :</span>
            {{ $amountToWords($allocatedCents) }}
        </div>
        <div class="grand-total">
            <span class="label">Total Allocated</span>
            <span class="arabic">إجمالي المخصص</span>
            <span class="amount">{{ $fmtCents($allocatedCents) }}</span>
        </div>
    </div>
    @endif

    <div class="bottom-area">
        <div class="signatures-row">
            <div class="col">
                <div class="line">
                    <div>
                        <div class="label">Customer Signature</div>
                        <div class="arabic">توقيع العميل</div>
                    </div>
                    <div class="colon">:</div>
                </div>
            </div>
            <div class="col" style="text-align: center;">
                <div style="margin-top: 8mm;">
                    <div class="label">Thank you for your payment</div>
                    <div class="arabic">شكراً لدفعتكم</div>
                </div>
            </div>
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
