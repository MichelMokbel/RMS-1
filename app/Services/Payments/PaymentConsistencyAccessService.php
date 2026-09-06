<?php

namespace App\Services\Payments;

use App\Models\MembershipPromotion;
use App\Models\PaymentConsistencyFinding;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Promotions\PromotionAccessService;
use Illuminate\Auth\Access\AuthorizationException;

class PaymentConsistencyAccessService
{
    public function __construct(
        private readonly PromotionAccessService $promotionAccess,
        private readonly AccountingContextService $accountingContext,
    ) {}

    public function assertCanRunPromotion(User $actor, MembershipPromotion $promotion): int
    {
        $companyId = $this->promotionAccess->assertOwns($actor, $promotion);
        if (! $actor->isActive() || ! $actor->can('payments.consistency.run')) {
            throw new AuthorizationException(__('You are not authorized to run payment consistency checks.'));
        }

        return $companyId;
    }

    public function assertCanView(User $actor, int $companyId, ?int $branchId): void
    {
        $defaultCompanyId = (int) ($this->accountingContext->defaultCompanyId() ?? 0);
        if (! $actor->isActive() || $actor->isCustomerPortalUser()
            || (! $actor->isAdmin() && ! $actor->can('payments.support.view'))
            || $companyId <= 0 || $companyId !== $defaultCompanyId
            || (! $actor->isAdmin() && ($branchId === null || ! in_array($branchId, $actor->allowedBranchIds(), true)))) {
            throw new AuthorizationException(__('You are not authorized to view payment consistency checks.'));
        }
    }

    public function assertCanRunFinding(User $actor, PaymentConsistencyFinding $finding): void
    {
        $this->assertCanView($actor, (int) $finding->company_id, $finding->branch_id ? (int) $finding->branch_id : null);
        if (! $actor->isAdmin() || ! $actor->can('payments.consistency.run')) {
            throw new AuthorizationException(__('Only an authorized administrator can run a manual consistency check.'));
        }
    }
}
