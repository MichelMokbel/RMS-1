<?php

namespace App\Services\Payments\Consistency;

use App\Models\MembershipPromotion;
use App\Services\Promotions\PromotionUsageConsistencyRule;

class PromotionConsistencyRule implements PaymentConsistencyRule
{
    public function __construct(
        private readonly PromotionUsageConsistencyRule $rule,
    ) {}

    public function ruleCodes(): array
    {
        return [PromotionUsageConsistencyRule::CODE];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $promotion = $this->promotion($ruleCode, $subjectType, $subjectId);

        return [
            'company_id' => (int) $promotion->company_id,
            'branch_id' => null,
            'checkout_id' => null,
        ];
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $result = $this->rule->evaluate($this->promotion($ruleCode, $subjectType, $subjectId));
        $result['deferred'] = false;

        return $result;
    }

    private function promotion(string $ruleCode, string $subjectType, int $subjectId): MembershipPromotion
    {
        if ($ruleCode !== PromotionUsageConsistencyRule::CODE || $subjectType !== PromotionUsageConsistencyRule::SUBJECT_TYPE) {
            throw new \InvalidArgumentException('Unsupported promotion consistency subject.');
        }

        return MembershipPromotion::query()->findOrFail($subjectId);
    }
}
