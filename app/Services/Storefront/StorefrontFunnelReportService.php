<?php

namespace App\Services\Storefront;

use App\Models\PaymentCheckoutAttempt;
use App\Models\StorefrontEvent;
use App\Models\StorefrontSetting;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StorefrontFunnelReportService
{
    /** @return array{from:string,to:string,browser_directional:array<string, int>,checkout_canonical:array<string, int>,plan_experiment:array<string, array<string, int>>} */
    public function report(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $today = CarbonImmutable::now(StorefrontSetting::TIMEZONE)->startOfDay();
        $toDate = $to ? $this->date($to) : $today->subDay();
        $fromDate = $from ? $this->date($from) : $toDate->subDays(29);
        if ($fromDate->greaterThan($toDate)) {
            throw ValidationException::withMessages(['funnel_from' => __('The start date must be on or before the end date.')]);
        }

        $fromUtc = $fromDate->startOfDay()->utc();
        $toUtcExclusive = $toDate->addDay()->startOfDay()->utc();
        $events = StorefrontEvent::query()
            ->where('company_id', $companyId)
            ->where('received_at', '>=', $fromUtc)
            ->where('received_at', '<', $toUtcExclusive);
        $attempts = PaymentCheckoutAttempt::query()
            ->where('company_id', $companyId)
            ->where('purpose', 'menu_order')
            ->where('started_at', '>=', $fromUtc)
            ->where('started_at', '<', $toUtcExclusive);
        $upsellCompletions = PaymentCheckoutAttempt::query()
            ->where('company_id', $companyId)
            ->whereIn('purpose', ['ordinary_order', 'menu_order', 'membership', 'membership_booking'])
            ->where('state', 'completed')
            ->where('started_at', '>=', $fromUtc)
            ->where('started_at', '<', $toUtcExclusive)
            ->get(['id', 'pricing_snapshot']);
        $paidUpsells = $upsellCompletions->filter(
            fn (PaymentCheckoutAttempt $attempt): bool => (int) data_get($attempt->pricing_snapshot, 'add_on_amount_cents', 0) > 0,
        );
        $membershipAttempts = PaymentCheckoutAttempt::query()
            ->where('company_id', $companyId)
            ->where('purpose', 'membership')
            ->where('started_at', '>=', $fromUtc)
            ->where('started_at', '<', $toUtcExclusive)
            ->get(['state', 'payable_amount_cents', 'pricing_snapshot']);
        $planExperiment = [];
        foreach (['1', '2', '3'] as $variant) {
            $variantEvents = (clone $events)->where('experiment_variant', $variant);
            $variantAttempts = $membershipAttempts->filter(
                fn (PaymentCheckoutAttempt $attempt): bool => (string) data_get($attempt->pricing_snapshot, 'plan_selector_variant', '') === $variant,
            );
            $paidAttempts = $variantAttempts->where('state', 'completed');
            $planExperiment[$variant] = [
                'selector_views' => (clone $variantEvents)->where('event_name', 'plan_selector_viewed')->distinct()->count('journey_hash'),
                'plan_selections' => (clone $variantEvents)->where('event_name', 'plan_selected')->distinct()->count('journey_hash'),
                'flexible_selections' => (clone $variantEvents)->where('event_name', 'plan_selected')->where('plan_code', 'flexible')->distinct()->count('journey_hash'),
                'plan_20_selections' => (clone $variantEvents)->where('event_name', 'plan_selected')->where('plan_code', '20')->distinct()->count('journey_hash'),
                'plan_26_selections' => (clone $variantEvents)->where('event_name', 'plan_selected')->where('plan_code', '26')->distinct()->count('journey_hash'),
                'checkout_starts' => $variantAttempts->count(),
                'paid_completions' => $paidAttempts->count(),
                'paid_amount_cents' => (int) $paidAttempts->sum('payable_amount_cents'),
            ];
        }

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'browser_directional' => [
                'order_home_views' => (clone $events)->where('event_name', 'storefront_viewed')->where('path_code', 'order_home')->distinct()->count('journey_hash'),
                'path_choices' => (clone $events)->where('event_name', 'path_selected')->distinct()->count('journey_hash'),
                'item_views' => (clone $events)->where('event_name', 'item_viewed')->distinct()->count('journey_hash'),
                'item_adds' => (clone $events)->where('event_name', 'item_added')->distinct()->count('journey_hash'),
                'cart_views' => (clone $events)->where('event_name', 'cart_viewed')->distinct()->count('journey_hash'),
                'delivery_app_exits' => (clone $events)->where('event_name', 'delivery_app_opened')->distinct()->count('journey_hash'),
                'upsell_views' => (clone $events)->where('event_name', 'upsell_viewed')->distinct()->count('journey_hash'),
                'upsell_skips' => (clone $events)->where('event_name', 'upsell_skipped')->distinct()->count('journey_hash'),
                'upsell_item_adds' => (clone $events)->where('event_name', 'upsell_item_added')->distinct()->count('journey_hash'),
            ],
            'checkout_canonical' => [
                'checkout_starts' => (clone $attempts)->count(),
                'declines' => (clone $attempts)->where('state', 'declined')->count(),
                'paid_processing' => (clone $attempts)->where('state', 'paid_processing')->count(),
                'paid_completions' => (clone $attempts)->where('state', 'completed')->count(),
                'paid_upsell_checkouts' => $paidUpsells->count(),
                'paid_upsell_amount_cents' => (int) $paidUpsells->sum(
                    fn (PaymentCheckoutAttempt $attempt): int => (int) data_get($attempt->pricing_snapshot, 'add_on_amount_cents', 0),
                ),
            ],
            'plan_experiment' => $planExperiment,
        ];
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), StorefrontSetting::TIMEZONE);
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->toDateString() !== trim($value)) {
            throw ValidationException::withMessages(['funnel_from' => __('Choose valid report dates.')]);
        }

        return $date;
    }
}
