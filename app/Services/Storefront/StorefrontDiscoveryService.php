<?php

namespace App\Services\Storefront;

use App\Models\StorefrontSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StorefrontDiscoveryService
{
    public function __construct(
        private readonly StorefrontCatalogService $catalog,
    ) {}

    /** @return array{source:string,label:string,items:array<int,array<string,mixed>>}|null */
    public function featured(int $companyId, int $branchId, StorefrontSetting $settings): ?array
    {
        $eligible = $this->catalog->eligibleQuery($companyId, $branchId)->get()->keyBy('menu_item_id');
        if ($eligible->isEmpty()) {
            return null;
        }

        $today = CarbonImmutable::now(StorefrontSetting::TIMEZONE)->startOfDay();
        $fromUtc = $today->subDays(7)->utc();
        $untilUtc = $today->utc();
        $base = fn () => DB::table('payment_checkout_targets as targets')
            ->join('payment_checkout_attempts as attempts', 'attempts.id', '=', 'targets.attempt_id')
            ->join('payment_provider_transactions as provider', function ($join): void {
                $join->on('provider.attempt_id', '=', 'attempts.id')->whereNotNull('provider.verified_paid_at');
            })
            ->join('orders', 'orders.id', '=', 'targets.order_id')
            ->join('ar_invoices as invoices', 'invoices.id', '=', 'targets.invoice_id')
            ->where('attempts.company_id', $companyId)
            ->where('attempts.branch_id', $branchId)
            ->where('attempts.purpose', 'menu_order')
            ->where('attempts.state', 'completed')
            ->where('targets.target_type', 'order')
            ->where('targets.hold_state', 'activated')
            ->whereNotIn('orders.status', ['Cancelled', 'cancelled'])
            ->whereNull('invoices.voided_at')
            ->whereNotIn('invoices.status', ['void', 'voided'])
            ->where('provider.verified_finished_at', '>=', $fromUtc)
            ->where('provider.verified_finished_at', '<', $untilUtc);

        $qualifyingOrderCount = (int) $base()->distinct()->count('targets.order_id');
        $rows = $base()
            ->join('payment_checkout_target_items as target_items', 'target_items.target_id', '=', 'targets.id')
            ->whereIn('target_items.menu_item_id', $eligible->keys())
            ->groupBy('target_items.menu_item_id')
            ->selectRaw('target_items.menu_item_id, COUNT(DISTINCT targets.order_id) as order_count, SUM(target_items.quantity) as total_quantity')
            ->get();

        if ($qualifyingOrderCount >= 3 && $rows->isNotEmpty()) {
            $ranked = $rows->sort(function ($left, $right) use ($eligible): int {
                return [
                    -(int) $left->order_count,
                    -(float) $left->total_quantity,
                    (int) $eligible->get($left->menu_item_id)->display_order,
                    (int) $left->menu_item_id,
                ] <=> [
                    -(int) $right->order_count,
                    -(float) $right->total_quantity,
                    (int) $eligible->get($right->menu_item_id)->display_order,
                    (int) $right->menu_item_id,
                ];
            })->take(4)->map(fn ($row): array => $this->catalog->present($eligible->get($row->menu_item_id), $settings))->values()->all();
            if ($ranked !== []) {
                return ['source' => 'popular', 'label' => __('Popular this week'), 'items' => $ranked];
            }
        }

        $chefPicks = $eligible->filter(fn ($profile): bool => (bool) $profile->is_chef_pick)
            ->sortBy(fn ($profile): array => [(int) $profile->display_order, (int) $profile->menu_item_id])
            ->take(4)
            ->map(fn ($profile): array => $this->catalog->present($profile, $settings))
            ->values()
            ->all();

        return $chefPicks === [] ? null : ['source' => 'chef_picks', 'label' => __('Chef picks'), 'items' => $chefPicks];
    }
}
