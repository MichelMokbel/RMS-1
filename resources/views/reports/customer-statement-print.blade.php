<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Statement</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 12mm; }
        html, body { width: 100%; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; color: #111; padding: 6mm; background: #fff; }
        .page { width: 100%; margin: 0 auto; padding: 8mm 10mm; }
        .no-print { margin-bottom: 12px; }
        .btn { display: inline-block; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; text-decoration: none; font-size: 13px; cursor: pointer; color: #111; }

        .statement { width: 100%; max-width: 100%; overflow: hidden; }
        .header { display: grid; grid-template-columns: 170px 1fr 200px; gap: 10px; align-items: start; margin-bottom: 8px; }
        .logo-box { text-align: center; }
        .logo { width: 72px; height: 72px; object-fit: contain; display: block; margin: 0 auto; }
        .company-name { font-size: 18px; line-height: 1; margin-top: 4px; font-family: "Brush Script MT", "Lucida Handwriting", cursive; }
        .center-title { text-align: center; }
        .center-title .company { font-size: 16px; font-weight: 700; margin: 0; }
        .center-title .phone { margin-top: 4px; font-size: 12px; font-weight: 700; }
        .center-title .report-title { margin-top: 6px; font-size: 14px; font-weight: 700; }
        .center-title .period { margin-top: 2px; font-size: 13px; font-weight: 700; }
        .date-meta { text-align: right; font-size: 12px; font-weight: 700; margin-top: 6px; }
        .date-meta .date-value { margin-left: 8px; }

        .customer-meta { margin-top: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; font-size: 12px; }
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { padding: 5px 0; vertical-align: top; }
        .meta-label { width: 150px; font-weight: 700; }
        .meta-colon { width: 16px; text-align: center; font-weight: 700; }
        .meta-value { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; }
        .main-table { margin-top: 10px; font-size: 11px; table-layout: fixed; }
        .main-table th, .main-table td { border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 5px 4px; text-align: left; word-break: break-word; }
        .main-table th { font-weight: 700; }
        .main-table .right { text-align: right; }
        .main-table tfoot td { font-weight: 700; }
        .main-table th:nth-child(1), .main-table td:nth-child(1) { width: 3%; }
        .main-table th:nth-child(2), .main-table td:nth-child(2) { width: 8%; }
        .main-table th:nth-child(3), .main-table td:nth-child(3) { width: 9%; }
        .main-table th:nth-child(4), .main-table td:nth-child(4) { width: 8%; }
        .main-table th:nth-child(5), .main-table td:nth-child(5) { width: 8%; }
        .main-table th:nth-child(6), .main-table td:nth-child(6) { width: 8%; }
        .main-table th:nth-child(7), .main-table td:nth-child(7) { width: 8%; }
        .main-table th:nth-child(8), .main-table td:nth-child(8) { width: 11%; }
        .main-table th:nth-child(9), .main-table td:nth-child(9) { width: 7%; }
        .main-table th:nth-child(10), .main-table td:nth-child(10) { width: 7%; }
        .main-table th:nth-child(11), .main-table td:nth-child(11) { width: 7%; }
        .main-table th:nth-child(12), .main-table td:nth-child(12) { width: 8%; }
        .main-table th:nth-child(13), .main-table td:nth-child(13) { width: 8%; }

        .summary { margin-top: 6px; border-top: 1px solid #111; border-bottom: 1px solid #111; }
        .summary table td { padding: 8px 6px; font-size: 12px; border-bottom: 1px solid #111; }
        .summary table tr:last-child td { border-bottom: none; font-weight: 700; }
        .summary-label { text-align: right; font-weight: 700; padding-right: 12px; }
        .summary-value { width: 220px; text-align: right; font-weight: 700; }

        .bottom-grid { margin-top: 10px; display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 24px; align-items: start; }
        .aging-table { font-size: 12px; }
        .aging-table th, .aging-table td { border: 1px solid #111; padding: 8px 6px; text-align: right; }
        .aging-table th:last-child, .aging-table td:last-child { font-weight: 700; }
        .notes { margin-top: 12px; font-size: 12px; line-height: 1.35; }

        .bank-title { font-size: 13px; font-weight: 700; text-decoration: underline; margin-bottom: 6px; }
        .bank-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .bank-table td { padding: 4px 0; vertical-align: top; }
        .bank-label { width: 160px; font-weight: 700; }
        .bank-colon { width: 16px; text-align: center; font-weight: 700; }
        .bank-value { font-weight: 600; }

        .sign-row { margin-top: 32px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; font-size: 12px; }
        .sign-line { border-bottom: 1px solid #111; display: inline-block; width: 60%; vertical-align: middle; margin-left: 8px; }
        .footer { margin-top: 30px; border-top: 1px solid #111; padding-top: 6px; text-align: right; font-size: 12px; }

        @media print {
            body { padding: 0; }
            .page { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
@php
    $dateFromLabel = !empty($filters['date_from']) ? now()->parse($filters['date_from'])->format('d F Y') : 'Beginning';
    $dateToLabel = !empty($filters['date_to']) ? now()->parse($filters['date_to'])->format('d F Y') : now()->format('d F Y');
    $reportDate = $generatedAt->format('d-M-Y');

    $logoPath = public_path('logo.png');
    $logoSrc = asset('logo.png');
    if (is_file($logoPath) && is_readable($logoPath)) {
        $logoData = @file_get_contents($logoPath);
        if ($logoData !== false) {
            $logoSrc = 'data:image/png;base64,'.base64_encode($logoData);
        }
    }

    $paymentTerm = ($customer?->credit_terms_days ?? 0) > 0 ? ((int) $customer->credit_terms_days.' Days') : '-';
@endphp
    <div class="no-print">
        <button class="btn" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('reports.customer-statement') }}">Back to Report</a>
    </div>

    <div class="page">
    <div class="statement">
        <div class="header">
            <div class="logo-box">
                <img class="logo" src="{{ $logoSrc }}" alt="Layla Kitchen Logo">
                <div class="company-name">Layla Kitchen</div>
            </div>
            <div class="center-title">
                <h1 class="company">LAYLA KITCHEN W.L.L</h1>
                <div class="phone">M: 44413660,</div>
                <div class="report-title">Customer Statement of Account</div>
                <div class="period">From {{ $dateFromLabel }} To {{ $dateToLabel }}</div>
            </div>
            <div class="date-meta">
                Date:
                <span class="date-value">{{ $reportDate }}</span>
            </div>
        </div>

        <div class="customer-meta">
            <table class="meta-table">
                <tr><td class="meta-label">Customer Code</td><td class="meta-colon">:</td><td class="meta-value">{{ $customer?->customer_code ?: '-' }}</td></tr>
                <tr><td class="meta-label">Customer Name</td><td class="meta-colon">:</td><td class="meta-value">{{ $customer?->name ?: '-' }}</td></tr>
                <tr><td class="meta-label">Payment Type</td><td class="meta-colon">:</td><td class="meta-value">-</td></tr>
                <tr><td class="meta-label">Sales Person</td><td class="meta-colon">:</td><td class="meta-value">-</td></tr>
                <tr><td class="meta-label">Location</td><td class="meta-colon">:</td><td class="meta-value">Doha</td></tr>
            </table>
            <table class="meta-table">
                <tr><td class="meta-label">Credit Limit</td><td class="meta-colon">:</td><td class="meta-value">{{ number_format((float) ($customer?->credit_limit ?? 0), 2, '.', ',') }}</td></tr>
                <tr><td class="meta-label">Payment Term</td><td class="meta-colon">:</td><td class="meta-value">{{ $paymentTerm }}</td></tr>
                <tr><td class="meta-label">Currency</td><td class="meta-colon">:</td><td class="meta-value">{{ config('pos.currency', 'QAR') }}</td></tr>
                <tr><td class="meta-label">Project</td><td class="meta-colon">:</td><td class="meta-value">-</td></tr>
            </table>
        </div>

        <table class="main-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>NO</th>
                    <th>DOCUMENT TYPE</th>
                    <th>LOCATION</th>
                    <th>TYPE</th>
                    <th>DATE</th>
                    <th>DUE DATE</th>
                    <th>REFERENCE NO</th>
                    <th class="right">AMOUNT</th>
                    <th class="right">PAID</th>
                    <th class="right">BALANCE</th>
                    <th>AGING</th>
                    <th>PAYMENT NO</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $isPayment = ($row['row_type'] === 'payment'); @endphp
                    <tr @if($isPayment) style="background:#f0fdf4;" @endif>
                        <td>{{ $row['line_no'] }}</td>
                        <td>{{ $row['document_no'] }}</td>
                        <td>{{ $row['document_type'] }}</td>
                        <td>{{ $row['location'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['due_date'] }}</td>
                        <td>{{ $row['reference_no'] }}</td>
                        <td class="right">{{ $isPayment ? '-' : $formatCents($row['amount_cents']) }}</td>
                        <td class="right">{{ $formatCents($row['paid_cents']) }}</td>
                        <td class="right">{{ $isPayment ? '-' : $formatCents($row['balance_cents']) }}</td>
                        <td>{{ $row['aging_label'] }}</td>
                        <td>{{ $row['payment_no'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13">No statement entries found.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="8" class="right">TOTAL (Invoiced / Received / Net)</td>
                    <td class="right">{{ $formatCents($summary['period_amount_cents']) }}</td>
                    <td class="right">{{ $formatCents($summary['period_received_cents']) }}</td>
                    <td class="right">{{ $formatCents($summary['period_balance_cents']) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>

        <div class="summary">
            <table>
                <tr>
                    <td class="summary-label">Total Amount</td>
                    <td class="summary-value">{{ $formatCents($summary['period_balance_cents']) }}</td>
                </tr>
                <tr>
                    <td class="summary-label">Previous Balance</td>
                    <td class="summary-value">{{ $formatCents($summary['previous_balance_cents']) }}</td>
                </tr>
                <tr>
                    <td class="summary-label">Total Outstanding Amount</td>
                    <td class="summary-value">{{ $formatCents($summary['total_outstanding_cents']) }}</td>
                </tr>
            </table>
        </div>

        <div class="bottom-grid">
            <div>
                <table class="aging-table">
                    <thead>
                        <tr>
                            <th>Not in Due</th>
                            <th>1-30</th>
                            <th>31-60</th>
                            <th>61-90</th>
                            <th>Over 90 Days</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $formatCents($aging['not_due']) }}</td>
                            <td>{{ $formatCents($aging['bucket_1_30']) }}</td>
                            <td>{{ $formatCents($aging['bucket_31_60']) }}</td>
                            <td>{{ $formatCents($aging['bucket_61_90']) }}</td>
                            <td>{{ $formatCents($aging['bucket_over_90']) }}</td>
                            <td>{{ $formatCents($aging['total']) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="notes">
                    Please verify the details and notify us if any discrepancy with your balance within 7 days from receipt of this statement.
                </div>
            </div>

            <div>
                <div class="bank-title">Bank Account Details</div>
                <table class="bank-table">
                    <tr><td class="bank-label">Account Name</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['account_name'] ?? '-' }}</td></tr>
                    <tr><td class="bank-label">Bank Name</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['bank_name'] ?? '-' }}</td></tr>
                    <tr><td class="bank-label">Bank Address</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['bank_address'] ?? '-' }}</td></tr>
                    <tr><td class="bank-label">Account No.</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['account_no'] ?? '-' }}</td></tr>
                    <tr><td class="bank-label">IBAN</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['iban'] ?? '-' }}</td></tr>
                    <tr><td class="bank-label">SWIFT Code</td><td class="bank-colon">:</td><td class="bank-value">{{ $bankDetails['swift_code'] ?? '-' }}</td></tr>
                </table>
            </div>
        </div>

        <div class="sign-row">
            <div>Generated by <span class="sign-line"></span></div>
            <div>Reviewed by <span class="sign-line"></span></div>
        </div>

        <div class="footer">Page 1/1</div>
    </div>
    </div>
</body>
</html>
