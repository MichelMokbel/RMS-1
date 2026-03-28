<?php

use App\Contracts\PhoneVerificationProvider;
use App\Models\Customer;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhoneVerificationProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('customer', 'web');

    $this->sms = new FakePhoneVerificationProvider();
    app()->instance(PhoneVerificationProvider::class, $this->sms);
});

it('starts customer registration and sends an sms verification code', function () {
    $response = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Portal Customer',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'registration_token',
            'phone' => ['e164', 'masked'],
        ]);

    $user = User::query()->where('email', 'portal@example.com')->firstOrFail();
    $customer = Customer::query()->whereKey($user->customer_id)->firstOrFail();
    $challenge = CustomerPhoneVerificationChallenge::query()->firstOrFail();

    expect($user->hasRole('customer'))->toBeTrue();
    expect($customer->phone_e164)->toBe('+97455123456');
    expect($challenge->purpose)->toBe('signup');
    expect((int) $challenge->send_count)->toBe(1);
    expect($this->sms->messages)->toHaveCount(1);
});

it('verifies signup otp and issues a customer api token', function () {
    $start = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Portal Customer',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ])->assertCreated();

    $verify = $this->postJson('/api/customer/auth/register/verify', [
        'registration_token' => $start->json('registration_token'),
        'code' => $this->sms->latestCode(),
    ]);

    $verify->assertOk()
        ->assertJsonStructure([
            'token',
            'account' => [
                'user' => ['id', 'name', 'email'],
                'customer' => ['id', 'phone_verified_at'],
            ],
        ]);

    expect(User::query()->where('email', 'portal@example.com')->firstOrFail()->customer->phone_verified_at)->not->toBeNull();
});

it('links registration to an existing customer matched by exact email', function () {
    $customer = Customer::factory()->create([
        'name' => 'Existing Customer',
        'email' => 'existing@example.com',
        'phone' => '55667788',
        'phone_e164' => '+97455667788',
        'phone_verified_at' => null,
    ]);

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Existing Customer',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'phone' => '55667788',
        'address' => 'The Pearl',
    ])->assertCreated();

    $user = User::query()->where('email', 'existing@example.com')->firstOrFail();

    expect((int) $user->customer_id)->toBe($customer->id);
});

it('rejects registration when the matched customer is already linked to another user', function () {
    $customer = Customer::factory()->create([
        'email' => 'existing@example.com',
        'phone' => '55667788',
        'phone_e164' => '+97455667788',
    ]);

    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Existing Customer',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'phone' => '55667788',
        'address' => 'The Pearl',
    ])->assertStatus(409);
});

it('enforces resend cooldown for signup verification codes', function () {
    $start = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Portal Customer',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ])->assertCreated();

    $this->postJson('/api/customer/auth/register/resend', [
        'registration_token' => $start->json('registration_token'),
    ])->assertStatus(422);
});
