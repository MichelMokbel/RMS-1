<?php

namespace App\Services\Payments;

use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Promotions\PromotionAccessService;
use Illuminate\Auth\Access\AuthorizationException;

class PaymentConsistencyAccessService
{
    public function __construct(
        private readonly PromotionAccessService $promotionAccess,
    ) {}

    public function assertCanRunPromotion(User $actor, MembershipPromotion $promotion): int
    {
        $companyId = $this->promotionAccess->assertOwns($actor, $promotion);
        if (! $actor->can('payments.consistency.run')) {
            throw new AuthorizationException(__('You are not authorized to run payment consistency checks.'));
        }

        return $companyId;
    }
}
