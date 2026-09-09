<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\StorefrontClosedDate;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Services\Storefront\StorefrontAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves the order day at exactly the Qatar cutoff and skips consecutive closed dates', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $settings = StorefrontSetting::query()->create([
        'company_id' => $company->id,
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => true,
        'menu_cutoff_time' => '23:00:00',
        'timezone' => 'Asia/Qatar',
    ]);
    $item = MenuItem::factory()->create();
    $profile = StorefrontItemProfile::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'menu_item_id' => $item->id,
        'advance_days' => 2,
        'minimum_quantity' => '1',
        'quantity_increment' => '1',
    ]);
    $availability = app(StorefrontAvailabilityService::class);

    expect($availability->earliestDate(
        $settings,
        $profile,
        CarbonImmutable::parse('2026-09-09 22:59:00', 'Asia/Qatar'),
    ))->toBe('2026-09-11')
        ->and($availability->earliestDate(
            $settings,
            $profile,
            CarbonImmutable::parse('2026-09-09 23:00:00', 'Asia/Qatar'),
        ))->toBe('2026-09-12');

    foreach (['2026-09-12', '2026-09-13'] as $date) {
        StorefrontClosedDate::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'service_date' => $date,
        ]);
    }
    expect($availability->earliestDate(
        $settings,
        $profile,
        CarbonImmutable::parse('2026-09-09 23:00:00', 'Asia/Qatar'),
    ))->toBe('2026-09-14');
});
