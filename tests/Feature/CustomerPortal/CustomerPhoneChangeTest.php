<?php

use App\Contracts\PhoneVerificationProvider;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhoneVerificationProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('customer', 'web');

    $this->sms = new FakePhoneVerificationProvider();
    app()->instance(PhoneVerificationProvider::class, $this->sms);
});

it('keeps the current phone active until a phone change otp is verified', function () {
    $customer = Customer::factory()->create([
        'phone' => '55123456',
        'phone_e164' => '+97455123456',
        'phone_verified_at' => now(),
    ]);

    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');

    Sanctum::actingAs($user, ['customer:*']);

    $start = $this->postJson('/api/customer/profile/phone/start-change', [
        'phone' => '55222333',
    ]);

    $start->assertOk()
        ->assertJsonStructure(['phone_change_token']);

    $customer->refresh();
    expect($customer->phone)->toBe('55123456');
    expect($customer->phone_e164)->toBe('+97455123456');

    $this->postJson('/api/customer/profile/phone/verify-change', [
        'phone_change_token' => $start->json('phone_change_token'),
        'code' => $this->sms->latestCode(),
    ])->assertOk();

    $customer->refresh();
    expect($customer->phone)->toBe('55222333');
    expect($customer->phone_e164)->toBe('+97455222333');
    expect($customer->phone_verified_at)->not->toBeNull();
});

it('disables phone changes while verification bypass is enabled', function () {
    Config::set('customers.verification_bypass', true);

    $customer = Customer::factory()->create([
        'phone' => '55123456',
        'phone_e164' => '+97455123456',
        'phone_verified_at' => now(),
    ]);

    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');

    Sanctum::actingAs($user, ['customer:*']);

    $this->postJson('/api/customer/profile/phone/start-change', [
        'phone' => '55222333',
    ])->assertStatus(409)->assertJson([
        'code' => 'PHONE_CHANGE_TEMPORARILY_DISABLED',
    ]);

    $this->postJson('/api/customer/profile/phone/verify-change', [
        'phone_change_token' => 'unused',
        'code' => '123456',
    ])->assertStatus(409)->assertJson([
        'code' => 'PHONE_CHANGE_TEMPORARILY_DISABLED',
    ]);
});
