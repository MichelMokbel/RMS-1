<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContextService;
use App\Services\Subscriptions\MembershipPlanCatalogService;

class PublicMembershipPlanController extends Controller
{
    public function __invoke(
        AccountingContextService $accountingContext,
        MembershipPlanCatalogService $plans,
    ) {
        $companyId = $accountingContext->defaultCompanyId();
        if (! $companyId) {
            return response()->json(['message' => __('Membership pricing is unavailable.')], 503);
        }

        $active = $plans->activePlans($companyId);
        if ($active->isEmpty()) {
            return response()->json(['message' => __('No membership plans are available.')], 404);
        }

        return response()->json([
            'data' => $active->map(fn ($plan): array => $plans->present($plan))->values(),
        ]);
    }
}
