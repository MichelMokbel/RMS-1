<?php

namespace App\Services\Payments;

use App\Models\MembershipPromotion;
use App\Models\PaymentConsistencyRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentConsistencySweepService
{
    public function __construct(
        private readonly PaymentConsistencyDispatchService $dispatcher,
    ) {}

    /** @return array{enabled:bool,mode:string,companies:int,promotions:int,dispatched:int,dispatch_failed:int} */
    public function dispatch(string $mode, ?int $companyId = null): array
    {
        if (! in_array($mode, [PaymentConsistencyRun::KIND_CATCHUP, PaymentConsistencyRun::KIND_FULL], true)) {
            throw new \InvalidArgumentException('Consistency sweep mode must be catchup or full.');
        }
        if (! (bool) config('payment_consistency.enabled', false)) {
            return $this->result(false, $mode, 0, 0, 0, 0);
        }

        $companyIds = $this->companyIds($companyId);
        $promotionCount = 0;
        $dispatched = 0;
        $dispatchFailed = 0;
        $slot = $this->slot($mode);

        foreach ($companyIds as $resolvedCompanyId) {
            $promotionIds = $mode === PaymentConsistencyRun::KIND_FULL
                ? MembershipPromotion::query()
                    ->where('company_id', $resolvedCompanyId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                : $this->changedPromotionIds($resolvedCompanyId, now('UTC')->subMinutes(
                    max(5, (int) config('payment_consistency.catchup_lookback_minutes', 20)),
                ));

            foreach ($promotionIds->chunk($this->batchSize()) as $batch) {
                foreach ($batch as $promotionId) {
                    $promotionCount++;
                    $triggerKey = "{$mode}:{$slot}:promotion:{$promotionId}";
                    if ($this->dispatcher->dispatchPromotion((int) $promotionId, $mode, $triggerKey)) {
                        $dispatched++;
                    } else {
                        $dispatchFailed++;
                    }
                }
            }
        }

        return $this->result(
            true,
            $mode,
            $companyIds->count(),
            $promotionCount,
            $dispatched,
            $dispatchFailed,
        );
    }

    /** @return Collection<int,int> */
    private function companyIds(?int $companyId): Collection
    {
        $query = MembershipPromotion::query()->select('company_id')->distinct();
        if ($companyId !== null) {
            if ($companyId <= 0) {
                throw new \InvalidArgumentException('Company ID must be positive.');
            }
            $query->where('company_id', $companyId);
        }

        return $query->orderBy('company_id')->pluck('company_id')->map(fn ($id): int => (int) $id);
    }

    /** @return Collection<int,int> */
    private function changedPromotionIds(int $companyId, CarbonInterface $cutoff): Collection
    {
        $ids = collect();
        $append = function (Collection $changed) use (&$ids): void {
            $ids = $ids->merge($changed->map(fn ($id): int => (int) $id));
        };

        $append(DB::table('membership_promotions')
            ->where('company_id', $companyId)
            ->where('updated_at', '>=', $cutoff)
            ->pluck('id'));
        $append(DB::table('membership_promotion_reservations')
            ->where('company_id', $companyId)
            ->where('updated_at', '>=', $cutoff)
            ->pluck('promotion_id'));
        $append(DB::table('membership_promotion_redemptions')
            ->where('company_id', $companyId)
            ->where('updated_at', '>=', $cutoff)
            ->pluck('promotion_id'));

        foreach (['membership_promotion_reservations', 'membership_promotion_redemptions'] as $usageTable) {
            $append(DB::table("{$usageTable} as usage")
                ->join('payment_checkout_attempts as attempts', 'attempts.id', '=', 'usage.checkout_id')
                ->where('usage.company_id', $companyId)
                ->where('attempts.updated_at', '>=', $cutoff)
                ->pluck('usage.promotion_id'));
        }

        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('meal_plan_requests as requests', 'requests.id', '=', 'redemptions.meal_plan_request_id')
            ->where('redemptions.company_id', $companyId)
            ->where('requests.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));
        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('membership_purchase_blocks as blocks', 'blocks.id', '=', 'redemptions.purchase_block_id')
            ->where('redemptions.company_id', $companyId)
            ->where('blocks.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));
        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('membership_purchase_blocks as blocks', 'blocks.id', '=', 'redemptions.purchase_block_id')
            ->join('payments', 'payments.id', '=', 'blocks.payment_id')
            ->where('redemptions.company_id', $companyId)
            ->where('payments.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));
        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('meal_plan_requests as requests', 'requests.id', '=', 'redemptions.meal_plan_request_id')
            ->join('meal_subscriptions as subscriptions', 'subscriptions.meal_plan_request_id', '=', 'requests.id')
            ->where('redemptions.company_id', $companyId)
            ->where('subscriptions.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));
        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('meal_plan_request_orders as request_orders', 'request_orders.meal_plan_request_id', '=', 'redemptions.meal_plan_request_id')
            ->where('redemptions.company_id', $companyId)
            ->where('request_orders.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));
        $append(DB::table('membership_promotion_redemptions as redemptions')
            ->join('customers', 'customers.id', '=', 'redemptions.original_customer_id')
            ->where('redemptions.company_id', $companyId)
            ->where('customers.updated_at', '>=', $cutoff)
            ->pluck('redemptions.promotion_id'));

        return $ids->unique()->sort()->values();
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
        return min(100, max(1, (int) config('payment_consistency.batch_size', 100)));
    }

    /** @return array{enabled:bool,mode:string,companies:int,promotions:int,dispatched:int,dispatch_failed:int} */
    private function result(
        bool $enabled,
        string $mode,
        int $companies,
        int $promotions,
        int $dispatched,
        int $dispatchFailed,
    ): array {
        return compact('enabled', 'mode', 'companies', 'promotions', 'dispatched') + [
            'dispatch_failed' => $dispatchFailed,
        ];
    }
}
