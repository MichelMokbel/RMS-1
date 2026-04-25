<?php

use App\Contracts\PhoneVerificationProvider;
use App\Models\Customer;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use App\Notifications\CustomerPortalResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhoneVerificationProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('customer', 'web');

    $this->sms = new FakePhoneVerificationProvider();
    app()->instance(PhoneVerificationProvider::class, $this->sms);
});

it('starts customer registration without linking to an existing customer and sends an sms verification code', function () {
    Customer::factory()->create([
        'name' => 'Existing Customer',
        'email' => 'portal@example.com',
        'phone' => '55123456',
        'phone_e164' => '+97455123456',
    ]);

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
    $challenge = CustomerPhoneVerificationChallenge::query()->firstOrFail();

    expect($user->hasRole('customer'))->toBeTrue();
    expect($user->customer_id)->toBeNull();
    expect($user->portal_name)->toBe('Portal Customer');
    expect($user->portal_phone_e164)->toBe('+97455123456');
    expect($user->portal_delivery_address)->toBe('West Bay');
    expect($challenge->customer_id)->toBeNull();
    expect($challenge->purpose)->toBe('signup');
    expect((int) $challenge->send_count)->toBe(1);
    expect($this->sms->messages)->toHaveCount(1);
});

it('verifies signup otp, issues a customer api token, and keeps the account unlinked', function () {
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
                'customer' => ['id', 'phone_verified_at', 'data_source'],
                'linked_customer',
                'link_status',
            ],
        ])
        ->assertJson([
            'account' => [
                'linked_customer' => false,
                'link_status' => 'unlinked',
                'customer' => [
                    'id' => null,
                    'data_source' => 'portal',
                ],
            ],
        ]);

    $user = User::query()->where('email', 'portal@example.com')->firstOrFail();
    expect($user->customer_id)->toBeNull();
    expect($user->portal_phone_verified_at)->not->toBeNull();
});

it('rejects registration when the email is already used by a staff account', function () {
    Role::findOrCreate('admin', 'web');

    $user = User::factory()->create([
        'email' => 'existing@example.com',
    ]);
    $user->assignRole('admin');

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Existing Customer',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'phone' => '55667788',
        'address' => 'The Pearl',
    ])->assertStatus(409);
});

it('rejects registration when a customer portal account already exists for the email', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'customer_id' => null,
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

it('can bypass signup verification, issue a token immediately, and keep the account unlinked', function () {
    Config::set('customers.verification_bypass', true);

    $response = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Portal Customer',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'token',
            'account' => [
                'user' => ['id', 'name', 'email'],
                'customer' => ['id', 'phone_verified_at', 'data_source'],
                'linked_customer',
                'link_status',
            ],
        ])
        ->assertJson([
            'verification_bypassed' => true,
            'account' => [
                'linked_customer' => false,
                'link_status' => 'unlinked',
            ],
        ]);

    $user = User::query()->where('email', 'portal@example.com')->firstOrFail();

    expect($user->customer_id)->toBeNull();
    expect($user->portal_phone_verified_at)->not->toBeNull();
    expect(CustomerPhoneVerificationChallenge::query()->count())->toBe(0);
    expect($this->sms->messages)->toHaveCount(0);
});

it('rejects verify and resend while verification bypass is enabled', function () {
    Config::set('customers.verification_bypass', true);

    $this->postJson('/api/customer/auth/register/verify', [
        'registration_token' => 'unused',
        'code' => '123456',
    ])->assertStatus(409)->assertJson([
        'code' => 'PHONE_VERIFICATION_BYPASSED',
    ]);

    $this->postJson('/api/customer/auth/register/resend', [
        'registration_token' => 'unused',
    ])->assertStatus(409)->assertJson([
        'code' => 'PHONE_VERIFICATION_BYPASSED',
    ]);
});

it('sends a customer portal reset password notification for customer accounts only', function () {
    Notification::fake();

    $customerUser = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => null,
    ]);
    $customerUser->assignRole('customer');

    $staffUser = User::factory()->create([
        'email' => 'staff@example.com',
    ]);

    $this->postJson('/api/customer/auth/forgot-password', [
        'email' => 'portal@example.com',
    ])->assertOk();

    $this->postJson('/api/customer/auth/forgot-password', [
        'email' => 'staff@example.com',
    ])->assertOk();

    Notification::assertSentTo($customerUser, CustomerPortalResetPassword::class);
    Notification::assertNotSentTo($staffUser, CustomerPortalResetPassword::class);
});

it('resets password for a customer portal user via the customer api endpoint', function () {
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'password' => Hash::make('old-password'),
        'customer_id' => null,
    ]);
    $user->assignRole('customer');

    $token = Password::broker()->createToken($user);

    $this->postJson('/api/customer/auth/reset-password', [
        'token' => $token,
        'email' => 'portal@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});
