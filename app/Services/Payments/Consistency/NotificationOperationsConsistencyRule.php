<?php

namespace App\Services\Payments\Consistency;

use App\Models\MealPlanRequest;
use App\Models\MealSubscriptionOrder;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Accounting\AccountingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class NotificationOperationsConsistencyRule implements PaymentConsistencyRule
{
    public const CODE = 'notification_operations_v1';

    public function __construct(
        private readonly AccountingContextService $accountingContext,
    ) {}

    public function ruleCodes(): array
    {
        return [self::CODE];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $parent = $this->parent($ruleCode, $subjectType, $subjectId);
        if ($parent instanceof PaymentCheckoutAttempt) {
            return [
                'company_id' => (int) $parent->company_id,
                'branch_id' => (int) $parent->branch_id,
                'checkout_id' => (int) $parent->id,
            ];
        }
        if ($parent instanceof MealPlanRequest) {
            $promotionCompanyId = $parent->promotion_id
                ? DB::table('membership_promotions')->where('id', $parent->promotion_id)->value('company_id')
                : null;

            return [
                'company_id' => (int) ($promotionCompanyId ?: $this->defaultCompanyId()),
                'branch_id' => null,
                'checkout_id' => null,
            ];
        }

        $subscription = DB::table('meal_subscriptions')->where('id', $parent->subscription_id)->first(['queue_company_id']);

        return [
            'company_id' => (int) ($subscription?->queue_company_id ?: $this->defaultCompanyId()),
            'branch_id' => (int) $parent->branch_id,
            'checkout_id' => null,
        ];
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $parent = $this->parent($ruleCode, $subjectType, $subjectId);
        $scope = $this->scope($ruleCode, $subjectType, $subjectId);
        $dispatch = is_array($parent->notification_dispatch) ? $parent->notification_dispatch : [];
        $requiredSlots = $this->requiredSlots($parent);
        $emailLogs = $this->emailLogs($parent);
        $issues = [];
        $deferred = false;
        $now = CarbonImmutable::now('UTC');

        if ($requiredSlots !== [] && $dispatch === []) {
            $issues[] = ConsistencyEvidence::issue('NOTIFICATION_INTENT_MISSING', $subjectType, $subjectId);
        }
        foreach ($requiredSlots as $slotKey) {
            $slot = is_array($dispatch[$slotKey] ?? null) ? $dispatch[$slotKey] : [];
            $state = (string) ($slot['state'] ?? 'missing');
            if ($state === 'missing') {
                $issues[] = ConsistencyEvidence::issue('NOTIFICATION_SLOT_MISSING', $subjectType, $subjectId);

                continue;
            }
            if (in_array($state, ['pending', 'queued', 'sending', 'retryable'], true)) {
                $due = $slot['next_retry_at'] ?? $slot['started_at'] ?? $parent->updated_at ?? $parent->created_at;
                $dueAt = $due ? CarbonImmutable::parse($due)->addMinutes(10) : $now;
                if ($state === 'retryable' && ! empty($slot['next_retry_at'])) {
                    $dueAt = CarbonImmutable::parse((string) $slot['next_retry_at']);
                }
                if ($now->lessThan($dueAt)) {
                    $deferred = true;
                } else {
                    $issues[] = ConsistencyEvidence::issue('NOTIFICATION_OPERATION_STALLED', $subjectType, $subjectId);
                }

                continue;
            }
            if (! in_array($state, ['sent', 'failed', 'skipped', 'suppressed', 'superseded'], true)) {
                $issues[] = ConsistencyEvidence::issue('NOTIFICATION_STATE_INVALID', $subjectType, $subjectId);
            }
            if ($state === 'sent' && ! $this->hasSuccessfulLog($emailLogs, $slotKey)) {
                $issues[] = ConsistencyEvidence::issue('NOTIFICATION_SUCCESS_LOG_MISSING', $subjectType, $subjectId);
            }
        }

        if ($parent instanceof PaymentCheckoutAttempt) {
            $tracking = is_array($parent->operations_tracking) ? $parent->operations_tracking : [];
            foreach ((array) ($tracking['issues'] ?? []) as $issue) {
                if (! is_array($issue) || ! empty($issue['resolved_at'])) {
                    continue;
                }
                $episode = (string) ($issue['episode_uuid'] ?? '');
                $alert = is_array($issue['alert'] ?? null) ? $issue['alert'] : [];
                if ($episode === '' || ($alert !== [] && (string) ($alert['episode_uuid'] ?? '') !== $episode)) {
                    $issues[] = ConsistencyEvidence::issue('OPERATIONS_ISSUE_EPISODE_MISMATCH', $subjectType, $subjectId);
                }
            }
        }

        return ConsistencyEvidence::result(
            (int) $scope['company_id'],
            $scope['branch_id'],
            $scope['checkout_id'],
            [
                'committed_parent_has_typed_notification_intent' => true,
                'successful_delivery_has_email_log' => true,
                'failed_delivery_does_not_change_parent' => true,
                'one_operations_alert_per_issue_episode' => true,
            ],
            [
                'parent_type' => $subjectType,
                'parent_id' => $subjectId,
                'required_slots' => $requiredSlots,
                'dispatch_states' => collect($dispatch)->map(fn ($slot): string => is_array($slot) ? (string) ($slot['state'] ?? 'missing') : 'invalid')->all(),
                'email_log_ids' => $emailLogs->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'deferred_reason' => $deferred ? 'NOTIFICATION_WORK_NOT_DUE' : null,
            ],
            [
                'parent' => $this->parentEvidence($parent),
                'dispatch' => $this->dispatchEvidence($dispatch),
                'email_logs' => $emailLogs->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
            $deferred,
        );
    }

    private function parent(string $ruleCode, string $subjectType, int $subjectId): Model
    {
        if ($ruleCode !== self::CODE) {
            throw new \InvalidArgumentException('Unsupported notification consistency rule.');
        }

        return match ($subjectType) {
            'payment_checkout_attempt' => PaymentCheckoutAttempt::query()->findOrFail($subjectId),
            'meal_plan_request' => MealPlanRequest::query()->findOrFail($subjectId),
            'meal_subscription_order' => MealSubscriptionOrder::query()->findOrFail($subjectId),
            default => throw new \InvalidArgumentException('Unsupported notification consistency subject.'),
        };
    }

    /** @return array<int, string> */
    private function requiredSlots(Model $parent): array
    {
        if ($parent instanceof PaymentCheckoutAttempt) {
            return (string) $parent->state === 'completed'
                ? ['customer_confirmation', 'admin_confirmation']
                : [];
        }
        if ($parent instanceof MealPlanRequest) {
            return $parent->submission_kind === 'promo_request'
                ? ['customer_confirmation', 'admin_confirmation']
                : [];
        }

        return array_values(array_intersect(
            ['customer_creation', 'customer_change', 'customer_cancellation'],
            array_keys((array) $parent->notification_dispatch),
        ));
    }

    private function emailLogs(Model $parent)
    {
        $query = DB::table('email_logs');
        if ($parent instanceof PaymentCheckoutAttempt) {
            $orderIds = DB::table('payment_checkout_targets')->where('attempt_id', $parent->id)->pluck('order_id')->filter();
            $requestIds = DB::table('payment_checkout_targets')->where('attempt_id', $parent->id)->pluck('meal_plan_request_id')->filter();
            $query->where(function ($inner) use ($orderIds, $requestIds): void {
                $inner->whereIn('order_id', $orderIds)->orWhereIn('meal_plan_request_id', $requestIds);
            });
        } elseif ($parent instanceof MealPlanRequest) {
            $query->where('meal_plan_request_id', $parent->id);
        } else {
            $query->where('order_id', $parent->order_id);
        }

        return $query->orderBy('id')->get([
            'id', 'category', 'recipient_type', 'status', 'user_id', 'order_id', 'meal_plan_request_id',
            'sent_at', 'created_at', 'updated_at',
        ]);
    }

    private function hasSuccessfulLog($emailLogs, string $slotKey): bool
    {
        $recipientType = str_contains($slotKey, 'admin') ? 'admin' : 'customer';

        return $emailLogs->contains(fn ($log): bool => (string) $log->recipient_type === $recipientType
            && in_array((string) $log->status, ['sent', 'skipped'], true));
    }

    /** @return array<string, mixed> */
    private function parentEvidence(Model $parent): array
    {
        if ($parent instanceof PaymentCheckoutAttempt) {
            return $parent->only([
                'id', 'company_id', 'branch_id', 'customer_id', 'portal_user_id', 'purpose', 'state',
                'completed_at', 'last_error_code', 'operations_next_action_at', 'created_at', 'updated_at',
            ]);
        }
        if ($parent instanceof MealPlanRequest) {
            return $parent->only([
                'id', 'customer_id', 'user_id', 'submission_kind', 'promotion_id', 'redemption_id',
                'status', 'converted_subscription_id', 'created_at', 'updated_at',
            ]);
        }

        return $parent->only([
            'id', 'subscription_id', 'order_id', 'service_date', 'branch_id', 'booking_uuid',
            'booking_revision', 'supersedes_subscription_order_id', 'accepted_operation_uuid', 'created_at',
        ]);
    }

    /** @param array<string, mixed> $dispatch
     * @return array<string, mixed>
     */
    private function dispatchEvidence(array $dispatch): array
    {
        $evidence = [];
        foreach ($dispatch as $key => $slot) {
            if (! is_array($slot)) {
                $evidence[$key] = ['state' => 'invalid'];

                continue;
            }
            $evidence[$key] = collect($slot)->only([
                'state', 'attempts', 'started_at', 'sent_at', 'finished_at', 'failed_at',
                'next_retry_at', 'error_code', 'episode_uuid', 'kind', 'suppressed_at', 'superseded_at',
            ])->all();
        }

        return $evidence;
    }

    private function defaultCompanyId(): int
    {
        $companyId = (int) ($this->accountingContext->defaultCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new \RuntimeException('The default company is unavailable for notification consistency checks.');
        }

        return $companyId;
    }
}
