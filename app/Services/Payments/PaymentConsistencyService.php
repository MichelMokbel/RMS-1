<?php

namespace App\Services\Payments;

use App\Jobs\ContinuePaymentConsistencySweep;
use App\Jobs\RunPaymentConsistency;
use App\Models\MembershipPromotion;
use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Promotions\PromotionUsageConsistencyRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentConsistencyService
{
    public function __construct(
        private readonly PaymentConsistencyRuleRegistry $registry,
        private readonly AccountingAuditLogService $auditLog,
        private readonly PaymentConsistencyAlertService $alerts,
    ) {}

    public function checkPromotion(
        int|MembershipPromotion $promotion,
        string $kind = PaymentConsistencyRun::KIND_TARGETED,
        ?int $requestedBy = null,
        ?string $triggerKey = null,
        ?int $parentRunId = null,
    ): PaymentConsistencyRun {
        $promotion = $promotion instanceof MembershipPromotion
            ? $promotion
            : MembershipPromotion::query()->findOrFail($promotion);

        return $this->check(
            PromotionUsageConsistencyRule::CODE,
            PromotionUsageConsistencyRule::SUBJECT_TYPE,
            (int) $promotion->id,
            $kind,
            $requestedBy,
            $triggerKey ?? 'promotion:'.$promotion->id.':'.Str::uuid(),
            $parentRunId,
        );
    }

    public function check(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        string $kind = PaymentConsistencyRun::KIND_TARGETED,
        ?int $requestedBy = null,
        ?string $triggerKey = null,
        ?int $parentRunId = null,
    ): PaymentConsistencyRun {
        if ($subjectId <= 0 || ! $this->registry->supports($ruleCode, $subjectType)) {
            throw new \InvalidArgumentException('Unsupported payment consistency subject.');
        }
        $adapter = $this->registry->adapter($ruleCode);
        $scope = $adapter->scope($ruleCode, $subjectType, $subjectId);
        $triggerKey ??= implode(':', [$ruleCode, $subjectType, $subjectId, Str::uuid()]);
        $run = $this->createRun($ruleCode, $subjectType, $subjectId, $scope, $kind, $requestedBy, $triggerKey, $parentRunId);
        if ($run->not_before?->isFuture()) {
            return $run;
        }
        [$run, $claimed] = $this->claim($run);
        if (! $claimed) {
            $this->refreshParent($run->parent_run_id);

            return $run;
        }

        try {
            $result = DB::transaction(fn (): array => $adapter->evaluate($ruleCode, $subjectType, $subjectId), 3);
            if (! (bool) ($result['deferred'] ?? false)) {
                $confirmation = DB::transaction(fn (): array => $adapter->evaluate($ruleCode, $subjectType, $subjectId), 3);
                if (! hash_equals((string) $result['evidence_fingerprint'], (string) $confirmation['evidence_fingerprint'])) {
                    $result['healthy'] = false;
                    $result['deferred'] = true;
                    $result['observed']['deferred_reason'] = 'SUBJECT_CHANGED_DURING_EVALUATION';
                }
            }

            return $this->persistResult($run, $ruleCode, $subjectType, $subjectId, $result);
        } catch (Throwable $exception) {
            $run->update([
                'state' => PaymentConsistencyRun::STATE_FAILED,
                'heartbeat_at' => now('UTC'),
                'completed_at' => now('UTC'),
                'error_code' => $this->boundedErrorCode($exception),
            ]);
            $this->refreshParent($run->parent_run_id);

            throw $exception;
        }
    }

    public function reserve(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        string $kind,
        string $triggerKey,
        ?int $requestedBy = null,
        ?int $parentRunId = null,
        $notBefore = null,
    ): PaymentConsistencyRun {
        if ($subjectId <= 0 || ! $this->registry->supports($ruleCode, $subjectType)) {
            throw new \InvalidArgumentException('Unsupported payment consistency subject.');
        }
        $scope = $this->registry->adapter($ruleCode)->scope($ruleCode, $subjectType, $subjectId);

        return $this->createRun(
            $ruleCode,
            $subjectType,
            $subjectId,
            $scope,
            $kind,
            $requestedBy,
            $triggerKey,
            $parentRunId,
            $notBefore,
        );
    }

    /** @param array{company_id:int,branch_id:int|null,checkout_id:int|null} $scope */
    private function createRun(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        array $scope,
        string $kind,
        ?int $requestedBy,
        string $triggerKey,
        ?int $parentRunId = null,
        $notBefore = null,
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

        return DB::transaction(function () use ($ruleCode, $subjectType, $subjectId, $scope, $kind, $requestedBy, $triggerKey, $parentRunId, $notBefore): PaymentConsistencyRun {
            $existing = PaymentConsistencyRun::query()
                ->where('company_id', $scope['company_id'])
                ->where('trigger_key', $triggerKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($parentRunId && ! $existing->parent_run_id) {
                    $existing->update(['parent_run_id' => $parentRunId]);
                }
                if ($existing->state === PaymentConsistencyRun::STATE_FAILED) {
                    $existing->update([
                        'state' => PaymentConsistencyRun::STATE_QUEUED,
                        'not_before' => $notBefore,
                        'started_at' => null,
                        'completed_at' => null,
                        'error_code' => null,
                    ]);
                }

                return $existing;
            }

            return PaymentConsistencyRun::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $scope['company_id'],
                'branch_id' => $scope['branch_id'],
                'kind' => $kind,
                'state' => PaymentConsistencyRun::STATE_QUEUED,
                'requested_by' => $requestedBy,
                'rule_code' => $ruleCode,
                'rule_versions' => $this->registry->versions(),
                'registry_hash' => $this->registry->hash(),
                'trigger_key' => $triggerKey,
                'parent_run_id' => $parentRunId,
                'not_before' => $notBefore,
                'target_type' => $subjectType,
                'target_id' => $subjectId,
                'upper_bound' => [
                    'captured_at' => now('UTC')->toIso8601String(),
                    'target_id' => $subjectId,
                ],
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $result */
    private function persistResult(
        PaymentConsistencyRun $run,
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        array $result,
    ): PaymentConsistencyRun {
        $deferredRetryId = null;
        $persistedRun = DB::transaction(function () use ($run, $ruleCode, $subjectType, $subjectId, $result, &$deferredRetryId): PaymentConsistencyRun {
            $lockedRun = PaymentConsistencyRun::query()->lockForUpdate()->findOrFail($run->id);
            $finding = PaymentConsistencyFinding::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('rule_code', $ruleCode)
                ->lockForUpdate()
                ->first();
            $now = now('UTC');

            if ((bool) ($result['deferred'] ?? false)) {
                $retry = $this->reserveDeferredRetry($lockedRun, $ruleCode, $subjectType, $subjectId, $now);
                $deferredRetryId = (int) $retry->id;
                $lockedRun->update([
                    'state' => PaymentConsistencyRun::STATE_COMPLETED,
                    'heartbeat_at' => $now,
                    'completed_at' => $now,
                    'checked_count' => 0,
                    'deferred_count' => 1,
                    'open_count' => $finding?->state === PaymentConsistencyFinding::STATE_OPEN ? 1 : 0,
                    'resolved_count' => 0,
                    'error_code' => null,
                    'cursors' => [
                        'deferred_reason' => (string) ($result['observed']['deferred_reason'] ?? 'SUBJECT_DEFERRED'),
                        'deferred_retry_run_id' => $deferredRetryId,
                    ],
                ]);

                return $lockedRun->fresh();
            }

            $opened = false;
            $resolved = false;
            $version = (int) $this->registry->definition($ruleCode)['version'];
            if (! $result['healthy']) {
                if (! $finding) {
                    $finding = PaymentConsistencyFinding::query()->create([
                        'company_id' => $result['company_id'],
                        'branch_id' => $result['branch_id'],
                        'rule_code' => $ruleCode,
                        'rule_version' => $version,
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
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
                        'rule_version' => $version,
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
                        'alert_dispatch' => $opened
                            ? ['state' => 'pending']
                            : $finding->alert_dispatch,
                    ]);
                }
                if ($opened) {
                    $this->auditLog->log('payment_consistency.finding_opened', $lockedRun->requested_by, $finding, [
                        'run_id' => (int) $lockedRun->id,
                        'rule_code' => $ruleCode,
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
                    'alert_dispatch' => $this->resolvedAlertDispatch($finding->alert_dispatch, $now),
                ]);
                $resolved = true;
                $this->auditLog->log('payment_consistency.finding_resolved', $lockedRun->requested_by, $finding, [
                    'run_id' => (int) $lockedRun->id,
                    'rule_code' => $ruleCode,
                    'episode_uuid' => $finding->episode_uuid,
                ], (int) $result['company_id']);
            }

            $lockedRun->update([
                'state' => PaymentConsistencyRun::STATE_COMPLETED,
                'heartbeat_at' => $now,
                'completed_at' => $now,
                'checked_count' => 1,
                'deferred_count' => 0,
                'open_count' => $result['healthy'] ? 0 : 1,
                'resolved_count' => $resolved ? 1 : 0,
                'error_code' => null,
            ]);

            return $lockedRun->fresh();
        }, 3);

        if ($deferredRetryId) {
            $this->dispatchDeferredRetry(PaymentConsistencyRun::query()->findOrFail($deferredRetryId));
        }
        $this->refreshParent($persistedRun->parent_run_id);

        $finding = PaymentConsistencyFinding::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('rule_code', $ruleCode)
            ->first();
        if ($finding
            && $finding->state === PaymentConsistencyFinding::STATE_OPEN
            && in_array((string) data_get($finding->alert_dispatch, 'state'), ['pending', 'retryable'], true)
        ) {
            $this->alerts->queueFinding((int) $finding->id, (string) $finding->episode_uuid);
        }

        return $persistedRun;
    }

    /** @return array{0:PaymentConsistencyRun,1:bool} */
    private function claim(PaymentConsistencyRun $run): array
    {
        return DB::transaction(function () use ($run): array {
            $locked = PaymentConsistencyRun::query()->lockForUpdate()->findOrFail($run->id);
            $staleAfter = max(180, (int) config('payment_consistency.run_stale_seconds', 300));
            $lastHeartbeat = $locked->heartbeat_at ?: $locked->started_at ?: $locked->updated_at;
            $stale = $locked->state === PaymentConsistencyRun::STATE_RUNNING
                && $lastHeartbeat !== null
                && $lastHeartbeat->lte(now('UTC')->subSeconds($staleAfter));
            if ($locked->state !== PaymentConsistencyRun::STATE_QUEUED && ! $stale) {
                return [$locked->fresh(), false];
            }

            $now = now('UTC');
            $locked->update([
                'state' => PaymentConsistencyRun::STATE_RUNNING,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'completed_at' => null,
                'error_code' => null,
            ]);

            return [$locked->fresh(), true];
        }, 3);
    }

    private function reserveDeferredRetry(
        PaymentConsistencyRun $run,
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        $now,
    ): PaymentConsistencyRun {
        $retryAt = $now->copy()->addMinutes(max(1, (int) config('payment_consistency.deferred_retry_minutes', 5)));
        $triggerKey = substr(implode(':', [
            'deferred', $run->id, $ruleCode, $subjectType, $subjectId, $retryAt->format('YmdHi'),
        ]), 0, 190);
        $existing = PaymentConsistencyRun::query()
            ->where('company_id', $run->company_id)
            ->where('trigger_key', $triggerKey)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        return PaymentConsistencyRun::query()->create([
            'reference' => (string) Str::uuid(),
            'company_id' => (int) $run->company_id,
            'branch_id' => $run->branch_id ? (int) $run->branch_id : null,
            'kind' => (string) $run->kind,
            'state' => PaymentConsistencyRun::STATE_QUEUED,
            'requested_by' => $run->requested_by ? (int) $run->requested_by : null,
            'rule_code' => $ruleCode,
            'rule_versions' => $this->registry->versions(),
            'registry_hash' => $this->registry->hash(),
            'trigger_key' => $triggerKey,
            'parent_run_id' => $run->parent_run_id ? (int) $run->parent_run_id : null,
            'not_before' => $retryAt,
            'target_type' => $subjectType,
            'target_id' => $subjectId,
            'upper_bound' => [
                'captured_at' => $now->toIso8601String(),
                'target_id' => $subjectId,
            ],
        ]);
    }

    private function dispatchDeferredRetry(PaymentConsistencyRun $retry): void
    {
        if ($retry->state !== PaymentConsistencyRun::STATE_QUEUED || (string) config('queue.default') === 'sync') {
            return;
        }

        try {
            RunPaymentConsistency::dispatch(
                (string) $retry->rule_code,
                (string) $retry->target_type,
                (int) $retry->target_id,
                (string) $retry->kind,
                (string) $retry->trigger_key,
                $retry->requested_by ? (int) $retry->requested_by : null,
                $retry->parent_run_id ? (int) $retry->parent_run_id : null,
            )->delay($retry->not_before);
        } catch (Throwable $exception) {
            Log::warning('payment_consistency_deferred_dispatch_failed', [
                'run_id' => (int) $retry->id,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function refreshParent(?int $parentRunId): void
    {
        if (! $parentRunId) {
            return;
        }

        $continue = DB::transaction(function () use ($parentRunId): bool {
            $parent = PaymentConsistencyRun::query()->lockForUpdate()->find($parentRunId);
            if (! $parent || (string) $parent->target_type !== PaymentConsistencySweepService::PARENT_TARGET_TYPE) {
                return false;
            }
            $cursors = is_array($parent->cursors) ? $parent->cursors : [];
            $manifest = is_array($cursors['manifest'] ?? null) ? $cursors['manifest'] : [];
            if ($manifest === []) {
                return false;
            }
            $children = PaymentConsistencyRun::query()
                ->where('parent_run_id', $parent->id)
                ->where(function ($query) use ($manifest): void {
                    foreach ($manifest as $subject) {
                        if (! is_array($subject)) {
                            continue;
                        }
                        $query->orWhere(function ($match) use ($subject): void {
                            $match->where('rule_code', (string) ($subject['rule_code'] ?? ''))
                                ->where('target_type', (string) ($subject['subject_type'] ?? ''))
                                ->where('target_id', (int) ($subject['subject_id'] ?? 0));
                        });
                    }
                })
                ->orderBy('id')
                ->get();
            $latestBySubject = [];
            foreach ($children as $child) {
                $latestBySubject[implode(':', [$child->rule_code, $child->target_type, $child->target_id])] = $child;
            }
            $checked = 0;
            $deferred = 0;
            $open = 0;
            $resolved = 0;
            $failed = 0;
            $nextAttemptAt = null;

            foreach ($manifest as $subject) {
                if (! is_array($subject)) {
                    $deferred++;

                    continue;
                }
                $key = implode(':', [
                    (string) ($subject['rule_code'] ?? ''),
                    (string) ($subject['subject_type'] ?? ''),
                    (int) ($subject['subject_id'] ?? 0),
                ]);
                $latest = $latestBySubject[$key] ?? null;
                if (! $latest) {
                    $deferred++;

                    continue;
                }
                if ($latest->state === PaymentConsistencyRun::STATE_COMPLETED && (int) $latest->deferred_count === 0) {
                    $checked++;
                    $open += (int) $latest->open_count;
                    $resolved += (int) $latest->resolved_count;

                    continue;
                }
                if ($latest->state === PaymentConsistencyRun::STATE_FAILED) {
                    $failed++;
                } else {
                    $deferred++;
                }
                if ($latest->not_before && ($nextAttemptAt === null || $latest->not_before->lt($nextAttemptAt))) {
                    $nextAttemptAt = $latest->not_before;
                }
            }

            $batchComplete = $checked === count($manifest) && $deferred === 0 && $failed === 0;
            $accumulated = is_array($cursors['accumulated'] ?? null)
                ? $cursors['accumulated']
                : ['checked' => 0, 'open' => 0, 'resolved' => 0];
            $scanComplete = (bool) ($cursors['scan_complete'] ?? false);
            if ($batchComplete) {
                $accumulated['checked'] = (int) ($accumulated['checked'] ?? 0) + $checked;
                $accumulated['open'] = (int) ($accumulated['open'] ?? 0) + $open;
                $accumulated['resolved'] = (int) ($accumulated['resolved'] ?? 0) + $resolved;
                $cursors['accumulated'] = $accumulated;
                $cursors['manifest'] = [];
                $cursors['batch_state'] = $scanComplete ? 'completed' : 'ready';
            }
            $parent->update([
                'state' => $batchComplete && $scanComplete
                    ? PaymentConsistencyRun::STATE_COMPLETED
                    : PaymentConsistencyRun::STATE_RUNNING,
                'heartbeat_at' => now('UTC'),
                'completed_at' => $batchComplete && $scanComplete ? now('UTC') : null,
                'not_before' => $nextAttemptAt,
                'checked_count' => (int) ($accumulated['checked'] ?? 0) + ($batchComplete ? 0 : $checked),
                'deferred_count' => $batchComplete ? 0 : $deferred + $failed,
                'open_count' => (int) ($accumulated['open'] ?? 0) + ($batchComplete ? 0 : $open),
                'resolved_count' => (int) ($accumulated['resolved'] ?? 0) + ($batchComplete ? 0 : $resolved),
                'error_code' => $failed > 0 ? 'CHILD_RUN_FAILED' : null,
                'cursors' => $cursors,
            ]);

            return $batchComplete && ! $scanComplete && (string) config('queue.default') !== 'sync';
        }, 3);

        if ($continue) {
            try {
                ContinuePaymentConsistencySweep::dispatch($parentRunId);
            } catch (Throwable $exception) {
                Log::warning('payment_consistency_continuation_dispatch_failed', [
                    'parent_run_id' => $parentRunId,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }

    /** @param array<string, mixed>|null $dispatch
     * @return array<string, mixed>|null
     */
    private function resolvedAlertDispatch(?array $dispatch, $now): ?array
    {
        if (! is_array($dispatch)) {
            return $dispatch;
        }
        if (in_array((string) ($dispatch['state'] ?? ''), ['pending', 'queued', 'retryable'], true)) {
            $dispatch['state'] = 'suppressed';
            $dispatch['suppressed_at'] = $now->toIso8601String();
        }

        return $dispatch;
    }

    private function boundedErrorCode(Throwable $exception): string
    {
        $name = class_basename($exception);
        $name = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        $name = preg_replace('/[^A-Z0-9_]/', '', $name) ?: 'CONSISTENCY_CHECK_FAILED';

        return substr($name, 0, 80);
    }
}
