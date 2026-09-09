<?php

namespace App\Services\Storefront;

use App\Models\StorefrontClosedDate;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use Carbon\CarbonImmutable;

class StorefrontAvailabilityService
{
    public function earliestDate(
        StorefrontSetting $settings,
        StorefrontItemProfile $profile,
        ?CarbonImmutable $now = null,
    ): string {
        $now ??= CarbonImmutable::now(StorefrontSetting::TIMEZONE);
        $now = $now->setTimezone(StorefrontSetting::TIMEZONE);
        $cutoff = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $now->toDateString().' '.(string) $settings->menu_cutoff_time,
            StorefrontSetting::TIMEZONE,
        );
        $orderDay = $now->greaterThanOrEqualTo($cutoff)
            ? $now->startOfDay()->addDay()
            : $now->startOfDay();
        $candidate = $orderDay->addDays(max(1, (int) $profile->advance_days));

        $closedDates = StorefrontClosedDate::query()
            ->where('company_id', $settings->company_id)
            ->where('branch_id', $settings->portal_branch_id)
            ->whereDate('service_date', '>=', $candidate->toDateString())
            ->orderBy('service_date')
            ->pluck('service_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();

        foreach ($closedDates as $closedDate) {
            if ($closedDate < $candidate->toDateString()) {
                continue;
            }
            if ($closedDate > $candidate->toDateString()) {
                break;
            }
            $candidate = $candidate->addDay();
        }

        return $candidate->toDateString();
    }

    public function isClosedDate(
        StorefrontSetting $settings,
        string $serviceDate,
        bool $lockForUpdate = false,
    ): bool {
        $query = StorefrontClosedDate::query()
            ->where('company_id', $settings->company_id)
            ->where('branch_id', $settings->portal_branch_id)
            ->where('service_date', $serviceDate);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }
}
