<?php

namespace App\Services\Subscriptions;

use App\Models\MembershipPlan;
use Illuminate\Database\Eloquent\Collection;

class MembershipPlanCatalogService
{
    /** @return Collection<int, MembershipPlan> */
    public function activePlans(int $companyId): Collection
    {
        $now = now('UTC');

        return MembershipPlan::query()
            ->where('company_id', $companyId)
            ->where('currency', 'QAR')
            ->where('delivery_included', true)
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderBy('meal_count')
            ->get();
    }

    public function findActive(int $companyId, string $code): ?MembershipPlan
    {
        return $this->activePlans($companyId)
            ->first(fn (MembershipPlan $plan): bool => hash_equals((string) $plan->code, trim($code)));
    }

    /** @return array<string, mixed> */
    public function present(MembershipPlan $plan): array
    {
        return [
            'code' => (string) $plan->code,
            'meal_count' => (int) $plan->meal_count,
            'package_price_cents' => (int) $plan->package_price_cents,
            'currency' => (string) $plan->currency,
            'delivery_included' => (bool) $plan->delivery_included,
            'effective_version' => $plan->updated_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
