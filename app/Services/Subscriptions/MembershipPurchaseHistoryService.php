<?php

namespace App\Services\Subscriptions;

use App\Models\MembershipPurchaseBlock;
use App\Services\Customers\CustomerOwnershipService;
use Illuminate\Support\Facades\DB;

class MembershipPurchaseHistoryService
{
    public function __construct(
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    /**
     * Resolve completed membership purchases across the approved customer merge chain.
     * A cancelled or exhausted purchase remains completed history.
     *
     * @return array{canonical_customer_id:int,customer_ids:array<int,int>,completed_purchase_count:int,has_completed_purchase:bool,evidence:array<int,array<string,mixed>>}
     */
    public function resolve(int $customerId, ?int $companyId = null): array
    {
        $canonicalCustomerId = $this->customerOwnership->canonicalCustomerId($customerId);
        $customerIds = $this->customerOwnership->historicalCustomerIds($canonicalCustomerId);
        $evidence = [];
        $seenRequestIds = [];
        $seenSubscriptionIds = [];

        $blocks = MembershipPurchaseBlock::query()
            ->select([
                'id',
                'subscription_id',
                'meal_plan_request_id',
                'company_id',
                'original_customer_id',
                'origin',
                'funded_at',
                'cancelled_at',
            ])
            ->whereIn('original_customer_id', $customerIds)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('funded_at')
            ->orderBy('id')
            ->get();

        foreach ($blocks as $block) {
            $requestId = $block->meal_plan_request_id ? (int) $block->meal_plan_request_id : null;
            $subscriptionId = (int) $block->subscription_id;
            if ($requestId !== null) {
                $seenRequestIds[$requestId] = true;
            }
            $seenSubscriptionIds[$subscriptionId] = true;
            $evidence[] = [
                'source' => 'purchase_block',
                'source_id' => (int) $block->id,
                'request_id' => $requestId,
                'subscription_id' => $subscriptionId,
                'original_customer_id' => (int) $block->original_customer_id,
                'completed_at' => $block->funded_at?->toIso8601String(),
                'cancelled' => $block->cancelled_at !== null,
                'origin' => (string) $block->origin,
            ];
        }

        $legacyConversions = DB::table('meal_subscriptions as subscriptions')
            ->join('meal_plan_requests as requests', 'requests.id', '=', 'subscriptions.meal_plan_request_id')
            ->join('branches', 'branches.id', '=', 'subscriptions.branch_id')
            ->whereIn('subscriptions.customer_id', $customerIds)
            ->where('requests.status', 'converted')
            ->where('requests.plan_meals', '>', 0)
            ->when($companyId !== null, fn ($query) => $query->where('branches.company_id', $companyId))
            ->select([
                'subscriptions.id as subscription_id',
                'subscriptions.customer_id',
                'subscriptions.created_at as subscription_created_at',
                'requests.id as request_id',
                'requests.converted_at',
            ])
            ->orderBy('subscriptions.created_at')
            ->orderBy('subscriptions.id')
            ->get();

        foreach ($legacyConversions as $conversion) {
            $requestId = (int) $conversion->request_id;
            $subscriptionId = (int) $conversion->subscription_id;
            if (isset($seenRequestIds[$requestId]) || isset($seenSubscriptionIds[$subscriptionId])) {
                continue;
            }

            $seenRequestIds[$requestId] = true;
            $seenSubscriptionIds[$subscriptionId] = true;
            $evidence[] = [
                'source' => 'legacy_conversion',
                'source_id' => $requestId,
                'request_id' => $requestId,
                'subscription_id' => $subscriptionId,
                'original_customer_id' => (int) $conversion->customer_id,
                'completed_at' => $conversion->converted_at ?? $conversion->subscription_created_at,
                'cancelled' => false,
                'origin' => 'legacy',
            ];
        }

        return [
            'canonical_customer_id' => $canonicalCustomerId,
            'customer_ids' => $customerIds,
            'completed_purchase_count' => count($evidence),
            'has_completed_purchase' => $evidence !== [],
            'evidence' => $evidence,
        ];
    }

    public function hasCompletedPurchase(int $customerId, ?int $companyId = null): bool
    {
        return $this->resolve($customerId, $companyId)['has_completed_purchase'];
    }
}
