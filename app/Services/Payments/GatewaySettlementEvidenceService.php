<?php

namespace App\Services\Payments;

use App\Models\ArClearingSettlement;
use App\Models\BankAccount;
use App\Models\GatewaySettlementImport;
use App\Models\GatewaySettlementRow;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Support\Imports\SafeSpreadsheetReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GatewaySettlementEvidenceService
{
    private const PROVIDER_FIELDS = [
        'provider_payment_id',
        'merchant_transaction_id',
        'visa_id',
    ];

    public function __construct(
        private readonly GatewaySettlementReviewService $reviews,
        private readonly SafeSpreadsheetReader $spreadsheetReader,
        private readonly AccountingAuditLogService $audit,
        private readonly AccountingContextService $accountingContext,
    ) {}

    /** @return array<string, mixed> */
    public function storeProviderReference(
        GatewaySettlementImport $import,
        GatewaySettlementRow $row,
        UploadedFile $file,
        string $providerField,
        string $providerValue,
        int $importRevision,
        int $rowRevision,
        User $actor,
    ): array {
        $this->assertEnabled();
        $this->reviews->authorizeImport($import, $actor);
        $providerField = strtolower(trim($providerField));
        $providerValue = trim($providerValue);
        if (! in_array($providerField, self::PROVIDER_FIELDS, true) || $providerValue === '' || mb_strlen($providerValue) > 160) {
            throw ValidationException::withMessages([
                'provider_value' => __('Choose a supported provider field and enter its exact value.'),
            ]);
        }
        $fileMetadata = $this->validateFile($file);
        $evidenceId = (string) Str::uuid();
        $disk = (string) config('skipcash.settlements.private_disk', 'local');
        $extension = $fileMetadata['extension'];
        $root = 'gateway-settlements/'.$import->company_id.'/'.$import->payment_source_id.'/evidence';
        $objectKey = $root.'/'.$evidenceId.'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs($root, $file, basename($objectKey), ['visibility' => 'private']);
        if ($stored !== $objectKey) {
            throw ValidationException::withMessages([
                'evidence_file' => __('The evidence file could not be stored securely.'),
            ]);
        }

        try {
            return DB::transaction(function () use (
                $import,
                $row,
                $file,
                $fileMetadata,
                $providerField,
                $providerValue,
                $importRevision,
                $rowRevision,
                $actor,
                $evidenceId,
                $disk,
                $objectKey,
            ): array {
                PaymentSource::query()->lockForUpdate()->findOrFail($import->payment_source_id);
                $import = GatewaySettlementImport::query()->lockForUpdate()->findOrFail($import->id);
                $row = GatewaySettlementRow::query()
                    ->where('import_id', $import->id)
                    ->lockForUpdate()
                    ->findOrFail($row->id);
                if ((int) $import->revision !== $importRevision || (int) $row->revision !== $rowRevision) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The displayed settlement data has changed. Refresh and try again.');
                }
                if ($row->order_type !== 'sale' || $row->error_code !== null || $row->duplicate_of_row_id !== null || $row->row_reference === null) {
                    throw ValidationException::withMessages([
                        'row' => __('Provider evidence can only be attached to an original valid Sale row.'),
                    ]);
                }
                if (ArClearingSettlement::query()
                    ->where('payment_source_id', $import->payment_source_id)
                    ->where('payout_reference', $row->payout_reference)
                    ->exists()) {
                    throw new GatewaySettlementConflictException('SETTLEMENT_ALREADY_POSTED', 'The payout already has settlement history.');
                }

                $entry = [
                    'id' => $evidenceId,
                    'purpose' => 'provider_reference',
                    'payout_reference' => $row->payout_reference,
                    'row_id' => (int) $row->id,
                    'report_field' => 'referenceNumber',
                    'report_value' => $row->row_reference,
                    'provider_field' => $providerField,
                    'provider_value' => $providerValue,
                    'private_disk' => $disk,
                    'object_key' => $objectKey,
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    'mime_type' => $fileMetadata['mime_type'],
                    'byte_count' => (int) $file->getSize(),
                    'sha256' => $fileMetadata['sha256'],
                    'uploaded_by' => (int) $actor->id,
                    'uploaded_at' => now()->toISOString(),
                ];
                $manifest = $import->evidence_manifest ?? [];
                $manifest[$evidenceId] = $entry;
                $import->forceFill(['evidence_manifest' => $manifest])->save();
                $row->forceFill(['revision' => (int) $row->revision + 1])->save();
                $this->reviews->invalidatePayoutReviews($row);

                $this->audit->log('gateway_settlement_evidence.uploaded', (int) $actor->id, $import, [
                    'evidence_id' => $evidenceId,
                    'purpose' => 'provider_reference',
                    'payout_reference' => $row->payout_reference,
                    'row_id' => (int) $row->id,
                    'provider_field' => $providerField,
                    'sha256' => $entry['sha256'],
                ], (int) $import->company_id);

                return $this->publicEntry($entry, (int) $import->fresh()->revision, (int) $row->revision);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function storeBankRemittance(
        GatewaySettlementImport $import,
        UploadedFile $file,
        string $payoutReference,
        int $amountCents,
        string $bankDate,
        int $importRevision,
        User $actor,
    ): array {
        $this->assertEnabled();
        $this->reviews->authorizeImport($import, $actor);
        $payoutReference = trim($payoutReference);
        if ($payoutReference === '' || mb_strlen($payoutReference) > 160 || $amountCents <= 0) {
            throw ValidationException::withMessages([
                'payout_reference' => __('Choose a valid payout and enter the positive amount shown in the remittance evidence.'),
            ]);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $bankDate, new DateTimeZone((string) $import->timezone));
        if (! $date || $date->format('Y-m-d') !== $bankDate) {
            throw ValidationException::withMessages([
                'bank_date' => __('Enter the bank date shown in the remittance evidence.'),
            ]);
        }

        $fileMetadata = $this->validateFile($file);
        $evidenceId = (string) Str::uuid();
        $disk = (string) config('skipcash.settlements.private_disk', 'local');
        $extension = $fileMetadata['extension'];
        $root = 'gateway-settlements/'.$import->company_id.'/'.$import->payment_source_id.'/evidence';
        $objectKey = $root.'/'.$evidenceId.'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs($root, $file, basename($objectKey), ['visibility' => 'private']);
        if ($stored !== $objectKey) {
            throw ValidationException::withMessages([
                'evidence_file' => __('The evidence file could not be stored securely.'),
            ]);
        }

        try {
            return DB::transaction(function () use (
                $import,
                $file,
                $fileMetadata,
                $payoutReference,
                $amountCents,
                $bankDate,
                $importRevision,
                $actor,
                $evidenceId,
                $disk,
                $objectKey,
            ): array {
                PaymentSource::query()->lockForUpdate()->findOrFail($import->payment_source_id);
                $import = GatewaySettlementImport::query()->lockForUpdate()->findOrFail($import->id);
                if ((int) $import->revision !== $importRevision) {
                    throw new GatewaySettlementConflictException('REVIEW_STALE', 'The displayed settlement data has changed. Refresh and try again.');
                }
                if (! $import->rows()->where('payout_reference', $payoutReference)->exists()) {
                    throw ValidationException::withMessages([
                        'payout_reference' => __('The payout does not belong to this report.'),
                    ]);
                }
                $rows = GatewaySettlementRow::query()
                    ->where('payment_source_id', $import->payment_source_id)
                    ->where('payout_reference', $payoutReference)
                    ->orderBy('id')
                    ->get();
                $this->reviews->authorizePayoutRows($rows, $actor);
                $summary = $this->reviews->summarizeLockedRows($rows, $payoutReference);
                if ($amountCents !== (int) $summary['net_cents']) {
                    throw ValidationException::withMessages([
                        'amount_cents' => __('The remittance amount must equal the exact payout net amount.'),
                    ]);
                }
                if (ArClearingSettlement::query()
                    ->where('payment_source_id', $import->payment_source_id)
                    ->where('payout_reference', $payoutReference)
                    ->exists()) {
                    throw new GatewaySettlementConflictException('SETTLEMENT_ALREADY_POSTED', 'The payout already has settlement history.');
                }

                $bankAccountId = $this->accountingContext->defaultBankAccountId((int) $import->company_id);
                $bankAccount = $bankAccountId ? BankAccount::query()->lockForUpdate()->find($bankAccountId) : null;
                if (! $bankAccount
                    || ! $bankAccount->is_active
                    || (int) $bankAccount->company_id !== (int) $import->company_id
                    || strtoupper((string) $bankAccount->currency_code) !== (string) $import->currency) {
                    throw ValidationException::withMessages([
                        'bank_account' => __('The default company bank account is not ready for SkipCash settlements.'),
                    ]);
                }

                $entry = [
                    'id' => $evidenceId,
                    'purpose' => 'bank_remittance',
                    'payout_reference' => $payoutReference,
                    'bank_account_id' => (int) $bankAccount->id,
                    'amount_cents' => $amountCents,
                    'bank_date' => $bankDate,
                    'private_disk' => $disk,
                    'object_key' => $objectKey,
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    'mime_type' => $fileMetadata['mime_type'],
                    'byte_count' => (int) $file->getSize(),
                    'sha256' => $fileMetadata['sha256'],
                    'uploaded_by' => (int) $actor->id,
                    'uploaded_at' => now()->toISOString(),
                ];
                $manifest = $import->evidence_manifest ?? [];
                $manifest[$evidenceId] = $entry;
                $import->forceFill(['evidence_manifest' => $manifest])->save();
                $this->reviews->invalidatePayoutReviews($rows->firstOrFail());

                $this->audit->log('gateway_settlement_evidence.uploaded', (int) $actor->id, $import, [
                    'evidence_id' => $evidenceId,
                    'purpose' => 'bank_remittance',
                    'payout_reference' => $payoutReference,
                    'bank_account_id' => (int) $bankAccount->id,
                    'amount_cents' => $amountCents,
                    'bank_date' => $bankDate,
                    'sha256' => $entry['sha256'],
                ], (int) $import->company_id);

                return $this->publicBankEntry($entry, (int) $import->fresh()->revision);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($objectKey);
            throw $exception;
        }
    }

    /** @return array{private_disk:string,object_key:string,original_name:string,mime_type:string,sha256:string} */
    public function downloadMetadata(GatewaySettlementImport $import, string $evidenceId, User $actor): array
    {
        $this->reviews->authorizeImport($import, $actor);
        $entry = ($import->evidence_manifest ?? [])[$evidenceId] ?? null;
        if (! is_array($entry)
            || ! in_array(($entry['purpose'] ?? null), ['provider_reference', 'bank_remittance'], true)
            || empty($entry['private_disk'])
            || empty($entry['object_key'])) {
            abort(404);
        }
        $disk = (string) $entry['private_disk'];
        $objectKey = (string) $entry['object_key'];
        abort_unless(Storage::disk($disk)->exists($objectKey), 404);

        $this->audit->log('gateway_settlement_evidence.file_accessed', (int) $actor->id, $import, [
            'evidence_id' => $evidenceId,
            'purpose' => (string) $entry['purpose'],
            'sha256' => (string) ($entry['sha256'] ?? ''),
        ], (int) $import->company_id);

        return [
            'private_disk' => $disk,
            'object_key' => $objectKey,
            'original_name' => basename((string) ($entry['original_name'] ?? 'settlement-evidence')),
            'mime_type' => (string) ($entry['mime_type'] ?? 'application/octet-stream'),
            'sha256' => (string) ($entry['sha256'] ?? ''),
        ];
    }

    /** @return array{extension:string,mime_type:string,sha256:string} */
    private function validateFile(UploadedFile $file): array
    {
        $maxKb = max(1, (int) config('skipcash.settlements.max_upload_kb', 10_240));
        if (! $file->isValid() || (int) ceil(((int) $file->getSize()) / 1024) > $maxKb) {
            throw ValidationException::withMessages([
                'evidence_file' => __('Upload a valid evidence file no larger than :size MB.', ['size' => round($maxKb / 1024)]),
            ]);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        $mimeType = match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-') ? 'application/pdf' : null,
            'png' => str_starts_with($head, "\x89PNG\r\n\x1a\n") ? 'image/png' : null,
            'jpg', 'jpeg' => str_starts_with($head, "\xff\xd8\xff") ? 'image/jpeg' : null,
            'xlsx' => $this->validateSpreadsheetEvidence($file),
            default => null,
        };
        if ($mimeType === null) {
            throw ValidationException::withMessages([
                'evidence_file' => __('Evidence must be a valid PDF, PNG, JPEG, or XLSX file.'),
            ]);
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        if (! is_string($sha256) || $sha256 === '') {
            throw ValidationException::withMessages([
                'evidence_file' => __('The evidence file checksum could not be calculated.'),
            ]);
        }

        return [
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'mime_type' => $mimeType,
            'sha256' => $sha256,
        ];
    }

    private function validateSpreadsheetEvidence(UploadedFile $file): ?string
    {
        try {
            $this->spreadsheetReader->workbook($file->getRealPath());
        } catch (RuntimeException) {
            return null;
        }

        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /** @param array<string, mixed> $entry */
    private function publicEntry(array $entry, int $importRevision, int $rowRevision): array
    {
        return [
            'id' => $entry['id'],
            'purpose' => $entry['purpose'],
            'payout_reference' => $entry['payout_reference'],
            'row_id' => $entry['row_id'],
            'report_field' => $entry['report_field'],
            'provider_field' => $entry['provider_field'],
            'original_name' => $entry['original_name'],
            'mime_type' => $entry['mime_type'],
            'byte_count' => $entry['byte_count'],
            'sha256' => $entry['sha256'],
            'uploaded_at' => $entry['uploaded_at'],
            'import_revision' => $importRevision,
            'row_revision' => $rowRevision,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function publicBankEntry(array $entry, int $importRevision): array
    {
        return [
            'id' => $entry['id'],
            'purpose' => $entry['purpose'],
            'payout_reference' => $entry['payout_reference'],
            'bank_account_id' => $entry['bank_account_id'],
            'amount_cents' => $entry['amount_cents'],
            'bank_date' => $entry['bank_date'],
            'original_name' => $entry['original_name'],
            'mime_type' => $entry['mime_type'],
            'byte_count' => $entry['byte_count'],
            'sha256' => $entry['sha256'],
            'uploaded_at' => $entry['uploaded_at'],
            'import_revision' => $importRevision,
        ];
    }

    private function assertEnabled(): void
    {
        if (! config('skipcash.settlements.enabled', false)) {
            throw ValidationException::withMessages([
                'settlements' => __('SkipCash settlement mutations are disabled.'),
            ]);
        }
    }
}
