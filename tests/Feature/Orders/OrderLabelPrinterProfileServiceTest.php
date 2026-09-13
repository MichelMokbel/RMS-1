<?php

use App\Models\AccountingCompany;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Orders\OrderLabelPrinterProfileService;
use App\Services\Orders\OrderLabelPrinterTestService;
use App\Services\POS\PosPrintJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Permission::findOrCreate('order-label-printers.manage', 'web');
    Role::findByName('admin', 'web')->givePermissionTo('order-label-printers.manage');
});

function profileFixture(): array
{
    $company = AccountingCompany::query()->create([
        'name' => 'Layla Kitchen',
        'code' => 'LK-PROFILE',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);
    DB::table('branches')->where('id', 1)->update(['company_id' => $company->id]);
    $terminal = PosTerminal::query()->create([
        'branch_id' => 1,
        'code' => 'T91',
        'name' => 'Printer host',
        'device_id' => 'PROFILE-AGENT-TEST',
        'active' => true,
    ]);
    $actor = User::factory()->create(['status' => 'active']);
    $actor->assignRole('admin');

    return compact('company', 'terminal', 'actor');
}

function profileData(PosTerminal $terminal): array
{
    return [
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'code' => 'BROTHER_1',
        'name' => 'Brother labels',
        'department' => 'packing',
        'model_code' => 'QL-820NWB',
        'os_queue_name' => 'Brother_QL_820NWB',
        'connection_description' => 'Ethernet',
        'resolution_dpi' => 300,
        'media_mode' => 'fixed',
        'width_tenths_mm' => 580,
        'height_tenths_mm' => 620,
        'min_height_tenths_mm' => null,
        'max_height_tenths_mm' => null,
        'default_copies' => 1,
    ];
}

it('requires an acknowledged physical test before activation', function () {
    $fixture = profileFixture();
    $service = app(OrderLabelPrinterProfileService::class);
    $profile = $service->save(null, profileData($fixture['terminal']), 0, $fixture['actor']);
    $preview = app(OrderLabelPrinterTestService::class)->preview($profile, $fixture['actor']);

    expect($profile->is_active)->toBeFalse()
        ->and($profile->is_verified)->toBeFalse()
        ->and($profile->revision)->toBe(1)
        ->and($preview)->toStartWith('%PDF-');
    expect(fn () => $service->setActive($profile, true, 1, $fixture['actor']))
        ->toThrow(ValidationException::class);

    $test = app(OrderLabelPrinterTestService::class)->send($profile, $fixture['actor']);
    $claimed = app(PosPrintJobService::class)->pull($fixture['terminal'], 0, 1)[0];
    app(PosPrintJobService::class)->ack($fixture['terminal'], $claimed->id, [
        'claim_token' => $claimed->claim_token,
        'status' => 'printed',
    ]);

    expect($test->fresh()->status)->toBe('printed');
    $verified = $service->verify($profile->fresh(), 1, $fixture['actor']);
    $active = $service->setActive($verified, true, 2, $fixture['actor']);

    expect($active->is_verified)->toBeTrue()
        ->and($active->is_active)->toBeTrue()
        ->and($active->revision)->toBe(3)
        ->and($active->last_tested_at)->not->toBeNull();
});

it('uses optimistic revisions and resets verification after printer settings change', function () {
    $fixture = profileFixture();
    $service = app(OrderLabelPrinterProfileService::class);
    $profile = $service->save(null, profileData($fixture['terminal']), 0, $fixture['actor']);

    expect(fn () => $service->save($profile, profileData($fixture['terminal']), 99, $fixture['actor']))
        ->toThrow(ValidationException::class);

    $profile->forceFill(['is_verified' => true, 'is_active' => true])->save();
    $data = profileData($fixture['terminal']);
    $data['width_tenths_mm'] = 500;
    $saved = $service->save($profile->fresh(), $data, 1, $fixture['actor']);

    expect($saved->is_verified)->toBeFalse()
        ->and($saved->is_active)->toBeFalse()
        ->and($saved->revision)->toBe(2);
});

it('rejects a terminal from another branch', function () {
    $fixture = profileFixture();
    DB::table('branches')->insert([
        'id' => 2,
        'company_id' => $fixture['company']->id,
        'name' => 'Branch 2',
        'code' => 'B2',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $other = PosTerminal::query()->create([
        'branch_id' => 2,
        'code' => 'T92',
        'name' => 'Other branch host',
        'active' => true,
    ]);
    $data = profileData($other);

    expect(fn () => app(OrderLabelPrinterProfileService::class)->save(null, $data, 0, $fixture['actor']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(OrderLabelPrinterProfile::query()->count())->toBe(0);
});

it('provisions a dedicated print device when a profile is created without a terminal', function () {
    $fixture = profileFixture();
    $data = profileData($fixture['terminal']);
    $data['terminal_id'] = null;

    $profile = app(OrderLabelPrinterProfileService::class)->save(null, $data, 0, $fixture['actor']);
    $terminal = $profile->terminal()->firstOrFail();

    expect($terminal->branch_id)->toBe(1)
        ->and($terminal->code)->toBe('T99')
        ->and($terminal->name)->toBe('Label agent: Brother labels')
        ->and($terminal->device_id)->not->toBeNull()
        ->and($terminal->active)->toBeTrue();
});
