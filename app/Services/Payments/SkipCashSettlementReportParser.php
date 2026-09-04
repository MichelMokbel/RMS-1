<?php

namespace App\Services\Payments;

use App\Services\Customers\PhoneNumberService;
use App\Support\Imports\SafeSpreadsheetReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SkipCashSettlementReportParser
{
    private const REQUIRED_HEADERS = [
        'period',
        'paymentref',
        'referencenumber',
        'ordertype',
        'status',
        'transactiondate',
        'transactiontime',
        'merchant',
        'branch',
        'sales',
        'grossamount',
        'variablecommission',
        'fixedcommission',
        'totalcommission',
        'netmerchantsettlementamount',
    ];

    private const MAX_EXACT_CENTS = '9007199254740991';

    public function __construct(
        private readonly SafeSpreadsheetReader $reader,
        private readonly PhoneNumberService $phoneNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @return array{sheet:string,period_start:?string,period_end:?string,rows:array<int,array<string,mixed>>,totals:array<string,int>,totals_complete:bool}
     */
    public function parse(string $path, array $profile): array
    {
        try {
            $workbook = $this->reader->workbook($path, [
                'raw_numeric_strings' => true,
                'include_row_metadata' => true,
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook could not be read safely: :reason', [
                    'reason' => $exception->getMessage(),
                ]),
            ]);
        }

        $populated = array_filter(
            $workbook['sheets'],
            fn (array $rows): bool => $rows !== [],
        );
        if (count($populated) !== 1) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook must contain exactly one populated report sheet.'),
            ]);
        }

        $sheet = (string) array_key_first($populated);
        $headers = $workbook['headers'][$sheet] ?? [];
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missingHeaders !== []) {
            throw ValidationException::withMessages([
                'workbook' => __('The report is missing required columns: :columns.', [
                    'columns' => implode(', ', $missingHeaders),
                ]),
            ]);
        }

        $sourceRows = array_values($populated[$sheet]);
        $maxRows = max(1, (int) config('skipcash.settlements.max_rows', 10_000));
        if (count($sourceRows) > $maxRows) {
            throw ValidationException::withMessages([
                'workbook' => __('The report contains more than :count data rows.', ['count' => $maxRows]),
            ]);
        }

        $timezone = (string) ($profile['timezone'] ?? '');
        $currency = strtoupper(trim((string) ($profile['currency'] ?? '')));
        $merchant = trim((string) ($profile['merchant'] ?? ''));
        $branchMap = $profile['branch_map'] ?? null;
        if ($timezone !== 'Asia/Qatar' || $currency !== 'QAR' || $merchant === '' || ! is_array($branchMap)) {
            throw ValidationException::withMessages([
                'payment_source_id' => __('The SkipCash report profile is incomplete.'),
            ]);
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            throw ValidationException::withMessages([
                'payment_source_id' => __('The SkipCash report timezone is invalid.'),
            ]);
        }

        $rows = [];
        $periods = [];
        $totals = [
            'gross_cents' => 0,
            'commission_cents' => 0,
            'settlement_fee_cents' => 0,
            'net_cents' => 0,
        ];
        $totalsComplete = true;

        foreach ($sourceRows as $index => $sourceRow) {
            $parsed = $this->parseRow($sourceRow, $index + 1, $sheet, $merchant, $branchMap, $zone);
            $rows[] = $parsed;

            if ($parsed['period_start'] !== null && $parsed['period_end'] !== null) {
                $periods[$parsed['period_start'].'|'.$parsed['period_end']] = [
                    $parsed['period_start'],
                    $parsed['period_end'],
                ];
            }

            if ($parsed['error_code'] !== null) {
                $totalsComplete = false;
            }
            $rowTotals = [
                'gross_cents' => $parsed['gross_cents'],
                'commission_cents' => $parsed['order_type'] === 'sale'
                    ? $parsed['total_commission_cents']
                    : 0,
                'settlement_fee_cents' => $parsed['settlement_fee_cents'],
                'net_cents' => $parsed['net_cents'],
            ];
            foreach ($rowTotals as $key => $value) {
                if ($value === null) {
                    $totalsComplete = false;

                    continue;
                }
                $totals[$key] = $this->checkedAdd($totals[$key], (int) $value);
            }
        }

        if (count($periods) !== 1) {
            $totalsComplete = false;
            foreach ($rows as &$row) {
                $row['errors'][] = 'The report period is missing or inconsistent.';
                $row['error_code'] ??= 'REPORT_PERIOD_INCONSISTENT';
            }
            unset($row);
        }

        $period = count($periods) === 1 ? array_values($periods)[0] : [null, null];

        return [
            'sheet' => $sheet,
            'period_start' => $period[0],
            'period_end' => $period[1],
            'rows' => $rows,
            'totals' => $totals,
            'totals_complete' => $totalsComplete,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $branchMap
     * @return array<string, mixed>
     */
    private function parseRow(
        array $source,
        int $sequence,
        string $sheet,
        string $configuredMerchant,
        array $branchMap,
        DateTimeZone $timezone,
    ): array {
        $errors = [];
        $period = $this->parsePeriod($this->value($source, 'period'));
        if ($period === null) {
            $errors['REPORT_PERIOD_INVALID'] = 'The period must use DD/MM/YYYY - DD/MM/YYYY.';
        }

        $orderTypeRaw = $this->value($source, 'ordertype');
        $orderType = match (strtolower((string) $orderTypeRaw)) {
            'sale' => 'sale',
            'settlement fee' => 'settlement_fee',
            default => null,
        };
        if ($orderType === null) {
            $errors['ORDER_TYPE_UNSUPPORTED'] = 'Only successful Sale and Settlement Fee rows are supported.';
        }

        $status = strtolower((string) $this->value($source, 'status'));
        if ($status !== 'successful') {
            $errors['STATUS_UNSUPPORTED'] = 'Only Successful rows can be posted.';
        }

        $payoutReference = $this->bounded($this->value($source, 'paymentref'), 160);
        if ($payoutReference === null) {
            $errors['PAYOUT_REFERENCE_MISSING'] = 'The payout reference is required.';
        }
        $rowReference = $this->bounded($this->value($source, 'referencenumber'), 160);
        if ($rowReference === null) {
            $errors['ROW_REFERENCE_MISSING'] = 'The row reference is required.';
        }

        $merchant = $this->bounded($this->value($source, 'merchant'), 160);
        if ($merchant === null || strcasecmp($merchant, $configuredMerchant) !== 0) {
            $errors['MERCHANT_MISMATCH'] = 'The report merchant does not match the configured payment source.';
        }

        $branchCode = $this->bounded($this->value($source, 'branch'), 120);
        $branchId = $branchCode !== null && array_key_exists($branchCode, $branchMap)
            ? (int) $branchMap[$branchCode]
            : null;
        if ($branchId === null || $branchId <= 0) {
            $errors['BRANCH_UNMAPPED'] = 'The provider branch has no approved RMS branch mapping.';
        }

        $transactionAt = $this->parseTransactionAt(
            $this->value($source, 'transactiondate'),
            $this->value($source, 'transactiontime'),
            $timezone,
        );
        if ($transactionAt === null) {
            $errors['TRANSACTION_TIME_INVALID'] = 'The transaction date or time is invalid.';
        }

        $money = [];
        foreach ([
            'sales_cents' => 'sales',
            'gross_cents' => 'grossamount',
            'variable_commission_cents' => 'variablecommission',
            'fixed_commission_cents' => 'fixedcommission',
            'total_commission_cents' => 'totalcommission',
            'net_cents' => 'netmerchantsettlementamount',
        ] as $target => $field) {
            try {
                $money[$target] = $this->parseCents($this->value($source, $field));
            } catch (RuntimeException) {
                $money[$target] = null;
                $errors['AMOUNT_INVALID'] = 'Money values must be exact decimals with no more than two decimal places.';
            }
        }

        $settlementFeeCents = $orderType === 'settlement_fee' ? $money['total_commission_cents'] : 0;
        if ($this->moneyComplete($money)) {
            $variable = (int) $money['variable_commission_cents'];
            $fixed = (int) $money['fixed_commission_cents'];
            $commission = (int) $money['total_commission_cents'];
            $sales = (int) $money['sales_cents'];
            $gross = (int) $money['gross_cents'];
            $net = (int) $money['net_cents'];

            if ($variable < 0 || $fixed < 0 || $commission < 0 || $variable + $fixed !== $commission) {
                $errors['COMMISSION_MISMATCH'] = 'Commission components must be nonnegative and equal total commission.';
            }
            if ($orderType === 'sale' && ($sales !== $gross || $gross <= 0 || $gross - $commission !== $net)) {
                $errors['SALE_TOTAL_MISMATCH'] = 'Sale gross, commission and net do not reconcile.';
            }
            if ($orderType === 'settlement_fee'
                && ($sales !== 0 || $gross !== 0 || $commission <= 0 || $net !== -$commission)) {
                $errors['SETTLEMENT_FEE_MISMATCH'] = 'Settlement fee gross and sales must be zero and net must equal the negative fee.';
            }
        }

        $phone = $this->phoneNumbers->normalize($this->value($source, 'customerphonenumber'));

        return [
            'row_sequence' => $sequence,
            'worksheet' => $sheet,
            'physical_row' => (int) ($source['__physical_row'] ?? ($sequence + 1)),
            'period_start' => $period[0] ?? null,
            'period_end' => $period[1] ?? null,
            'order_type' => $orderType ?? $this->bounded($orderTypeRaw, 40),
            'status' => $status !== '' ? $status : null,
            'payout_reference' => $payoutReference,
            'row_reference' => $rowReference,
            'economic_identity' => null,
            'financial_content_hash' => null,
            'source_evidence' => [
                'values' => array_diff_key($source, ['__physical_row' => true]),
                'errors' => array_values($errors),
            ],
            'customer_phone' => $phone,
            'customer_phone_hash' => $phone !== null ? hash('sha256', $phone) : null,
            'merchant' => $merchant,
            'branch_code' => $branchCode,
            'branch_id' => $branchId,
            'transaction_at' => $transactionAt,
            'explicit_bank_date' => null,
            ...$money,
            'settlement_fee_cents' => $settlementFeeCents,
            'match_state' => $errors === [] && $orderType === 'sale' ? 'unmatched' : ($errors === [] ? 'not_applicable' : 'blocked'),
            'error_code' => $errors !== [] ? (string) array_key_first($errors) : null,
            'errors' => array_values($errors),
        ];
    }

    /** @return array{string,string}|null */
    private function parsePeriod(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^(\d{2}\/\d{2}\/\d{4})\s+-\s+(\d{2}\/\d{2}\/\d{4})$/', $value, $matches)) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('!d/m/Y', $matches[1]);
        $end = DateTimeImmutable::createFromFormat('!d/m/Y', $matches[2]);
        if (! $start || ! $end || $start->format('d/m/Y') !== $matches[1] || $end->format('d/m/Y') !== $matches[2] || $start > $end) {
            return null;
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function parseTransactionAt(?string $date, ?string $time, DateTimeZone $timezone): ?string
    {
        if ($date === null || $time === null) {
            return null;
        }

        $date = str_contains($date, 'T') ? substr($date, 0, 10) : $date;
        $format = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? 'Y-m-d H:i:s' : 'd-M-Y H:i:s';
        $value = $date.' '.$time;
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
        if (! $parsed || $parsed->format($format) !== $value) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseCents(?string $value): int
    {
        if ($value === null || ! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new RuntimeException('Invalid exact decimal.');
        }

        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $cents = ltrim($whole.$fraction, '0');
        $cents = $cents === '' ? '0' : $cents;
        if (strlen($cents) > strlen(self::MAX_EXACT_CENTS)
            || (strlen($cents) === strlen(self::MAX_EXACT_CENTS) && strcmp($cents, self::MAX_EXACT_CENTS) > 0)) {
            throw new RuntimeException('Amount is outside the exact integer range.');
        }

        $amount = (int) $cents;

        return ($matches[1] ?? '') === '-' ? -$amount : $amount;
    }

    /** @param array<string, ?int> $money */
    private function moneyComplete(array $money): bool
    {
        return ! in_array(null, $money, true);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw ValidationException::withMessages(['workbook' => __('The report totals are outside the supported range.')]);
        }

        $sum = $left + $right;
        if (abs($sum) > (int) self::MAX_EXACT_CENTS) {
            throw ValidationException::withMessages(['workbook' => __('The report totals are outside the exact supported range.')]);
        }

        return $sum;
    }

    private function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strcasecmp($value, 'Null') === 0 ? null : $value;
    }

    private function bounded(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
