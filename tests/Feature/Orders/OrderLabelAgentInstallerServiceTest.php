<?php

use App\Models\AccountingCompany;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Orders\OrderLabelAgentInstallerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Permission::findOrCreate('order-label-printers.manage', 'web');
    Permission::findOrCreate('pos.login', 'web');
    Role::findByName('admin', 'web')->givePermissionTo('order-label-printers.manage');
});

function labelAgentFixture(): array
{
    $company = AccountingCompany::query()->create([
        'name' => 'Layla Kitchen',
        'code' => 'LK-AGENT',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);
    DB::table('branches')->where('id', 1)->update(['company_id' => $company->id]);
    $terminal = PosTerminal::query()->create([
        'branch_id' => 1,
        'code' => 'T99',
        'name' => 'Label agent: Packing labels',
        'device_id' => 'LABEL-DEVICE-99',
        'active' => true,
    ]);
    $profile = OrderLabelPrinterProfile::query()->create([
        'company_id' => $company->id,
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'code' => 'BROTHER_PACKING',
        'name' => 'Packing labels',
        'department' => 'packing',
        'model_code' => 'QL-820NWB',
        'os_queue_name' => 'Brother QL-820NWB',
        'connection_description' => 'USB · Windows',
        'resolution_dpi' => 300,
        'media_mode' => 'fixed',
        'width_tenths_mm' => 570,
        'height_tenths_mm' => 370,
        'default_copies' => 1,
        'is_verified' => false,
        'is_active' => false,
        'revision' => 1,
    ]);
    $actor = User::factory()->create(['status' => 'active']);
    $actor->assignRole('admin');

    return compact('company', 'terminal', 'profile', 'actor');
}

it('builds a configured Windows agent with a dedicated least privilege identity', function () {
    $fixture = labelAgentFixture();

    $package = app(OrderLabelAgentInstallerService::class)->build(
        $fixture['profile'],
        $fixture['actor'],
        'https://rms-dev.example.test'
    );

    $agent = User::query()->where('username', '__label_agent_t'.$fixture['terminal']->id)->firstOrFail();
    $token = $agent->tokens()->sole();
    preg_match('/\$agentText = .*FromBase64String\("([A-Za-z0-9+\/=]+)"\)/', $package['contents'], $agentMatch);
    preg_match('/\$configText = .*FromBase64String\("([A-Za-z0-9+\/=]+)"\)/', $package['contents'], $configMatch);
    $agentScript = base64_decode($agentMatch[1] ?? '', true);
    $config = json_decode((string) base64_decode($configMatch[1] ?? '', true), true, flags: JSON_THROW_ON_ERROR);

    expect($package['filename'])->toBe('Layla-Print-Agent-BROTHER_PACKING.ps1')
        ->and($package['contents'])->toContain('Layla Kitchen Print Agent is installed and running.')
        ->and($package['contents'])->not->toContain('__AGENT_SCRIPT_BASE64__')
        ->and($package['contents'])->not->toContain('__CONFIG_BASE64__')
        ->and($package['contents'])->not->toMatch('/__[A-Z0-9_]+__/')
        ->and($agentScript)->toContain('/api/pos/print-jobs/pull')
        ->and($agentScript)->not->toContain('UseBasicParsing')
        ->and($config['base_url'])->toBe('https://rms-dev.example.test')
        ->and($config['queue_name'])->toBe('Brother QL-820NWB')
        ->and($config['media_mode'])->toBe('fixed')
        ->and($config['width_tenths_mm'])->toBe(570)
        ->and($config['height_tenths_mm'])->toBe(370)
        ->and($agent->pos_enabled)->toBeTrue()
        ->and($agent->status)->toBe('active')
        ->and($agent->roles()->count())->toBe(0)
        ->and($agent->getDirectPermissions()->pluck('name')->all())->toBe(['pos.login'])
        ->and($agent->branches()->pluck('branches.id')->all())->toBe([1])
        ->and($token->abilities)->toBe(['pos.print', 'device:LABEL-DEVICE-99']);
});

it('rotates setup credentials only while the profile is inactive', function () {
    $fixture = labelAgentFixture();
    $service = app(OrderLabelAgentInstallerService::class);

    $service->build($fixture['profile'], $fixture['actor'], 'https://rms-dev.example.test');
    $service->build($fixture['profile'], $fixture['actor'], 'https://rms-dev.example.test');

    $agent = User::query()->where('username', '__label_agent_t'.$fixture['terminal']->id)->firstOrFail();
    expect($agent->tokens()->count())->toBe(1);

    $fixture['profile']->forceFill(['is_active' => true])->save();
    expect(fn () => $service->build($fixture['profile']->fresh(), $fixture['actor'], 'https://rms-dev.example.test'))
        ->toThrow(ValidationException::class);
});

it('allows print polling but denies non-print POS APIs to an agent token', function () {
    $fixture = labelAgentFixture();
    $agent = User::factory()->create(['status' => 'active', 'pos_enabled' => true]);
    $agent->givePermissionTo('pos.login');
    $agent->branches()->sync([1]);
    Sanctum::actingAs($agent, ['pos.print', 'device:'.$fixture['terminal']->device_id]);

    $this->withHeader('X-Device-Id', $fixture['terminal']->device_id)
        ->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=1')
        ->assertOk();

    $this->withHeader('X-Device-Id', $fixture['terminal']->device_id)
        ->getJson('/api/pos/bootstrap')
        ->assertForbidden()
        ->assertJsonPath('reason', 'ABILITY');
});
