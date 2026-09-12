<?php

use App\Contracts\PhoneVerificationProvider;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerDeliveryLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhoneVerificationProvider;

uses(RefreshDatabase::class);

function qatarDeliveryLocation(array $overrides = []): array
{
    return array_merge([
        'latitude' => 25.2854474,
        'longitude' => 51.5310399,
        'place_id' => 'test-place-id',
        'building' => 'Building 12',
        'unit' => 'Floor 3',
        'instructions' => 'Call on arrival',
    ], $overrides);
}

beforeEach(function (): void {
    Role::findOrCreate('customer', 'web');
    Config::set('customers.verification_bypass', true);
    Config::set('customers.matching_enabled', false);
    Config::set('customers.delivery_location_required', true);
    app()->instance(PhoneVerificationProvider::class, new FakePhoneVerificationProvider);
});

it('creates a customer with a confirmed Qatar pin and server generated address summary', function (): void {
    $response = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Map Customer',
        'email' => 'map@example.test',
        'password' => 'password123',
        'phone' => '55123456',
        'delivery_location' => qatarDeliveryLocation(),
    ])->assertCreated()
        ->assertJsonPath('account.customer.delivery_location.latitude', '25.285447')
        ->assertJsonPath('account.customer.delivery_location.longitude', '51.531040')
        ->assertJsonPath('account.customer.delivery_location.building', 'Building 12');

    $user = User::query()->where('email', 'map@example.test')->firstOrFail();
    $customer = $user->customer()->firstOrFail();

    expect($response->json('account.customer.delivery_address'))
        ->toBe('Building 12, Floor 3, Call on arrival, https://www.google.com/maps?q=25.285447,51.531040')
        ->and($user->portal_delivery_latitude)->toBe('25.285447')
        ->and($user->portal_delivery_longitude)->toBe('51.531040')
        ->and($customer->delivery_latitude)->toBe('25.285447')
        ->and($customer->delivery_longitude)->toBe('51.531040')
        ->and($customer->delivery_place_id)->toBe('test-place-id');
});

it('rejects missing, partial, and outside Qatar delivery locations without creating an account', function (): void {
    $base = [
        'name' => 'Invalid Map Customer',
        'email' => 'invalid-map@example.test',
        'password' => 'password123',
        'phone' => '55123456',
    ];

    $this->postJson('/api/customer/auth/register/start', $base)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_location');

    $this->postJson('/api/customer/auth/register/start', $base + [
        'delivery_location' => ['latitude' => 25.285447, 'building' => 'Building 12'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_location.longitude');

    $this->postJson('/api/customer/auth/register/start', $base + [
        'delivery_location' => qatarDeliveryLocation([
            'latitude' => 26.2235,
            'longitude' => 50.5876,
        ]),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_location.latitude');

    expect(User::query()->where('email', 'invalid-map@example.test')->exists())->toBeFalse();
});

it('copies a new signup location onto the unique exact customer match', function (): void {
    Config::set('customers.matching_enabled', true);
    $existing = Customer::factory()->create([
        'name' => 'Existing Map Customer',
        'phone_e164' => '+97455123456',
        'delivery_address' => 'Old address',
    ]);

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'existing map customer',
        'email' => 'exact-map@example.test',
        'password' => 'password123',
        'phone' => '55123456',
        'delivery_location' => qatarDeliveryLocation(['building' => 'Villa 44']),
    ])->assertCreated()
        ->assertJsonPath('account.customer.id', $existing->id);

    expect($existing->fresh()->delivery_building)->toBe('Villa 44')
        ->and($existing->fresh()->delivery_latitude)->toBe('25.285447');
});

it('lets an already started legacy verification complete after the pin requirement is enabled', function (): void {
    Config::set('customers.verification_bypass', false);
    Config::set('customers.delivery_location_required', false);
    $sms = new FakePhoneVerificationProvider;
    app()->instance(PhoneVerificationProvider::class, $sms);

    $start = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Legacy Pending Customer',
        'email' => 'legacy-pending@example.test',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'Legacy address',
    ])->assertCreated();

    Config::set('customers.delivery_location_required', true);

    $this->postJson('/api/customer/auth/register/verify', [
        'registration_token' => $start->json('registration_token'),
        'code' => $sms->latestCode(),
    ])->assertOk()
        ->assertJsonPath('account.customer.delivery_address', 'Legacy address')
        ->assertJsonPath('account.customer.delivery_location', null);
});

it('uses the pinned boundary for mainland, island, edge, and outside points', function (): void {
    $locations = app(CustomerDeliveryLocationService::class);

    expect($locations->contains(25.285447, 51.531040))->toBeTrue()
        ->and($locations->contains(25.3757924, 51.5403603))->toBeTrue()
        ->and($locations->contains(24.6394442, 51.3512114))->toBeTrue()
        ->and($locations->contains(26.2235, 50.5876))->toBeFalse()
        ->and($locations->contains(24.7136, 46.6753))->toBeFalse()
        ->and($locations->contains(24.4539, 54.3773))->toBeFalse();
});
