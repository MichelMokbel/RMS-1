<?php

namespace App\Services\Payments;

use App\Models\GatewaySettlementImport;
use App\Models\GatewaySettlementRow;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GatewaySettlementImportService
{
    public function __construct(
        private readonly SkipCashSettlementReportParser $parser,
        private readonly AccountingContextService $accountingContext,
        private readonly AccountingAuditLogService $audit,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    public function stage(UploadedFile $workbook, PaymentSource $source, User $actor): GatewaySettlementImport
    {
        $this->assertEnabled();
        $this->assertAccess($actor);
        $this->assertSource($source);
        $this->assertUpload($workbook);

        $fileHash = hash_file('sha256', $workbook->getRealPath());
        if (! is_string($fileHash) || $fileHash === '') {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook checksum could not be calculated.'),
            ]);
        }

        $existing = GatewaySettlementImport::query()
            ->where('payment_source_id', $source->id)
            ->where('file_hash', $fileHash)
            ->first();
        if ($existing) {
            throw new GatewaySettlementDuplicateImportException($existing);
        }

        $profile = config('skipcash.report_profiles.'.strtolower((string) $source->code));
        if (! is_array($profile)) {
            throw ValidationException::withMessages([
                'payment_source_id' => __('No settlement report profile is configured for this payment source.'),
            ]);
        }
        $parsed = $this->parser->parse($workbook->getRealPath(), $profile);
        if (! $actor->isAdmin()) {
            $allowedBranches = $actor->allowedBranchIds();
            $hasUnavailableBranch = collect($parsed['rows'])->contains(
                fn (array $row): bool => ! isset($row['branch_id'])
                    || ! in_array((int) $row['branch_id'], $allowedBranches, true),
            );
            if ($allowedBranches === [] || $hasUnavailableBranch) {
                throw new AuthorizationException('The settlement report includes a branch you cannot access.');
            }
        }

        $disk = (string) config('skipcash.settlements.private_disk', 'local');
        $root = 'gateway-settlements/'.$source->company_id.'/'.$source->id.'/imports';
        $objectKey = $root.'/'.Str::uuid().'.xlsx';
        $stored = Storage::disk($disk)->putFileAs(
            $root,
            $workbook,
            basename($objectKey),
            ['visibility' => 'private'],
        );
        if ($stored !== $objectKey) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook could not be stored securely.'),
            ]);
        }

        try {
            $import = DB::transaction(function () use ($workbook, $source, $actor, $fileHash, $parsed, $profile, $disk, $objectKey): GatewaySettlementImport {
                PaymentSource::query()->lockForUpdate()->findOrFail($source->id);
                $currency = strtoupper(trim((string) ($profile['currency'] ?? '')));
                $timezone = trim((string) ($profile['timezone'] ?? ''));
                $import = GatewaySettlementImport::query()->create([
                    'company_id' => (int) $source->company_id,
                    'payment_source_id' => (int) $source->id,
                    'imported_by' => (int) $actor->id,
                    'private_disk' => $disk,
                    'object_key' => $objectKey,
                    'original_name' => Str::limit(basename($workbook->getClientOriginalName()), 255, ''),
                    'file_hash' => $fileHash,
                    'parser_version' => (string) config('skipcash.settlements.parser_version', 'skipcash-report-v1'),
                    'currency' => $currency,
                    'timezone' => $timezone,
                    'report_period_start' => $parsed['period_start'],
                    'report_period_end' => $parsed['period_end'],
                    ...$parsed['totals'],
                    'totals_complete' => $parsed['totals_complete'],
                    'review_state' => $parsed['totals_complete'] ? 'draft' : 'blocked',
                    'posting_state' => 'unposted',
                    'revision' => 1,
                    'error_code' => $parsed['totals_complete'] ? null : 'ROW_REVIEW_REQUIRED',
                ]);

                $duplicateCount = 0;
                $conflictCount = 0;
                foreach ($parsed['rows'] as $row) {
                    unset($row['period_start'], $row['period_end'], $row['errors']);
                    $row = $this->withSourceScopedIdentity($row, (int) $source->id, $currency);
                    $existingRow = $row['error_code'] === null && $row['economic_identity'] !== null
                        ? GatewaySettlementRow::query()
                            ->where('payment_source_id', $source->id)
                            ->where('economic_identity', $row['economic_identity'])
                            ->orderBy('id')
                            ->first()
                        : null;
                    if ($existingRow && $this->isExactEconomicDuplicate($existingRow, $row)) {
                        $row['duplicate_of_row_id'] = (int) ($existingRow->duplicate_of_row_id ?: $existingRow->id);
                        $row['match_state'] = 'duplicate';
                        $duplicateCount++;
                    } elseif ($existingRow) {
                        $row['duplicate_of_row_id'] = (int) ($existingRow->duplicate_of_row_id ?: $existingRow->id);
                        $row['match_state'] = 'conflict';
                        $row['error_code'] = 'ECONOMIC_IDENTITY_CONFLICT';
                        $evidence = $row['source_evidence'];
                        $evidence['errors'][] = 'This economic row conflicts with previously imported financial evidence.';
                        $row['source_evidence'] = $evidence;
                        $conflictCount++;
                    }
                    GatewaySettlementRow::query()->create([
                        'import_id' => (int) $import->id,
                        'payment_source_id' => (int) $source->id,
                        ...$row,
                    ]);
                }

                if ($conflictCount > 0) {
                    $import->forceFill([
                        'review_state' => 'blocked',
                        'error_code' => 'ECONOMIC_IDENTITY_CONFLICT',
                    ])->save();
                }

                $this->audit->log(
                    $parsed['totals_complete']
                        ? 'gateway_settlement_import.staged'
                        : 'gateway_settlement_import.blocked',
                    (int) $actor->id,
                    $import,
                    [
                        'payment_source_id' => (int) $source->id,
                        'file_hash' => $fileHash,
                        'row_count' => count($parsed['rows']),
                        'duplicate_count' => $duplicateCount,
                        'conflict_count' => $conflictCount,
                        'gross_cents' => $parsed['totals']['gross_cents'],
                        'commission_cents' => $parsed['totals']['commission_cents'],
                        'settlement_fee_cents' => $parsed['totals']['settlement_fee_cents'],
                        'net_cents' => $parsed['totals']['net_cents'],
                    ],
                    (int) $source->company_id,
                );

                return $import->fresh(['rows']);
            });
            $this->paymentConsistency->settlementAfterCommit(
                (int) $import->id,
                'gateway_settlement_import',
                (int) $import->id,
                'staged',
            );

            return $import;
        } catch (QueryException $exception) {
            Storage::disk($disk)->delete($objectKey);
            $existing = GatewaySettlementImport::query()
                ->where('payment_source_id', $source->id)
                ->where('file_hash', $fileHash)
                ->first();
            if ($existing) {
                throw new GatewaySettlementDuplicateImportException($existing);
            }
            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    private function assertEnabled(): void
    {
        if (! config('skipcash.settlements.enabled', false)) {
            throw ValidationException::withMessages([
                'settlements' => __('SkipCash settlement imports are disabled.'),
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function isExactEconomicDuplicate(GatewaySettlementRow $existing, array $row): bool
    {
        $financialHash = (string) ($row['financial_content_hash'] ?? '');

        return $financialHash !== ''
            && hash_equals((string) $existing->financial_content_hash, $financialHash)
            && (int) $existing->branch_id === (int) ($row['branch_id'] ?? 0)
            && hash_equals(strtolower((string) $existing->merchant), strtolower((string) ($row['merchant'] ?? '')))
            && hash_equals((string) $existing->status, (string) ($row['status'] ?? ''))
            && hash_equals((string) $existing->order_type, (string) ($row['order_type'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withSourceScopedIdentity(array $row, int $paymentSourceId, string $currency): array
    {
        $payoutReference = $row['payout_reference'] ?? null;
        $rowReference = $row['row_reference'] ?? null;
        $orderType = $row['order_type'] ?? null;
        if ($payoutReference === null || $rowReference === null || $orderType === null) {
            return $row;
        }

        $row['economic_identity'] = hash('sha256', json_encode([
            'version' => 'skipcash-economic-v1',
            'payment_source_id' => $paymentSourceId,
            'order_type' => $orderType,
            'payout_reference' => $payoutReference,
            'row_reference' => $rowReference,
        ], JSON_THROW_ON_ERROR));

        foreach ([
            'sales_cents',
            'gross_cents',
            'variable_commission_cents',
            'fixed_commission_cents',
            'total_commission_cents',
            'settlement_fee_cents',
            'net_cents',
        ] as $field) {
            if (! isset($row[$field])) {
                return $row;
            }
        }

        $sourceValues = $row['source_evidence']['values'] ?? [];
        $row['financial_content_hash'] = hash('sha256', json_encode([
            'version' => 'skipcash-financial-v1',
            'payment_source_id' => $paymentSourceId,
            'merchant' => strtolower((string) ($row['merchant'] ?? '')),
            'branch_code' => (string) ($row['branch_code'] ?? ''),
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'order_type' => $orderType,
            'status' => (string) ($row['status'] ?? ''),
            'payout_reference' => $payoutReference,
            'row_reference' => $rowReference,
            'order_id' => $sourceValues['orderid'] ?? null,
            'transaction_at' => $row['transaction_at'] ?? null,
            'currency' => $currency,
            'sales_cents' => (int) $row['sales_cents'],
            'gross_cents' => (int) $row['gross_cents'],
            'variable_commission_cents' => (int) $row['variable_commission_cents'],
            'fixed_commission_cents' => (int) $row['fixed_commission_cents'],
            'total_commission_cents' => (int) $row['total_commission_cents'],
            'settlement_fee_cents' => (int) $row['settlement_fee_cents'],
            'net_cents' => (int) $row['net_cents'],
            'method' => $sourceValues['method'] ?? null,
            'payment_method' => $sourceValues['paymentmethod'] ?? null,
            'skipcash_coupon' => $sourceValues['skipcashcoupon'] ?? null,
            'variable_commission_percentage' => $sourceValues['variablecommissionpercentage'] ?? null,
            'fixed_commission_rate' => $sourceValues['fixedcommissionrate'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return $row;
    }

    private function assertAccess(User $actor): void
    {
        if (! $actor->can('gateway_settlements.import')) {
            throw new AuthorizationException('You are not allowed to import gateway settlements.');
        }
    }

    private function assertSource(PaymentSource $source): void
    {
        $companyId = $this->accountingContext->defaultCompanyId();
        if ($source->method !== PaymentSource::METHOD_SKIPCASH
            || $companyId === null
            || (int) $source->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'payment_source_id' => __('Choose the default company SkipCash payment source.'),
            ]);
        }
    }

    private function assertUpload(UploadedFile $workbook): void
    {
        if (! $workbook->isValid() || strtolower($workbook->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages([
                'workbook' => __('Upload a valid XLSX workbook.'),
            ]);
        }

        $maxKb = max(1, (int) config('skipcash.settlements.max_upload_kb', 10_240));
        if ((int) ceil(((int) $workbook->getSize()) / 1024) > $maxKb) {
            throw ValidationException::withMessages([
                'workbook' => __('The workbook may not exceed :size MB.', [
                    'size' => round($maxKb / 1024),
                ]),
            ]);
        }
    }
}
