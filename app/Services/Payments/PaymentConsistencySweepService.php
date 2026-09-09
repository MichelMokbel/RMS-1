<?php

namespace App\Services\Payments;

use App\Models\PaymentConsistencyRun;
use App\Services\Accounting\AccountingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentConsistencySweepService
{
    public const PARENT_RULE_CODE = 'registry_sweep_v1';

    public const PARENT_TARGET_TYPE = 'accounting_company';

    public function __construct(
        private readonly PaymentConsistencyDispatchService $dispatcher,
        private readonly AccountingContextService $accountingContext,
        private readonly PaymentConsistencyAlertService $alerts,
        private readonly PaymentConsistencyRuleRegistry $registry,
        private readonly PaymentConsistencyService $consistency,
    ) {}

    /** @return array{enabled:bool,mode:string,companies:int,subjects:int,promotions:int,dispatched:int,dispatch_failed:int} */
    public function dispatch(string $mode, ?int $companyId = null): array
    {
        if (! in_array($mode, [PaymentConsistencyRun::KIND_CATCHUP, PaymentConsistencyRun::KIND_FULL], true)) {
            throw new \InvalidArgumentException('Consistency sweep mode must be catchup or full.');
        }
        if (! (bool) config('payment_consistency.enabled', false)) {
            return $this->result(false, $mode, 0, 0, 0, 0, 0);
        }

        $companyIds = $this->companyIds($companyId);
        $totals = ['subjects' => 0, 'promotions' => 0, 'dispatched' => 0, 'dispatch_failed' => 0];
        foreach ($companyIds as $resolvedCompanyId) {
            $resolvedCompanyId = (int) $resolvedCompanyId;
            $this->recoverStaleRuns($resolvedCompanyId);
            $this->recoverFailedSweepChildren($resolvedCompanyId);
            $this->recoverDeferredRetries($resolvedCompanyId);
            $this->recoverQueuedSweepChildren($resolvedCompanyId);

            $parent = $this->sweepParent($resolvedCompanyId, $mode, $this->slot($mode));
            $batch = $this->continue((int) $parent->id);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $batch[$key];
            }
            $this->alerts->recoverDue($resolvedCompanyId);
        }

        return $this->result(
            true,
            $mode,
            $companyIds->count(),
            $totals['subjects'],
            $totals['promotions'],
            $totals['dispatched'],
            $totals['dispatch_failed'],
        );
    }

    /** @return array{subjects:int,promotions:int,dispatched:int,dispatch_failed:int} */
    public function continue(int $parentRunId): array
    {
        $empty = ['subjects' => 0, 'promotions' => 0, 'dispatched' => 0, 'dispatch_failed' => 0];
        $parent = DB::transaction(function () use ($parentRunId): ?PaymentConsistencyRun {
            $parent = PaymentConsistencyRun::query()->lockForUpdate()->find($parentRunId);
            if (! $parent
                || $parent->state !== PaymentConsistencyRun::STATE_RUNNING
                || $parent->target_type !== self::PARENT_TARGET_TYPE
            ) {
                return null;
            }
            $cursors = is_array($parent->cursors) ? $parent->cursors : [];
            $batchState = (string) ($cursors['batch_state'] ?? 'ready');
            $stale = $batchState === 'assembling'
                && $parent->heartbeat_at?->lte(now('UTC')->subSeconds(
                    max(180, (int) config('payment_consistency.run_stale_seconds', 300)),
                ));
            if (($cursors['manifest'] ?? []) !== []) {
                return $batchState === 'waiting' ? $parent->fresh() : null;
            }
            if ($batchState !== 'ready' && ! $stale) {
                return null;
            }
            if ((bool) ($cursors['scan_complete'] ?? false)) {
                $parent->update([
                    'state' => PaymentConsistencyRun::STATE_COMPLETED,
                    'heartbeat_at' => now('UTC'),
                    'completed_at' => now('UTC'),
                    'deferred_count' => 0,
                ]);

                return null;
            }
            $cursors['batch_state'] = 'assembling';
            $parent->update(['cursors' => $cursors, 'heartbeat_at' => now('UTC')]);

            return $parent->fresh();
        }, 3);
        if (! $parent) {
            return $empty;
        }
        $existingManifest = collect((array) data_get($parent->cursors, 'manifest', []));
        if ($existingManifest->isNotEmpty()) {
            return $this->reserveAndDispatchManifest($parent, $existingManifest);
        }

        $upperBound = is_array($parent->upper_bound) ? $parent->upper_bound : [];
        $cutoff = ! empty($upperBound['changed_since'])
            ? CarbonImmutable::parse((string) $upperBound['changed_since'])
            : CarbonImmutable::createFromTimestampUTC(0);
        $batch = $this->subjectBatch(
            (int) $parent->company_id,
            (string) $parent->kind,
            $cutoff,
            (array) data_get($parent->cursors, 'scan', ['stream' => 0, 'last_id' => 0]),
            (array) ($upperBound['stream_bounds'] ?? []),
        );

        $manifest = $batch['subjects']->map(function (array $subject) use ($parent): array {
            return $subject + ['trigger_key' => substr(implode(':', [
                'sweep', $parent->id, $subject['rule_code'], $subject['subject_type'], $subject['subject_id'],
            ]), 0, 190)];
        })->values();
        $parent = DB::transaction(function () use ($parentRunId, $batch, $manifest): ?PaymentConsistencyRun {
            $parent = PaymentConsistencyRun::query()->lockForUpdate()->find($parentRunId);
            $cursors = is_array($parent?->cursors) ? $parent->cursors : [];
            if (! $parent || ($cursors['batch_state'] ?? null) !== 'assembling') {
                return null;
            }
            $cursors['scan'] = $batch['scan'];
            $cursors['scan_complete'] = $batch['complete'];
            $cursors['manifest'] = $manifest->all();
            $cursors['batch_state'] = $manifest->isEmpty() ? 'ready' : 'waiting';
            $parent->update([
                'cursors' => $cursors,
                'heartbeat_at' => now('UTC'),
                'deferred_count' => $manifest->count(),
            ]);
            foreach ($manifest as $subject) {
                $this->consistency->reserve(
                    $subject['rule_code'],
                    $subject['subject_type'],
                    $subject['subject_id'],
                    (string) $parent->kind,
                    $subject['trigger_key'],
                    parentRunId: (int) $parent->id,
                );
            }
            if ($manifest->isEmpty() && $batch['complete']) {
                $parent->update([
                    'state' => PaymentConsistencyRun::STATE_COMPLETED,
                    'completed_at' => now('UTC'),
                    'deferred_count' => 0,
                ]);
            }

            return $parent->fresh();
        }, 3);
        if (! $parent || $manifest->isEmpty()) {
            return $empty;
        }

        return $this->dispatchManifest($parent, $manifest);
    }

    /** @param Collection<int, array<string, mixed>> $manifest
     * @return array{subjects:int,promotions:int,dispatched:int,dispatch_failed:int}
     */
    private function reserveAndDispatchManifest(PaymentConsistencyRun $parent, Collection $manifest): array
    {
        DB::transaction(function () use ($parent, $manifest): void {
            foreach ($manifest as $subject) {
                $this->consistency->reserve(
                    (string) $subject['rule_code'],
                    (string) $subject['subject_type'],
                    (int) $subject['subject_id'],
                    (string) $parent->kind,
                    (string) $subject['trigger_key'],
                    parentRunId: (int) $parent->id,
                );
            }
        }, 3);

        return $this->dispatchManifest($parent, $manifest);
    }

    /** @param Collection<int, array<string, mixed>> $manifest
     * @return array{subjects:int,promotions:int,dispatched:int,dispatch_failed:int}
     */
    private function dispatchManifest(PaymentConsistencyRun $parent, Collection $manifest): array
    {
        $dispatched = 0;
        $failed = 0;
        foreach ($manifest as $subject) {
            $run = PaymentConsistencyRun::query()
                ->where('company_id', $parent->company_id)
                ->where('trigger_key', (string) $subject['trigger_key'])
                ->first();
            if (! $run || $run->state !== PaymentConsistencyRun::STATE_QUEUED) {
                continue;
            }
            $ok = $subject['rule_code'] === 'promotion_usage_v1'
                ? $this->dispatcher->dispatchPromotion(
                    $subject['subject_id'],
                    (string) $parent->kind,
                    $subject['trigger_key'],
                    parentRunId: (int) $parent->id,
                )
                : $this->dispatcher->dispatchRule(
                    $subject['rule_code'],
                    $subject['subject_type'],
                    $subject['subject_id'],
                    (string) $parent->kind,
                    $subject['trigger_key'],
                    parentRunId: (int) $parent->id,
                );
            $ok ? $dispatched++ : $failed++;
        }
        if ($failed > 0) {
            $parent->update(['error_code' => 'SWEEP_DISPATCH_FAILED', 'heartbeat_at' => now('UTC')]);
        }

        return [
            'subjects' => $manifest->count(),
            'promotions' => $manifest->where('rule_code', 'promotion_usage_v1')->count(),
            'dispatched' => $dispatched,
            'dispatch_failed' => $failed,
        ];
    }

    private function sweepParent(int $companyId, string $mode, string $slot): PaymentConsistencyRun
    {
        $triggerKey = "sweep:{$mode}:{$slot}:company:{$companyId}";
        $sameSlot = PaymentConsistencyRun::query()
            ->where('company_id', $companyId)
            ->where('trigger_key', $triggerKey)
            ->first();
        if ($sameSlot) {
            return $sameSlot;
        }
        $outstanding = PaymentConsistencyRun::query()
            ->where('company_id', $companyId)
            ->where('rule_code', self::PARENT_RULE_CODE)
            ->where('target_type', self::PARENT_TARGET_TYPE)
            ->where('kind', $mode)
            ->whereIn('state', [PaymentConsistencyRun::STATE_QUEUED, PaymentConsistencyRun::STATE_RUNNING])
            ->orderBy('id')
            ->first();
        if ($outstanding) {
            return $outstanding;
        }

        $cutoff = CarbonImmutable::now('UTC')->subMinutes(
            max(5, (int) config('payment_consistency.catchup_lookback_minutes', 20)),
        );
        $bounds = $this->streamBounds($companyId, $mode, $cutoff);

        return DB::transaction(function () use ($companyId, $mode, $triggerKey, $cutoff, $bounds): PaymentConsistencyRun {
            $sameSlot = PaymentConsistencyRun::query()
                ->where('company_id', $companyId)
                ->where('trigger_key', $triggerKey)
                ->lockForUpdate()
                ->first();
            if ($sameSlot) {
                return $sameSlot;
            }
            $outstanding = PaymentConsistencyRun::query()
                ->where('company_id', $companyId)
                ->where('rule_code', self::PARENT_RULE_CODE)
                ->where('target_type', self::PARENT_TARGET_TYPE)
                ->where('kind', $mode)
                ->whereIn('state', [PaymentConsistencyRun::STATE_QUEUED, PaymentConsistencyRun::STATE_RUNNING])
                ->lockForUpdate()
                ->orderBy('id')
                ->first();
            if ($outstanding) {
                return $outstanding;
            }
            $now = now('UTC');

            return PaymentConsistencyRun::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $companyId,
                'branch_id' => null,
                'kind' => $mode,
                'state' => PaymentConsistencyRun::STATE_RUNNING,
                'rule_code' => self::PARENT_RULE_CODE,
                'rule_versions' => $this->registry->versions(),
                'registry_hash' => $this->registry->hash(),
                'trigger_key' => $triggerKey,
                'target_type' => self::PARENT_TARGET_TYPE,
                'target_id' => $companyId,
                'upper_bound' => [
                    'captured_at' => $now->toIso8601String(),
                    'changed_since' => $mode === PaymentConsistencyRun::KIND_CATCHUP ? $cutoff->toIso8601String() : null,
                    'stream_bounds' => $bounds,
                ],
                'cursors' => [
                    'scan' => ['stream' => 0, 'last_id' => 0],
                    'scan_complete' => false,
                    'batch_state' => 'ready',
                    'manifest' => [],
                    'accumulated' => ['checked' => 0, 'open' => 0, 'resolved' => 0],
                ],
                'heartbeat_at' => $now,
                'started_at' => $now,
            ]);
        }, 3);
    }

    /** @return array<string, int> */
    private function streamBounds(int $companyId, string $mode, CarbonImmutable $cutoff): array
    {
        return collect($this->streams($companyId, $mode, $cutoff))->mapWithKeys(
            fn (array $stream): array => [
                $stream['key'] => (int) ((clone $stream['query'])->max($stream['id_column']) ?? 0),
            ],
        )->all();
    }

    /**
     * @param  array{stream?:int,last_id?:int}  $scan
     * @param  array<string, int>  $bounds
     * @return array{subjects:Collection<int,array{rule_code:string,subject_type:string,subject_id:int}>,scan:array{stream:int,last_id:int},complete:bool}
     */
    private function subjectBatch(int $companyId, string $mode, CarbonImmutable $cutoff, array $scan, array $bounds): array
    {
        $streams = $this->streams($companyId, $mode, $cutoff);
        $streamIndex = max(0, (int) ($scan['stream'] ?? 0));
        $lastId = max(0, (int) ($scan['last_id'] ?? 0));
        $remaining = $this->batchSize();
        $subjects = collect();

        while ($remaining > 0 && isset($streams[$streamIndex])) {
            $stream = $streams[$streamIndex];
            $upper = max(0, (int) ($bounds[$stream['key']] ?? 0));
            if ($upper <= $lastId) {
                $streamIndex++;
                $lastId = 0;

                continue;
            }
            $requested = $remaining;
            $ids = (clone $stream['query'])
                ->where($stream['id_column'], '>', $lastId)
                ->where($stream['id_column'], '<=', $upper)
                ->distinct()
                ->orderBy($stream['id_column'])
                ->limit($requested)
                ->pluck($stream['id_column'])
                ->map(fn ($id): int => (int) $id)
                ->values();
            foreach ($ids as $id) {
                $subjects->push([
                    'rule_code' => $stream['rule_code'],
                    'subject_type' => $stream['subject_type'],
                    'subject_id' => $id,
                ]);
            }
            if ($ids->isNotEmpty()) {
                $lastId = (int) $ids->last();
                $remaining -= $ids->count();
            }
            if ($ids->count() < $requested) {
                $streamIndex++;
                $lastId = 0;
            }
        }

        return [
            'subjects' => $subjects,
            'scan' => ['stream' => $streamIndex, 'last_id' => $lastId],
            'complete' => ! isset($streams[$streamIndex]),
        ];
    }

    /** @return array<int, array{key:string,rule_code:string,subject_type:string,id_column:string,query:Builder}> */
    private function streams(int $companyId, string $mode, CarbonImmutable $cutoff): array
    {
        $full = $mode === PaymentConsistencyRun::KIND_FULL;
        $attempts = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('payment_checkout_attempts as attempts')->where('attempts.company_id', $companyId);
            if (! $full) {
                $query->where(function ($changed) use ($cutoff): void {
                    $changed->where('attempts.updated_at', '>=', $cutoff)
                        ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('payment_provider_transactions as t')
                            ->whereColumn('t.attempt_id', 'attempts.id')->where('t.updated_at', '>=', $cutoff))
                        ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('payment_provider_transactions as t')
                            ->join('payment_provider_events as e', 'e.provider_transaction_id', '=', 't.id')
                            ->whereColumn('t.attempt_id', 'attempts.id')->where('e.updated_at', '>=', $cutoff))
                        ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('payment_checkout_targets as t')
                            ->whereColumn('t.attempt_id', 'attempts.id')->where('t.updated_at', '>=', $cutoff));
                });
            }

            return $query;
        };
        $ordinaryTargets = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('payment_checkout_targets as targets')
                ->join('payment_checkout_attempts as attempts', 'attempts.id', '=', 'targets.attempt_id')
                ->leftJoin('orders', 'orders.id', '=', 'targets.order_id')
                ->leftJoin('ar_invoices as invoices', 'invoices.id', '=', 'targets.invoice_id')
                ->leftJoin('payment_allocations as allocations', function ($join): void {
                    $join->on('allocations.allocatable_id', '=', 'invoices.id')
                        ->where('allocations.allocatable_type', '=', 'App\\Models\\ArInvoice');
                })
                ->where('attempts.company_id', $companyId)
                ->whereIn('attempts.purpose', ['ordinary_order', 'menu_order']);
            if (! $full) {
                $query->where(fn ($q) => $q->where('targets.updated_at', '>=', $cutoff)
                    ->orWhere('attempts.updated_at', '>=', $cutoff)->orWhere('orders.updated_at', '>=', $cutoff)
                    ->orWhere('invoices.updated_at', '>=', $cutoff)->orWhere('allocations.updated_at', '>=', $cutoff));
            }

            return $query;
        };
        $blocks = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('membership_purchase_blocks as blocks')
                ->leftJoin('payments', 'payments.id', '=', 'blocks.payment_id')
                ->leftJoin('meal_plan_requests as requests', 'requests.id', '=', 'blocks.meal_plan_request_id')
                ->leftJoin('meal_subscriptions as subscriptions', 'subscriptions.id', '=', 'blocks.subscription_id')
                ->leftJoin('membership_booking_funding as funding', 'funding.purchase_block_id', '=', 'blocks.id')
                ->where('blocks.company_id', $companyId);
            if (! $full) {
                $query->where(fn ($q) => $q->where('blocks.updated_at', '>=', $cutoff)
                    ->orWhere('payments.updated_at', '>=', $cutoff)->orWhere('requests.updated_at', '>=', $cutoff)
                    ->orWhere('subscriptions.updated_at', '>=', $cutoff)->orWhere('funding.updated_at', '>=', $cutoff));
            }

            return $query;
        };
        $requests = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('meal_plan_requests as requests')
                ->join('membership_promotions as promotions', 'promotions.id', '=', 'requests.promotion_id')
                ->where('promotions.company_id', $companyId)->where('requests.submission_kind', 'promo_request');
            if (! $full) {
                $query->where('requests.updated_at', '>=', $cutoff);
            }

            return $query;
        };
        $subscriptions = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('meal_subscriptions as subscriptions')
                ->leftJoin('membership_purchase_blocks as blocks', 'blocks.subscription_id', '=', 'subscriptions.id')
                ->leftJoin('membership_booking_funding as funding', 'funding.purchase_block_id', '=', 'blocks.id')
                ->leftJoin('meal_subscription_orders as mappings', 'mappings.subscription_id', '=', 'subscriptions.id')
                ->where('subscriptions.queue_company_id', $companyId)->where('subscriptions.fulfillment_mode', 'customer_selection');
            if (! $full) {
                $query->where(fn ($q) => $q->where('subscriptions.updated_at', '>=', $cutoff)
                    ->orWhere('blocks.updated_at', '>=', $cutoff)->orWhere('funding.updated_at', '>=', $cutoff)
                    ->orWhere('mappings.created_at', '>=', $cutoff));
            }

            return $query;
        };
        $mappings = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('meal_subscription_orders as mappings')
                ->join('meal_subscriptions as subscriptions', 'subscriptions.id', '=', 'mappings.subscription_id')
                ->leftJoin('membership_booking_funding as funding', 'funding.subscription_order_id', '=', 'mappings.id')
                ->leftJoin('orders', 'orders.id', '=', 'mappings.order_id')
                ->leftJoin('ar_invoices as invoices', 'invoices.source_order_id', '=', 'orders.id')
                ->where('subscriptions.queue_company_id', $companyId)->where('subscriptions.fulfillment_mode', 'customer_selection');
            if (! $full) {
                $query->where(fn ($q) => $q->where('mappings.created_at', '>=', $cutoff)
                    ->orWhere('subscriptions.updated_at', '>=', $cutoff)->orWhere('funding.updated_at', '>=', $cutoff)
                    ->orWhere('orders.updated_at', '>=', $cutoff)->orWhere('invoices.updated_at', '>=', $cutoff));
            }

            return $query;
        };
        $promotions = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('membership_promotions as promotions')
                ->leftJoin('membership_promotion_reservations as reservations', 'reservations.promotion_id', '=', 'promotions.id')
                ->leftJoin('membership_promotion_redemptions as redemptions', 'redemptions.promotion_id', '=', 'promotions.id')
                ->leftJoin('meal_plan_requests as requests', 'requests.promotion_id', '=', 'promotions.id')
                ->where('promotions.company_id', $companyId);
            if (! $full) {
                $query->where(fn ($q) => $q->where('promotions.updated_at', '>=', $cutoff)
                    ->orWhere('reservations.updated_at', '>=', $cutoff)->orWhere('redemptions.updated_at', '>=', $cutoff)
                    ->orWhere('requests.updated_at', '>=', $cutoff));
            }

            return $query;
        };
        $payments = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('payments')
                ->leftJoin('payment_allocations as allocations', 'allocations.payment_id', '=', 'payments.id')
                ->leftJoin('membership_purchase_blocks as blocks', 'blocks.payment_id', '=', 'payments.id')
                ->leftJoin('payment_provider_transactions as transactions', 'transactions.payment_id', '=', 'payments.id')
                ->where('payments.company_id', $companyId)->where('payments.source', 'ar');
            if (! $full) {
                $query->where(fn ($q) => $q->where('payments.updated_at', '>=', $cutoff)
                    ->orWhere('allocations.updated_at', '>=', $cutoff)->orWhere('blocks.updated_at', '>=', $cutoff)
                    ->orWhere('transactions.updated_at', '>=', $cutoff));
            }

            return $query;
        };
        $imports = function () use ($companyId, $full, $cutoff): Builder {
            $query = DB::table('gateway_settlement_imports as imports')
                ->leftJoin('gateway_settlement_rows as rows', 'rows.import_id', '=', 'imports.id')
                ->leftJoin('ar_clearing_settlements as settlements', 'settlements.gateway_import_id', '=', 'imports.id')
                ->leftJoin('ar_clearing_settlement_items as settlement_items', 'settlement_items.settlement_id', '=', 'settlements.id')
                ->where('imports.company_id', $companyId);
            if (! $full) {
                $query->where(fn ($q) => $q->where('imports.updated_at', '>=', $cutoff)
                    ->orWhere('rows.updated_at', '>=', $cutoff)->orWhere('settlements.updated_at', '>=', $cutoff)
                    ->orWhere('settlement_items.updated_at', '>=', $cutoff));
            }

            return $query;
        };

        $streams = [
            $this->stream('provider_attempts', 'provider_checkout_v1', 'payment_checkout_attempt', 'attempts.id', $attempts()),
            $this->stream('notification_attempts', 'notification_operations_v1', 'payment_checkout_attempt', 'attempts.id', $attempts()),
            $this->stream('ordinary_targets', 'ordinary_accounting_v1', 'payment_checkout_target', 'targets.id', $ordinaryTargets()),
            $this->stream('membership_blocks', 'membership_purchase_v1', 'membership_purchase_block', 'blocks.id', $blocks()),
            $this->stream('promotion_requests', 'membership_purchase_v1', 'meal_plan_request', 'requests.id', $requests()),
            $this->stream('notification_requests', 'notification_operations_v1', 'meal_plan_request', 'requests.id', $requests()),
            $this->stream('membership_balances', 'membership_balance_v1', 'meal_subscription', 'subscriptions.id', $subscriptions()),
            $this->stream('membership_sequences', 'membership_sequence_v1', 'meal_subscription', 'subscriptions.id', $subscriptions()),
            $this->stream('booking_corrections', 'booking_correction_v1', 'meal_subscription_order', 'mappings.id', $mappings()),
            $this->stream('booking_policies', 'booking_policy_v1', 'meal_subscription_order', 'mappings.id', $mappings()),
            $this->stream('booking_notifications', 'notification_operations_v1', 'meal_subscription_order', 'mappings.id', $mappings()),
            $this->stream('promotions', 'promotion_usage_v1', 'membership_promotion', 'promotions.id', $promotions()),
            $this->stream('saved_credit', 'saved_credit_v1', 'payment', 'payments.id', $payments()),
            $this->stream('settlements', 'settlement_v1', 'gateway_settlement_import', 'imports.id', $imports()),
        ];
        if ($companyId === (int) ($this->accountingContext->defaultCompanyId() ?? 0)) {
            $customers = DB::table('customers');
            if (! $full) {
                $customers->where(fn ($q) => $q->where('customers.updated_at', '>=', $cutoff)
                    ->orWhereExists(fn ($users) => $users->selectRaw('1')->from('users')
                        ->whereColumn('users.customer_id', 'customers.id')->where('users.updated_at', '>=', $cutoff))
                    ->orWhereExists(fn ($reviews) => $reviews->selectRaw('1')->from('customer_match_reviews')
                        ->where(fn ($owner) => $owner->whereColumn('customer_match_reviews.customer_id', 'customers.id')
                            ->orWhereColumn('customer_match_reviews.candidate_customer_id', 'customers.id'))
                        ->where('customer_match_reviews.updated_at', '>=', $cutoff)));
            }
            $streams[] = $this->stream('customer_ownership', 'customer_ownership_v1', 'customer', 'customers.id', $customers);
        }

        return $streams;
    }

    /** @return array{key:string,rule_code:string,subject_type:string,id_column:string,query:Builder} */
    private function stream(string $key, string $ruleCode, string $subjectType, string $idColumn, Builder $query): array
    {
        return [
            'key' => $key,
            'rule_code' => $ruleCode,
            'subject_type' => $subjectType,
            'id_column' => $idColumn,
            'query' => $query,
        ];
    }

    private function recoverDeferredRetries(int $companyId): int
    {
        return $this->recoverRuns(PaymentConsistencyRun::query()->where('company_id', $companyId)
            ->where('state', PaymentConsistencyRun::STATE_QUEUED)->whereNotNull('not_before')
            ->where('not_before', '<=', now('UTC'))->where('trigger_key', 'like', 'deferred:%'));
    }

    private function recoverQueuedSweepChildren(int $companyId): int
    {
        return $this->recoverRuns(PaymentConsistencyRun::query()->where('company_id', $companyId)
            ->where('state', PaymentConsistencyRun::STATE_QUEUED)->whereNotNull('parent_run_id')->whereNull('not_before'));
    }

    private function recoverFailedSweepChildren(int $companyId): int
    {
        $runs = PaymentConsistencyRun::query()->where('company_id', $companyId)
            ->where('state', PaymentConsistencyRun::STATE_FAILED)->whereNotNull('parent_run_id')
            ->orderBy('id')->limit($this->batchSize())->get();
        foreach ($runs as $run) {
            $run->update(['state' => PaymentConsistencyRun::STATE_QUEUED, 'started_at' => null, 'completed_at' => null]);
        }

        return $this->dispatchRuns($runs);
    }

    private function recoverStaleRuns(int $companyId): int
    {
        $staleBefore = now('UTC')->subSeconds(max(180, (int) config('payment_consistency.run_stale_seconds', 300)));
        $runs = PaymentConsistencyRun::query()->where('company_id', $companyId)
            ->where('state', PaymentConsistencyRun::STATE_RUNNING)->where('target_type', '!=', self::PARENT_TARGET_TYPE)
            ->where(fn ($q) => $q->where('heartbeat_at', '<=', $staleBefore)
                ->orWhere(fn ($missing) => $missing->whereNull('heartbeat_at')->where('started_at', '<=', $staleBefore)))
            ->orderBy('id')->limit($this->batchSize())->get();
        foreach ($runs as $run) {
            $run->update(['state' => PaymentConsistencyRun::STATE_QUEUED, 'started_at' => null, 'heartbeat_at' => null]);
        }

        return $this->dispatchRuns($runs);
    }

    private function recoverRuns($query): int
    {
        return $this->dispatchRuns($query->orderBy('id')->limit($this->batchSize())->get());
    }

    private function dispatchRuns(Collection $runs): int
    {
        $count = 0;
        foreach ($runs as $run) {
            $ok = (string) $run->rule_code === 'promotion_usage_v1'
                ? $this->dispatcher->dispatchPromotion((int) $run->target_id, (string) $run->kind, (string) $run->trigger_key, $run->requested_by, $run->parent_run_id)
                : $this->dispatcher->dispatchRule((string) $run->rule_code, (string) $run->target_type, (int) $run->target_id, (string) $run->kind, (string) $run->trigger_key, $run->requested_by, $run->parent_run_id);
            if ($ok) {
                $count++;
            }
        }

        return $count;
    }

    /** @return Collection<int, int> */
    private function companyIds(?int $companyId): Collection
    {
        if ($companyId !== null && $companyId <= 0) {
            throw new \InvalidArgumentException('Company ID must be positive.');
        }

        return DB::table('accounting_companies')->when($companyId !== null, fn ($q) => $q->where('id', $companyId))
            ->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id);
    }

    private function slot(string $mode): string
    {
        if ($mode === PaymentConsistencyRun::KIND_FULL) {
            return now((string) config('payment_consistency.timezone', 'Asia/Qatar'))->format('Ymd');
        }
        $slot = now('UTC')->startOfMinute();
        $slot->setMinute(intdiv($slot->minute, 15) * 15);

        return $slot->format('Ymd\THi');
    }

    private function batchSize(): int
    {
        return max(1, min(100, (int) config('payment_consistency.batch_size', 100)));
    }

    /** @return array{enabled:bool,mode:string,companies:int,subjects:int,promotions:int,dispatched:int,dispatch_failed:int} */
    private function result(bool $enabled, string $mode, int $companies, int $subjects, int $promotions, int $dispatched, int $dispatchFailed): array
    {
        return compact('enabled', 'mode', 'companies', 'subjects', 'promotions', 'dispatched') + ['dispatch_failed' => $dispatchFailed];
    }
}
