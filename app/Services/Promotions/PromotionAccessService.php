<?php

namespace App\Services\Promotions;

use App\Models\AccountingCompany;
use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Auth\Access\AuthorizationException;

class PromotionAccessService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
    ) {}

    public function companyIdFor(User $actor): int
    {
        if (! $actor->isActive() || $actor->isCustomerPortalUser() || ! $actor->isAdmin() || ! $actor->can('promotions.manage')) {
            throw new AuthorizationException(__('You are not authorized to manage membership promotions.'));
        }

        $companyId = $this->accountingContext->defaultCompanyId();
        if (! $companyId || ! AccountingCompany::query()->whereKey($companyId)->where('is_active', true)->exists()) {
            throw new AuthorizationException(__('An active default accounting company is required.'));
        }

        return $companyId;
    }

    public function assertOwns(User $actor, MembershipPromotion $promotion): int
    {
        $companyId = $this->companyIdFor($actor);
        if ((int) $promotion->company_id !== $companyId) {
            throw new AuthorizationException(__('This promotion is outside your company.'));
        }

        return $companyId;
    }
}
