<?php

namespace App\Services\Promotions;

use App\Models\AccountingAuditLog;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipPromotionService
{
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const TIMEZONE = 'Asia/Qatar';

    public function __construct(
        private readonly PromotionAccessService $access,
        private readonly PromotionUsageProjectionService $usage,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input, string $operationUuid): MembershipPromotion
    {
        $companyId = $this->access->companyIdFor($actor);
        $this->assertAuditStorage();
        $operationUuid = $this->validOperationUuid($operationUuid);
        $draft = $this->validateDraft($companyId, $input);
        $fingerprint = $this->fingerprint('create', $companyId, null, $draft);

        return DB::transaction(function () use ($actor, $companyId, $operationUuid, $draft, $fingerprint): MembershipPromotion {
            $this->lockAndAuthorizeActor($actor, $companyId);
            if ($replay = $this->replay($actor, $operationUuid, 'membership_promotion.created', $fingerprint)) {
                return $replay;
            }

            $promotion = $this->insertDraft($companyId, $actor, $draft);
            $after = $this->snapshot($promotion);
            $this->record('membership_promotion.created', $actor, $promotion, $operationUuid, $fingerprint, null, $after);

            return $promotion;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function updateDraft(
        User $actor,
        MembershipPromotion $promotion,
        int $expectedRevision,
        array $input,
        string $operationUuid,
    ): MembershipPromotion {
        $companyId = $this->access->assertOwns($actor, $promotion);
        $this->assertAuditStorage();
        $operationUuid = $this->validOperationUuid($operationUuid);
        $draft = $this->validateDraft($companyId, $input);
        $fingerprint = $this->fingerprint('update_draft', $companyId, (int) $promotion->id, [
            'expected_revision' => $expectedRevision,
            'draft' => $draft,
        ]);

        return DB::transaction(function () use (
            $actor,
            $promotion,
            $companyId,
            $expectedRevision,
            $operationUuid,
            $draft,
            $fingerprint,
        ): MembershipPromotion {
            $this->lockAndAuthorizeActor($actor, $companyId);
            $locked = $this->lockPromotion($promotion, $companyId);
            if ($replay = $this->replay($actor, $operationUuid, 'membership_promotion.updated', $fingerprint, $locked->id)) {
                return $replay;
            }
            $this->assertRevision($locked, $expectedRevision);
            if ($locked->status !== MembershipPromotion::STATUS_DRAFT) {
                throw new PromotionConflictException(__('Activated promotion terms cannot be edited. Copy the code as a new draft instead.'), $locked->revision);
            }

            $before = $this->snapshot($locked);
            $locked->forceFill($this->promotionFields($draft) + [
                'revision' => $locked->revision + 1,
                'updated_by' => $actor->id,
            ])->save();
            $locked->plans()->sync($draft['plan_ids']);
            $locked->load('plans');
            $this->record('membership_promotion.updated', $actor, $locked, $operationUuid, $fingerprint, $before, $this->snapshot($locked));

            return $locked;
        }, 3);
    }

    public function activate(User $actor, MembershipPromotion $promotion, int $expectedRevision, string $operationUuid): MembershipPromotion
    {
        return $this->changeState($actor, $promotion, $expectedRevision, $operationUuid, 'activate');
    }

    public function pause(User $actor, MembershipPromotion $promotion, int $expectedRevision, string $operationUuid): MembershipPromotion
    {
        return $this->changeState($actor, $promotion, $expectedRevision, $operationUuid, 'pause');
    }

    public function resume(User $actor, MembershipPromotion $promotion, int $expectedRevision, string $operationUuid): MembershipPromotion
    {
        return $this->changeState($actor, $promotion, $expectedRevision, $operationUuid, 'resume');
    }

    public function expire(User $actor, MembershipPromotion $promotion, int $expectedRevision, string $operationUuid): MembershipPromotion
    {
        return $this->changeState($actor, $promotion, $expectedRevision, $operationUuid, 'expire');
    }

    public function increaseLimit(
        User $actor,
        MembershipPromotion $promotion,
        int $expectedRevision,
        int $newTotalLimit,
        string $operationUuid,
    ): MembershipPromotion {
        $companyId = $this->access->assertOwns($actor, $promotion);
        $this->assertAuditStorage();
        $operationUuid = $this->validOperationUuid($operationUuid);
        if ($newTotalLimit < 1 || $newTotalLimit > 4294967295) {
            throw ValidationException::withMessages(['total_limit' => __('The total limit must be positive.')]);
        }
        $fingerprint = $this->fingerprint('increase_limit', $companyId, (int) $promotion->id, [
            'expected_revision' => $expectedRevision,
            'new_total_limit' => $newTotalLimit,
        ]);

        return DB::transaction(function () use (
            $actor,
            $promotion,
            $companyId,
            $expectedRevision,
            $newTotalLimit,
            $operationUuid,
            $fingerprint,
        ): MembershipPromotion {
            $this->lockAndAuthorizeActor($actor, $companyId);
            $locked = $this->lockPromotion($promotion, $companyId);
            if ($replay = $this->replay($actor, $operationUuid, 'membership_promotion.limit_increased', $fingerprint, $locked->id)) {
                return $replay;
            }
            $this->assertRevision($locked, $expectedRevision);
            if ($locked->status === MembershipPromotion::STATUS_EXPIRED) {
                throw new PromotionConflictException(__('An expired promotion cannot be changed.'), $locked->revision);
            }
            if ($newTotalLimit <= (int) $locked->total_limit) {
                throw ValidationException::withMessages(['total_limit' => __('The new total limit must be greater than the current limit.')]);
            }
            $usage = $this->usage->forPromotion($locked);
            if ($newTotalLimit < $usage['completed'] + $usage['reserved']) {
                throw ValidationException::withMessages(['total_limit' => __('The total limit cannot be lower than completed and protected uses.')]);
            }

            $before = $this->snapshot($locked);
            $locked->forceFill([
                'total_limit' => $newTotalLimit,
                'revision' => $locked->revision + 1,
                'updated_by' => $actor->id,
            ])->save();
            $this->record('membership_promotion.limit_increased', $actor, $locked, $operationUuid, $fingerprint, $before, $this->snapshot($locked));

            return $locked;
        }, 3);
    }

    public function copyAsDraft(User $actor, MembershipPromotion $source, string $operationUuid): MembershipPromotion
    {
        $companyId = $this->access->assertOwns($actor, $source);
        $this->assertAuditStorage();
        $operationUuid = $this->validOperationUuid($operationUuid);
        $source->loadMissing('plans');
        $copy = [
            'discount_type' => $source->discount_type,
            'fixed_amount_cents' => $source->fixed_amount_cents,
            'percentage_basis_points' => $source->percentage_basis_points,
            'purchase_eligibility' => $source->purchase_eligibility,
            'starts_at' => $source->starts_at->copy(),
            'ends_at' => $source->ends_at->copy(),
            'total_limit' => (int) $source->total_limit,
            'per_customer_limit' => (int) $source->per_customer_limit,
            'plan_ids' => $source->plans->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'plan_codes' => $source->plans->pluck('code')->map(fn ($code): string => (string) $code)->sort()->values()->all(),
            'plan_prices' => $source->plans->pluck('package_price_cents')->map(fn ($value): int => (int) $value)->sort()->values()->all(),
        ];
        $fingerprint = $this->fingerprint('copy', $companyId, (int) $source->id, $copy);

        return DB::transaction(function () use ($actor, $source, $companyId, $operationUuid, $copy, $fingerprint): MembershipPromotion {
            $this->lockAndAuthorizeActor($actor, $companyId);
            if ($replay = $this->replay($actor, $operationUuid, 'membership_promotion.copied', $fingerprint)) {
                return $replay;
            }
            $lockedSource = $this->lockPromotion($source, $companyId);
            $promotion = $this->insertDraft($companyId, $actor, $copy);
            $this->record(
                'membership_promotion.copied',
                $actor,
                $promotion,
                $operationUuid,
                $fingerprint,
                null,
                $this->snapshot($promotion),
                ['source_promotion_id' => (int) $lockedSource->id],
            );

            return $promotion;
        }, 3);
    }

    public function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (! preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{12}$/D', $normalized)) {
            throw ValidationException::withMessages([
                'promo_code' => __('Enter the promotion code exactly as it was shared.'),
            ]);
        }

        return $normalized;
    }

    private function changeState(
        User $actor,
        MembershipPromotion $promotion,
        int $expectedRevision,
        string $operationUuid,
        string $action,
    ): MembershipPromotion {
        $companyId = $this->access->assertOwns($actor, $promotion);
        $this->assertAuditStorage();
        $operationUuid = $this->validOperationUuid($operationUuid);
        $auditAction = 'membership_promotion.'.$action.'d';
        if ($action === 'pause') {
            $auditAction = 'membership_promotion.paused';
        } elseif ($action === 'resume') {
            $auditAction = 'membership_promotion.resumed';
        } elseif ($action === 'expire') {
            $auditAction = 'membership_promotion.expired';
        }
        $fingerprint = $this->fingerprint($action, $companyId, (int) $promotion->id, [
            'expected_revision' => $expectedRevision,
        ]);

        return DB::transaction(function () use (
            $actor,
            $promotion,
            $companyId,
            $expectedRevision,
            $operationUuid,
            $action,
            $auditAction,
            $fingerprint,
        ): MembershipPromotion {
            $this->lockAndAuthorizeActor($actor, $companyId);
            $locked = $this->lockPromotion($promotion, $companyId);
            if ($replay = $this->replay($actor, $operationUuid, $auditAction, $fingerprint, $locked->id)) {
                return $replay;
            }
            $this->assertRevision($locked, $expectedRevision);
            $this->assertStateTransition($locked, $action);

            $before = $this->snapshot($locked);
            $now = now('UTC');
            $values = [
                'revision' => $locked->revision + 1,
                'updated_by' => $actor->id,
            ];
            if ($action === 'activate' || $action === 'resume') {
                if ($locked->ends_at->lessThanOrEqualTo($now)) {
                    throw new PromotionConflictException(__('This promotion has already reached its end date.'), $locked->revision);
                }
                if ($locked->plans->isEmpty() || $locked->plans->contains(function (MembershipPlan $plan) use ($companyId): bool {
                    return (int) $plan->company_id !== $companyId
                        || ! $plan->is_active
                        || ! in_array((string) $plan->code, ['20', '26'], true);
                })) {
                    throw ValidationException::withMessages(['eligible_plan_ids' => __('Choose at least one eligible membership plan.')]);
                }
                $values['status'] = MembershipPromotion::STATUS_ACTIVE;
                if ($action === 'activate') {
                    $values['first_activated_at'] = $locked->first_activated_at ?? $now;
                    if ($this->canMakeAnyPlanFree($locked)) {
                        $values['per_customer_limit'] = 1;
                    }
                }
            } elseif ($action === 'pause') {
                $values['status'] = MembershipPromotion::STATUS_PAUSED;
            } else {
                $values['status'] = MembershipPromotion::STATUS_EXPIRED;
                $values['expired_at'] = $now;
            }

            $locked->forceFill($values)->save();
            $locked->load('plans');
            $this->record($auditAction, $actor, $locked, $operationUuid, $fingerprint, $before, $this->snapshot($locked));

            return $locked;
        }, 3);
    }

    private function assertStateTransition(MembershipPromotion $promotion, string $action): void
    {
        $allowed = match ($action) {
            'activate' => [MembershipPromotion::STATUS_DRAFT],
            'pause' => [MembershipPromotion::STATUS_ACTIVE],
            'resume' => [MembershipPromotion::STATUS_PAUSED],
            'expire' => [MembershipPromotion::STATUS_DRAFT, MembershipPromotion::STATUS_ACTIVE, MembershipPromotion::STATUS_PAUSED],
            default => [],
        };

        if (! in_array($promotion->status, $allowed, true)) {
            throw new PromotionConflictException(__('This promotion action is no longer available. Refresh and try again.'), $promotion->revision);
        }
    }

    private function canMakeAnyPlanFree(MembershipPromotion $promotion): bool
    {
        if ($promotion->discount_type === MembershipPromotion::DISCOUNT_PERCENTAGE) {
            return (int) $promotion->percentage_basis_points === 10000;
        }

        return $promotion->plans()->where('package_price_cents', '<=', (int) $promotion->fixed_amount_cents)->exists();
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateDraft(int $companyId, array $input): array
    {
        $discountType = (string) ($input['discount_type'] ?? '');
        if (! in_array($discountType, [MembershipPromotion::DISCOUNT_FIXED, MembershipPromotion::DISCOUNT_PERCENTAGE], true)) {
            throw ValidationException::withMessages(['discount_type' => __('Choose a fixed QAR or percentage discount.')]);
        }

        $fixedAmountCents = null;
        $percentageBasisPoints = null;
        if ($discountType === MembershipPromotion::DISCOUNT_FIXED) {
            $fixedAmountCents = $this->parseDecimal((string) ($input['fixed_amount'] ?? ''), 'fixed_amount', 999999999);
        } else {
            $percentageBasisPoints = $this->parseDecimal((string) ($input['percentage'] ?? ''), 'percentage', 10000);
        }

        $eligibility = (string) ($input['purchase_eligibility'] ?? '');
        if (! in_array($eligibility, [
            MembershipPromotion::ELIGIBILITY_FIRST,
            MembershipPromotion::ELIGIBILITY_RENEWAL,
            MembershipPromotion::ELIGIBILITY_BOTH,
        ], true)) {
            throw ValidationException::withMessages(['purchase_eligibility' => __('Choose first purchase, renewal, or both.')]);
        }

        $startsAt = $this->qatarDate((string) ($input['starts_on'] ?? ''), 'starts_on');
        $endsAt = $this->qatarDate((string) ($input['ends_on'] ?? ''), 'ends_on')->addDay();
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_on' => __('The end date must be on or after the start date.')]);
        }

        $totalLimit = filter_var($input['total_limit'] ?? null, FILTER_VALIDATE_INT);
        $perCustomerLimit = filter_var($input['per_customer_limit'] ?? 1, FILTER_VALIDATE_INT);
        if ($totalLimit === false || $totalLimit < 1 || $totalLimit > 4294967295) {
            throw ValidationException::withMessages(['total_limit' => __('The total redemption limit must be positive.')]);
        }
        if ($perCustomerLimit === false || $perCustomerLimit < 1 || $perCustomerLimit > 4294967295 || $perCustomerLimit > $totalLimit) {
            throw ValidationException::withMessages(['per_customer_limit' => __('The customer limit must be positive and no greater than the total limit.')]);
        }

        $planIds = collect($input['eligible_plan_ids'] ?? [])
            ->filter(fn ($id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $plans = MembershipPlan::query()
            ->where('company_id', $companyId)
            ->whereIn('code', ['20', '26'])
            ->where('is_active', true)
            ->whereIn('id', $planIds)
            ->orderBy('id')
            ->get();
        if ($planIds->isEmpty() || $plans->count() !== $planIds->count()) {
            throw ValidationException::withMessages(['eligible_plan_ids' => __('Choose at least one supported membership plan from this company.')]);
        }

        return [
            'discount_type' => $discountType,
            'fixed_amount_cents' => $fixedAmountCents,
            'percentage_basis_points' => $percentageBasisPoints,
            'purchase_eligibility' => $eligibility,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'total_limit' => (int) $totalLimit,
            'per_customer_limit' => (int) $perCustomerLimit,
            'plan_ids' => $plans->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'plan_codes' => $plans->pluck('code')->map(fn ($code): string => (string) $code)->all(),
            'plan_prices' => $plans->pluck('package_price_cents')->map(fn ($value): int => (int) $value)->all(),
        ];
    }

    private function parseDecimal(string $value, string $field, int $maximumMinor): int
    {
        $value = trim($value);
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            throw ValidationException::withMessages([$field => __('Use a positive value with at most two decimal places.')]);
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 9) {
            throw ValidationException::withMessages([$field => __('The discount value is too large.')]);
        }
        $minor = ((int) $whole * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($minor < 1 || $minor > $maximumMinor) {
            throw ValidationException::withMessages([$field => __('The discount value is outside the allowed range.')]);
        }

        return $minor;
    }

    private function qatarDate(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), self::TIMEZONE);
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->format('Y-m-d') !== trim($value)) {
            throw ValidationException::withMessages([$field => __('Enter a valid Qatar calendar date.')]);
        }

        return $date->utc();
    }

    /** @param array<string, mixed> $draft */
    private function insertDraft(int $companyId, User $actor, array $draft): MembershipPromotion
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $promotion = MembershipPromotion::query()->create($this->promotionFields($draft) + [
                    'company_id' => $companyId,
                    'code' => $this->generateCode(),
                    'status' => MembershipPromotion::STATUS_DRAFT,
                    'revision' => 1,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $promotion->plans()->attach($draft['plan_ids']);

                return $promotion->load('plans');
            } catch (QueryException $exception) {
                if (! $this->isCodeCollision($exception) || $attempt === 3) {
                    if ($this->isCodeCollision($exception)) {
                        throw ValidationException::withMessages([
                            'code' => __('A promotion code could not be generated right now. Please try again.'),
                        ]);
                    }

                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['code' => __('A promotion code could not be generated right now. Please try again.')]);
    }

    /** @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function promotionFields(array $draft): array
    {
        return [
            'discount_type' => $draft['discount_type'],
            'fixed_amount_cents' => $draft['fixed_amount_cents'],
            'percentage_basis_points' => $draft['percentage_basis_points'],
            'purchase_eligibility' => $draft['purchase_eligibility'],
            'starts_at' => $draft['starts_at'],
            'ends_at' => $draft['ends_at'],
            'total_limit' => $draft['total_limit'],
            'per_customer_limit' => $draft['per_customer_limit'],
        ];
    }

    private function generateCode(): string
    {
        $last = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($index = 0; $index < 12; $index++) {
            $code .= self::CODE_ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    private function isCodeCollision(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[1] ?? '') === '1062'
            && str_contains($exception->getMessage(), 'membership_promotions_company_code_unique');
    }

    private function validOperationUuid(string $operationUuid): string
    {
        $operationUuid = trim($operationUuid);
        if (! Str::isUuid($operationUuid)) {
            throw ValidationException::withMessages(['operation_uuid' => __('A valid operation identifier is required.')]);
        }

        return strtolower($operationUuid);
    }

    private function lockAndAuthorizeActor(User $actor, int $companyId): User
    {
        $locked = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($this->access->companyIdFor($locked) !== $companyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(__('This promotion is outside your company.'));
        }

        return $locked;
    }

    private function lockPromotion(MembershipPromotion $promotion, int $companyId): MembershipPromotion
    {
        $locked = MembershipPromotion::query()
            ->whereKey($promotion->id)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();
        if (! $locked) {
            throw new \Illuminate\Auth\Access\AuthorizationException(__('This promotion is outside your company.'));
        }

        return $locked->load('plans');
    }

    private function assertRevision(MembershipPromotion $promotion, int $expectedRevision): void
    {
        if ($expectedRevision < 1 || (int) $promotion->revision !== $expectedRevision) {
            throw new PromotionConflictException(__('This promotion changed in another session. Refresh and try again.'), $promotion->revision);
        }
    }

    private function assertAuditStorage(): void
    {
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['promotion' => __('Promotions cannot be changed while audit storage is unavailable.')]);
        }
    }

    private function replay(
        User $actor,
        string $operationUuid,
        string $expectedAction,
        string $fingerprint,
        ?int $promotionId = null,
    ): ?MembershipPromotion {
        $audit = AccountingAuditLog::query()
            ->where('actor_id', $actor->id)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
            ->orderBy('id')
            ->first();
        if (! $audit) {
            return null;
        }

        $payload = $audit->payload ?? [];
        if ($audit->action !== $expectedAction
            || ! hash_equals((string) ($payload['fingerprint'] ?? ''), $fingerprint)
            || ($promotionId !== null && (int) $audit->subject_id !== $promotionId)) {
            throw new PromotionConflictException(__('This operation identifier was already used for different promotion input.'));
        }

        return MembershipPromotion::query()->with('plans')->findOrFail((int) $audit->subject_id);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $extra
     */
    private function record(
        string $action,
        User $actor,
        MembershipPromotion $promotion,
        string $operationUuid,
        string $fingerprint,
        ?array $before,
        array $after,
        array $extra = [],
    ): void {
        $this->auditLog->log($action, (int) $actor->id, $promotion, [
            'operation_uuid' => $operationUuid,
            'fingerprint' => $fingerprint,
            'before' => $before,
            'after' => $after,
            'result_revision' => (int) $promotion->revision,
        ] + $extra, (int) $promotion->company_id);
    }

    /** @return array<string, mixed> */
    private function snapshot(MembershipPromotion $promotion): array
    {
        $promotion->loadMissing('plans');

        return [
            'company_id' => (int) $promotion->company_id,
            'code' => (string) $promotion->code,
            'discount_type' => (string) $promotion->discount_type,
            'fixed_amount_cents' => $promotion->fixed_amount_cents === null ? null : (int) $promotion->fixed_amount_cents,
            'percentage_basis_points' => $promotion->percentage_basis_points === null ? null : (int) $promotion->percentage_basis_points,
            'purchase_eligibility' => (string) $promotion->purchase_eligibility,
            'starts_at' => $promotion->starts_at?->utc()->toISOString(),
            'ends_at' => $promotion->ends_at?->utc()->toISOString(),
            'total_limit' => (int) $promotion->total_limit,
            'per_customer_limit' => (int) $promotion->per_customer_limit,
            'status' => (string) $promotion->status,
            'revision' => (int) $promotion->revision,
            'first_activated_at' => $promotion->first_activated_at?->utc()->toISOString(),
            'expired_at' => $promotion->expired_at?->utc()->toISOString(),
            'plan_ids' => $promotion->plans->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'plan_codes' => $promotion->plans->pluck('code')->map(fn ($code): string => (string) $code)->sort()->values()->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(string $action, int $companyId, ?int $promotionId, array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize([
            'action' => $action,
            'company_id' => $companyId,
            'promotion_id' => $promotionId,
            'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->toISOString();
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
