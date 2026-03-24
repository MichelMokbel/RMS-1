<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BankStatementImportService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function import(BankAccount $bankAccount, UploadedFile $file, int $actorId): array
    {
        $path = $file->store('bank-statements');

        $import = BankStatementImport::query()->create([
            'bank_account_id' => $bankAccount->id,
            'company_id' => $bankAccount->company_id,
            'file_name' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'imported_rows' => 0,
            'status' => 'processing',
            'processed_at' => null,
            'uploaded_by' => $actorId,
        ]);

        $rows = $this->parseCsv(Storage::path($path));
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $bankAccount, $import, &$created, &$skipped) {
            foreach ($rows as $row) {
                $normalized = $this->normalizeRow($row);
                if ($normalized === null) {
                    $skipped++;
                    continue;
                }

                $periodId = $this->context->resolvePeriodId($normalized['transaction_date'], (int) $bankAccount->company_id);

                $duplicate = BankTransaction::query()
                    ->where('bank_account_id', $bankAccount->id)
                    ->whereNotNull('statement_import_id')
                    ->whereDate('transaction_date', $normalized['transaction_date'])
                    ->where('amount', $normalized['amount'])
                    ->where('direction', $normalized['direction'])
                    ->where('reference', $normalized['reference'])
                    ->exists();

                if ($duplicate) {
                    $skipped++;
                    continue;
                }

                BankTransaction::query()->create([
                    'company_id' => $bankAccount->company_id,
                    'bank_account_id' => $bankAccount->id,
                    'period_id' => $periodId,
                    'reconciliation_run_id' => null,
                    'transaction_type' => 'statement_line',
                    'transaction_date' => $normalized['transaction_date'],
                    'amount' => $normalized['amount'],
                    'direction' => $normalized['direction'],
                    'status' => 'open',
                    'is_cleared' => false,
                    'cleared_date' => null,
                    'reference' => $normalized['reference'],
                    'memo' => $normalized['memo'],
                    'source_type' => null,
                    'source_id' => null,
                    'statement_import_id' => $import->id,
                ]);

                $created++;
            }

            $import->forceFill([
                'imported_rows' => $created,
                'status' => 'processed',
                'processed_at' => now(),
            ])->save();
        });

        $this->auditLog->log('bank_statement.imported', $actorId, $import, [
            'bank_account_id' => (int) $bankAccount->id,
            'created_rows' => $created,
            'skipped_rows' => $skipped,
        ], (int) $bankAccount->company_id);

        return [
            'import' => $import->fresh(['transactions']),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw ValidationException::withMessages([
                'statement_file' => __('Unable to open the statement file.'),
            ]);
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn (?string $header) => $this->normalizeHeader((string) $header), $data);
                continue;
            }

            if ($data === [null] || $data === false) {
                continue;
            }

            $rows[] = array_combine($headers, array_map(fn ($value) => trim((string) $value), $data)) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $date = $row['date']
            ?? $row['transaction_date']
            ?? $row['posted_date']
            ?? null;

        if (! $date) {
            return null;
        }

        try {
            $transactionDate = Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        $direction = strtolower((string) ($row['direction'] ?? ''));
        $amount = null;

        if (($row['amount'] ?? null) !== null && $row['amount'] !== '') {
            $rawAmount = (float) str_replace(',', '', (string) $row['amount']);
            $direction = $direction !== '' ? $direction : ($rawAmount < 0 ? 'outflow' : 'inflow');
            $amount = abs($rawAmount);
        } else {
            $debit = (float) str_replace(',', '', (string) ($row['debit'] ?? 0));
            $credit = (float) str_replace(',', '', (string) ($row['credit'] ?? 0));

            if ($debit > 0) {
                $amount = $debit;
                $direction = $direction !== '' ? $direction : 'outflow';
            } elseif ($credit > 0) {
                $amount = $credit;
                $direction = $direction !== '' ? $direction : 'inflow';
            }
        }

        if (! $amount || $amount <= 0) {
            return null;
        }

        return [
            'transaction_date' => $transactionDate,
            'amount' => round($amount, 2),
            'direction' => in_array($direction, ['inflow', 'outflow'], true) ? $direction : 'outflow',
            'reference' => $row['reference'] ?? $row['check_number'] ?? $row['transaction_id'] ?? null,
            'memo' => $row['memo'] ?? $row['description'] ?? $row['details'] ?? null,
        ];
    }
}
