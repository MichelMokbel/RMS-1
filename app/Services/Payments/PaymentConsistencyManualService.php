<?php

namespace App\Services\Payments;

use App\Models\AccountingAuditLog;
use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentConsistencyManualService
{
    public function __construct(
        private readonly PaymentConsistencyAccessService $access,
        private readonly PaymentConsistencyDispatchService $dispatcher,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    /** @return array{queued:bool,replayed:bool} */
    public function recheck(PaymentConsistencyFinding $finding, User $actor, string $operationUuid): array
    {
        $this->access->assertCanRunFinding($actor, $finding);
        if (! (bool) config('payment_consistency.enabled', false)) {
            throw ValidationException::withMessages([
                'consistency' => __('Payment consistency checks are disabled.'),
            ]);
        }
        if (! Str::isUuid($operationUuid) || strtolower($operationUuid) !== $operationUuid) {
            throw ValidationException::withMessages(['operation_uuid' => __('A valid operation identifier is required.')]);
        }
        $fingerprint = hash('sha256', implode('|', [
            $finding->id, $finding->rule_code, $finding->subject_type, $finding->subject_id, $finding->evidence_fingerprint,
        ]));
        $replayed = false;

        DB::transaction(function () use ($finding, $actor, $operationUuid, $fingerprint, &$replayed): void {
            $locked = PaymentConsistencyFinding::query()->lockForUpdate()->findOrFail($finding->id);
            $prior = AccountingAuditLog::query()
                ->whereIn('action', [
                    'payment_consistency.manual_recheck_requested',
                    'payment_consistency.manual_recheck_queued',
                ])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->lockForUpdate()->get();
            $first = $prior->first();
            if ($first) {
                if (! hash_equals((string) ($first->payload['input_fingerprint'] ?? ''), $fingerprint)) {
                    throw ValidationException::withMessages([
                        'operation_uuid' => __('This operation identifier was already used for a different recheck.'),
                    ]);
                }
                $replayed = $prior->contains('action', 'payment_consistency.manual_recheck_queued');

                return;
            }
            $this->auditLog->log('payment_consistency.manual_recheck_requested', (int) $actor->id, $locked, [
                'operation_uuid' => $operationUuid,
                'input_fingerprint' => $fingerprint,
                'rule_code' => $locked->rule_code,
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'episode_uuid' => $locked->episode_uuid,
            ], (int) $locked->company_id);
        }, 3);

        if ($replayed) {
            return ['queued' => true, 'replayed' => true];
        }
        $triggerKey = substr('manual:'.$operationUuid.':'.$finding->rule_code.':'.$finding->id, 0, 190);
        $queued = $this->dispatcher->dispatchRule(
            (string) $finding->rule_code,
            (string) $finding->subject_type,
            (int) $finding->subject_id,
            PaymentConsistencyRun::KIND_MANUAL,
            $triggerKey,
            (int) $actor->id,
        );
        if (! $queued) {
            throw ValidationException::withMessages(['consistency' => __('The consistency recheck could not be queued.')]);
        }

        DB::transaction(function () use ($finding, $actor, $operationUuid, $fingerprint): void {
            $locked = PaymentConsistencyFinding::query()->lockForUpdate()->findOrFail($finding->id);
            $alreadyRecorded = AccountingAuditLog::query()
                ->where('action', 'payment_consistency.manual_recheck_queued')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->exists();
            if (! $alreadyRecorded) {
                $this->auditLog->log('payment_consistency.manual_recheck_queued', (int) $actor->id, $locked, [
                    'operation_uuid' => $operationUuid,
                    'input_fingerprint' => $fingerprint,
                    'rule_code' => $locked->rule_code,
                    'subject_type' => $locked->subject_type,
                    'subject_id' => (int) $locked->subject_id,
                    'episode_uuid' => $locked->episode_uuid,
                ], (int) $locked->company_id);
            }
        }, 3);

        return ['queued' => true, 'replayed' => false];
    }
}
