<?php

namespace App\Services\Payments;

use App\Models\MembershipPromotion;
use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Promotions\PromotionUsageConsistencyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentConsistencyService
{
    public function __construct(
        private readonly PromotionUsageConsistencyRule $promotionRule,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    public function checkPromotion(
        int|MembershipPromotion $promotion,
        string $kind = PaymentConsistencyRun::KIND_TARGETED,
        ?int $requestedBy = null,
        ?string $triggerKey = null,
    ): PaymentConsistencyRun {
        $promotion = $promotion instanceof MembershipPromotion
            ? $promotion
            : MembershipPromotion::query()->findOrFail($promotion);
        $triggerKey ??= 'promotion:'.$promotion->id.':'.Str::uuid();
        $run = $this->createRun($promotion, $kind, $requestedBy, $triggerKey);
        if ($run->state !== PaymentConsistencyRun::STATE_QUEUED) {
            return $run;
        }

        $run->update([
            'state' => PaymentConsistencyRun::STATE_RUNNING,
            'started_at' => now('UTC'),
            'heartbeat_at' => now('UTC'),
        ]);

        try {
            $result = $this->promotionRule->evaluate($promotion->fresh());

            return $this->persistResult($run, $promotion, $result);
        } catch (Throwable $exception) {
            $run->update([
                'state' => PaymentConsistencyRun::STATE_FAILED,
                'heartbeat_at' => now('UTC'),
                'completed_at' => now('UTC'),
                'error_code' => $this->boundedErrorCode($exception),
            ]);

            throw $exception;
        }
    }

    private function createRun(
        MembershipPromotion $promotion,
        string $kind,
        ?int $requestedBy,
        string $triggerKey,
    ): PaymentConsistencyRun {
        if (! in_array($kind, [
            PaymentConsistencyRun::KIND_TARGETED,
            PaymentConsistencyRun::KIND_CATCHUP,
            PaymentConsistencyRun::KIND_FULL,
            PaymentConsistencyRun::KIND_MANUAL,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported consistency run kind.');
        }
        if ($triggerKey === '' || mb_strlen($triggerKey) > 190) {
            throw new \InvalidArgumentException('A bounded consistency trigger key is required.');
        }

        return DB::transaction(function () use ($promotion, $kind, $requestedBy, $triggerKey): PaymentConsistencyRun {
            $existing = PaymentConsistencyRun::query()
                ->where('company_id', $promotion->company_id)
                ->where('trigger_key', $triggerKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }
            $versions = [PromotionUsageConsistencyRule::CODE => PromotionUsageConsistencyRule::VERSION];

            return PaymentConsistencyRun::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $promotion->company_id,
                'branch_id' => null,
                'kind' => $kind,
                'state' => PaymentConsistencyRun::STATE_QUEUED,
                'requested_by' => $requestedBy,
                'rule_code' => PromotionUsageConsistencyRule::CODE,
                'rule_versions' => $versions,
                'registry_hash' => hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR)),
                'trigger_key' => $triggerKey,
                'target_type' => PromotionUsageConsistencyRule::SUBJECT_TYPE,
                'target_id' => $promotion->id,
            ]);
        }, 3);
    }

    /** @param array<string,mixed> $result */
    private function persistResult(
        PaymentConsistencyRun $run,
        MembershipPromotion $promotion,
        array $result,
    ): PaymentConsistencyRun {
        return DB::transaction(function () use ($run, $promotion, $result): PaymentConsistencyRun {
            $lockedRun = PaymentConsistencyRun::query()->lockForUpdate()->findOrFail($run->id);
            $finding = PaymentConsistencyFinding::query()
                ->where('subject_type', PromotionUsageConsistencyRule::SUBJECT_TYPE)
                ->where('subject_id', $promotion->id)
                ->where('rule_code', PromotionUsageConsistencyRule::CODE)
                ->lockForUpdate()
                ->first();
            $opened = false;
            $resolved = false;
            $now = now('UTC');

            if (! $result['healthy']) {
                if (! $finding) {
                    $finding = PaymentConsistencyFinding::query()->create([
                        'company_id' => $result['company_id'],
                        'branch_id' => $result['branch_id'],
                        'rule_code' => PromotionUsageConsistencyRule::CODE,
                        'rule_version' => PromotionUsageConsistencyRule::VERSION,
                        'subject_type' => PromotionUsageConsistencyRule::SUBJECT_TYPE,
                        'subject_id' => $promotion->id,
                        'episode_uuid' => (string) Str::uuid(),
                        'state' => PaymentConsistencyFinding::STATE_OPEN,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'last_run_id' => $lockedRun->id,
                        'checkout_id' => $result['checkout_id'],
                        'expected' => $result['expected'],
                        'observed' => $result['observed'],
                        'evidence_fingerprint' => $result['evidence_fingerprint'],
                        'alert_dispatch' => ['state' => 'pending'],
                    ]);
                    $opened = true;
                } else {
                    $opened = $finding->state === PaymentConsistencyFinding::STATE_RESOLVED;
                    $finding->update([
                        'company_id' => $result['company_id'],
                        'branch_id' => $result['branch_id'],
                        'rule_version' => PromotionUsageConsistencyRule::VERSION,
                        'episode_uuid' => $opened ? (string) Str::uuid() : $finding->episode_uuid,
                        'state' => PaymentConsistencyFinding::STATE_OPEN,
                        'first_seen_at' => $opened ? $now : $finding->first_seen_at,
                        'last_seen_at' => $now,
                        'resolved_at' => null,
                        'last_run_id' => $lockedRun->id,
                        'checkout_id' => $result['checkout_id'],
                        'expected' => $result['expected'],
                        'observed' => $result['observed'],
                        'evidence_fingerprint' => $result['evidence_fingerprint'],
                        'alert_dispatch' => $opened ? ['state' => 'pending'] : $finding->alert_dispatch,
                    ]);
                }
                if ($opened) {
                    $this->auditLog->log('payment_consistency.finding_opened', $lockedRun->requested_by, $finding, [
                        'run_id' => (int) $lockedRun->id,
                        'rule_code' => PromotionUsageConsistencyRule::CODE,
                        'episode_uuid' => $finding->episode_uuid,
                        'issue_codes' => collect($result['observed']['issues'])->pluck('code')->unique()->values()->all(),
                    ], (int) $result['company_id']);
                }
            } elseif ($finding && $finding->state === PaymentConsistencyFinding::STATE_OPEN) {
                $finding->update([
                    'state' => PaymentConsistencyFinding::STATE_RESOLVED,
                    'last_seen_at' => $now,
                    'resolved_at' => $now,
                    'last_run_id' => $lockedRun->id,
                    'expected' => $result['expected'],
                    'observed' => $result['observed'],
                    'evidence_fingerprint' => $result['evidence_fingerprint'],
                ]);
                $resolved = true;
                $this->auditLog->log('payment_consistency.finding_resolved', $lockedRun->requested_by, $finding, [
                    'run_id' => (int) $lockedRun->id,
                    'rule_code' => PromotionUsageConsistencyRule::CODE,
                    'episode_uuid' => $finding->episode_uuid,
                ], (int) $result['company_id']);
            }

            $lockedRun->update([
                'state' => PaymentConsistencyRun::STATE_COMPLETED,
                'heartbeat_at' => $now,
                'completed_at' => $now,
                'checked_count' => 1,
                'open_count' => $result['healthy'] ? 0 : 1,
                'resolved_count' => $resolved ? 1 : 0,
                'error_code' => null,
            ]);

            return $lockedRun->fresh();
        }, 3);
    }

    private function boundedErrorCode(Throwable $exception): string
    {
        $name = class_basename($exception);
        $name = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        $name = preg_replace('/[^A-Z0-9_]/', '', $name) ?: 'CONSISTENCY_CHECK_FAILED';

        return substr($name, 0, 80);
    }
}
