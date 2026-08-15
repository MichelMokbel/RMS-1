<?php

namespace App\Services\HR;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\HrCompensationPackage;
use App\Models\HrPayrollPaymentBatch;
use App\Models\HrPayrollPaymentBatchItem;
use App\Models\HrPayrollRun;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\LedgerAccountMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPaymentService
{
    public function __construct(
        protected LedgerAccountMappingService $mappings,
        protected AccountingContextService $context,
        protected JournalEntryService $journals,
        protected HrAccessService $access,
        protected HrNumberService $numbers,
    ) {}

    public function pay(HrPayrollRun $run, int $bankAccountId, string $paymentDate, ?string $reference, User $actor): HrPayrollPaymentBatch
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.pay');
        if (blank($reference)) {
            throw ValidationException::withMessages(['reference' => __('A bank payment reference is required.')]);
        }

        return DB::transaction(function () use ($run, $bankAccountId, $paymentDate, $reference, $actor): HrPayrollPaymentBatch {
            $run = HrPayrollRun::query()->with('results')->lockForUpdate()->findOrFail($run->id);
            if ($this->enumValue($run->status) !== 'posted') {
                throw ValidationException::withMessages(['payroll' => __('Only a posted payroll run can be paid.')]);
            }
            if (in_array((int) $actor->id, [(int) $run->prepared_by, (int) $run->approved_by], true)) {
                throw ValidationException::withMessages(['payroll' => __('The payroll payer must differ from the preparer and approver.')]);
            }
            $existing = HrPayrollPaymentBatch::query()->where('payroll_run_id', $run->id)->where('status', 'processed')->first();
            if ($existing) {
                return $existing;
            }

            $bank = BankAccount::query()->whereKey($bankAccountId)->where('company_id', $run->company_id)
                ->where('is_active', true)->whereNotNull('ledger_account_id')->first();
            if (! $bank) {
                throw ValidationException::withMessages(['bank_account_id' => __('Select an active company bank account linked to the ledger.')]);
            }
            if (strtoupper((string) $bank->currency_code) !== strtoupper((string) $run->currency)) {
                throw ValidationException::withMessages(['bank_account_id' => __('The bank account currency must match the payroll run currency.')]);
            }
            $total = (int) $run->results->sum('net_minor');
            if ($total <= 0) {
                throw ValidationException::withMessages(['payroll' => __('Payroll payment total must be positive.')]);
            }

            $batch = HrPayrollPaymentBatch::query()->where('payroll_run_id', $run->id)->lockForUpdate()->first();
            if (! $batch) {
                $batch = HrPayrollPaymentBatch::query()->create([
                    'company_id' => $run->company_id, 'payroll_run_id' => $run->id,
                    'batch_number' => $this->numbers->paymentBatch((int) $run->company_id, $paymentDate),
                    'bank_account_id' => $bank->id, 'payment_date' => $paymentDate,
                    'currency' => $run->currency, 'total_minor' => $total, 'item_count' => $run->results->count(),
                    'status' => 'draft', 'reference' => $reference, 'created_by' => $actor->id,
                ]);
            }
            if ($this->enumValue($batch->status) === 'processed') {
                return $batch;
            }

            foreach ($run->results as $result) {
                $package = HrCompensationPackage::query()->find($result->compensation_package_id);
                if (! $package || blank($package->beneficiary_name) || (blank($package->bank_account_number) && blank($package->iban))) {
                    throw ValidationException::withMessages(["employees.{$result->employee_id}" => __('Employee bank beneficiary details are required before payment.')]);
                }
                HrPayrollPaymentBatchItem::query()->firstOrCreate(
                    ['payment_batch_id' => $batch->id, 'payroll_result_id' => $result->id],
                    ['employee_id' => $result->employee_id, 'amount_minor' => $result->net_minor,
                        'beneficiary_name' => $package?->beneficiary_name, 'bank_account_number' => $package?->bank_account_number,
                        'iban' => $package?->iban, 'payment_reference' => $reference, 'status' => 'processed']
                );
            }

            $journal = $this->paymentJournal($run, $batch, $bank, $paymentDate, $total, (int) $actor->id);
            $periodId = $this->context->resolvePeriodId($paymentDate, (int) $run->company_id);
            $transaction = BankTransaction::query()->firstOrCreate([
                'source_type' => HrPayrollPaymentBatch::class, 'source_id' => $batch->id, 'transaction_type' => 'payroll_payment',
            ], [
                'company_id' => $run->company_id, 'bank_account_id' => $bank->id, 'period_id' => $periodId,
                'transaction_date' => $paymentDate, 'amount' => $total / 100, 'direction' => 'outflow', 'status' => 'open',
                'is_cleared' => false, 'reference' => $reference, 'memo' => 'Aggregate payroll payment '.$run->run_number,
            ]);

            $batch->forceFill(['status' => 'processed', 'journal_entry_id' => $journal->id,
                'bank_transaction_id' => $transaction->id, 'processed_at' => now(), 'processed_by' => $actor->id])->save();

            return $batch->fresh(['items', 'journalEntry']);
        });
    }

    public function reverse(HrPayrollPaymentBatch $batch, User $actor, string $date, string $reason): HrPayrollPaymentBatch
    {
        $batch->loadMissing('payrollRun');
        $this->access->assertPayroll($actor, $batch->payrollRun, 'hr.payroll.reverse');

        return DB::transaction(function () use ($batch, $actor, $date, $reason): HrPayrollPaymentBatch {
            $batch = HrPayrollPaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($this->enumValue($batch->status) === 'reversed') {
                return $batch;
            }
            if ($this->enumValue($batch->status) !== 'processed') {
                throw ValidationException::withMessages(['payment' => __('Only processed payroll payments can be reversed.')]);
            }
            $reversal = $this->journals->reverse(JournalEntry::query()->findOrFail($batch->journal_entry_id), (int) $actor->id, $date, 'Payroll payment reversal: '.$reason);
            $bankTransaction = BankTransaction::query()->lockForUpdate()->findOrFail($batch->bank_transaction_id);
            if ($bankTransaction->is_cleared || $bankTransaction->reconciliation_run_id) {
                throw ValidationException::withMessages(['payment' => __('A reconciled payroll bank transaction must be unreconciled before reversal.')]);
            }
            $bankTransaction->forceFill(['status' => 'void'])->save();
            $batch->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $reversal->id,
                'reversed_at' => now(), 'reversed_by' => $actor->id])->save();

            return $batch->fresh();
        });
    }

    private function paymentJournal(HrPayrollRun $run, HrPayrollPaymentBatch $batch, BankAccount $bank, string $date, int $total, int $actorId): JournalEntry
    {
        $payable = $this->mappings->resolveAccountId('payroll.payable', (int) $run->company_id);
        if (! $payable) {
            throw ValidationException::withMessages(['account_mappings' => __('Payroll payable account mapping is missing.')]);
        }
        $existing = JournalEntry::query()->where('source_type', HrPayrollPaymentBatch::class)->where('source_id', $batch->id)->where('entry_type', 'payroll_payment')->first();
        if ($existing?->status === 'posted') {
            return $existing;
        }
        $payload = ['company_id' => $run->company_id, 'entry_type' => 'payroll_payment', 'entry_date' => $date,
            'memo' => 'Aggregate payroll payment '.$run->run_number, 'lines' => [
                ['account_id' => $payable, 'debit' => $total / 100, 'credit' => 0, 'memo' => 'Clear net payroll payable'],
                ['account_id' => $bank->ledger_account_id, 'debit' => 0, 'credit' => $total / 100, 'memo' => 'Payroll bank payment'],
            ]];
        $journal = $this->journals->saveDraft($payload, $actorId, $existing);
        if (! $existing) {
            $journal->forceFill(['source_type' => HrPayrollPaymentBatch::class, 'source_id' => $batch->id])->save();
        }

        return $this->journals->post($journal, $actorId);
    }

    private function enumValue(mixed $value): string
    {
        return is_object($value) && isset($value->value) ? (string) $value->value : (string) $value;
    }
}
