<?php

namespace App\Services\Promotions;

use App\Models\MembershipPromotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromotionUsageProjectionService
{
    /** @return array{reserved:int,paid_uses:int,free_request_uses:int,completed:int,remaining:int} */
    public function forPromotion(MembershipPromotion $promotion): array
    {
        $reserved = Schema::hasTable('membership_promotion_reservations')
            ? DB::table('membership_promotion_reservations')
                ->where('promotion_id', $promotion->id)
                ->where('status', 'held')
                ->count()
            : 0;
        $paidUses = 0;
        $freeRequestUses = 0;
        if (Schema::hasTable('membership_promotion_redemptions')) {
            $paidUses = DB::table('membership_promotion_redemptions')
                ->where('promotion_id', $promotion->id)
                ->where('kind', 'paid_purchase')
                ->count();
            $freeRequestUses = DB::table('membership_promotion_redemptions')
                ->where('promotion_id', $promotion->id)
                ->where('kind', 'zero_request')
                ->count();
        }

        $completed = $paidUses + $freeRequestUses;

        return [
            'reserved' => $reserved,
            'paid_uses' => $paidUses,
            'free_request_uses' => $freeRequestUses,
            'completed' => $completed,
            'remaining' => max(0, (int) $promotion->total_limit - $completed - $reserved),
        ];
    }
}
