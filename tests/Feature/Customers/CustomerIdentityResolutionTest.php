<?php

use App\Contracts\PhoneVerificationProvider;
use App\Jobs\ScanCustomerMatchCandidates;
use App\Models\AccountingAuditLog;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use App\Services\Ai\AiProviderInterface;
use App\Services\Customers\CustomerMatchingRecoveryService;
use App\Services\Customers\CustomerMatchingService;
use App\Services\Customers\CustomerMatchReviewService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\FakePhoneVerificationProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('customer', 'web');
    Role::findOrCreate('admin', 'web');
    Config::set('customers.verification_bypass', true);
    Config::set('customers.matching_enabled', true);
    Config::set('customers.matching_ai_enabled', false);
    $this->sms = new FakePhoneVerificationProvider;
    app()->instance(PhoneVerificationProvider::class, $this->sms);
});

it('links the only active unoccupied customer with the exact normalized phone and full name', function (): void {
    Queue::fake([ScanCustomerMatchCandidates::class]);
    $existing = Customer::factory()->create([
        'name' => '  Layla   Customer ',
        'phone' => '55123456',
        'phone_e164' => '+97455123456',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'LAYLA customer',
        'email' => 'new@example.test',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ])->assertCreated();

    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    $audit = AccountingAuditLog::query()->where('action', 'customer.identity.resolved')->firstOrFail();
    expect($response->json('account.customer.id'))->toBe($existing->id)
        ->and($user->customer_id)->toBe($existing->id)
        ->and(Customer::query()->count())->toBe(1)
        ->and(CustomerMatchReview::query()->count())->toBe(0)
        ->and($audit->payload['resolution'])
        ->toBe('existing_exact_match');
    Queue::assertPushed(ScanCustomerMatchCandidates::class);
});

it('creates an owned customer and a private review for an inexact or occupied match', function (): void {
    Queue::fake([ScanCustomerMatchCandidates::class]);
    $candidate = Customer::factory()->create([
        'name' => 'Layla Customer',
        'phone_e164' => '+97455123456',
        'is_active' => true,
    ]);
    $occupied = User::factory()->create(['customer_id' => $candidate->id]);
    $occupied->assignRole('customer');

    $response = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Layla Customer',
        'email' => 'second@example.test',
        'password' => 'password123',
        'phone' => '55123456',
    ])->assertCreated();

    $user = User::query()->where('email', 'second@example.test')->firstOrFail();
    $review = CustomerMatchReview::query()->firstOrFail();
    expect($user->customer_id)->not->toBe($candidate->id)
        ->and($response->json('account.linked_customer'))->toBeTrue()
        ->and($review->user_id)->toBe($user->id)
        ->and($review->customer_id)->toBe($user->customer_id)
        ->and($review->candidate_customer_id)->toBe($candidate->id)
        ->and($review->reason_codes)->toContain('existing_login');
});

it('creates one owned customer when more than one exact historical customer exists', function (): void {
    Queue::fake([ScanCustomerMatchCandidates::class]);
    Customer::factory()->count(2)->create([
        'name' => 'Same Customer',
        'phone_e164' => '+97455123456',
        'is_active' => true,
    ]);

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Same Customer',
        'email' => 'same@example.test',
        'password' => 'password123',
        'phone' => '55123456',
    ])->assertCreated();

    $user = User::query()->where('email', 'same@example.test')->firstOrFail();
    expect(Customer::query()->count())->toBe(3)
        ->and(CustomerMatchReview::query()->where('customer_id', $user->customer_id)->count())->toBe(2);
    expect(CustomerMatchReview::query()->get()->every(
        fn (CustomerMatchReview $review): bool => in_array('multiple_exact_matches', $review->reason_codes, true),
    ))->toBeTrue();
});

it('resolves a legacy unlinked account during login without a staff linking gate', function (): void {
    Config::set('customers.matching_enabled', false);
    $user = User::factory()->create([
        'email' => 'legacy@example.test',
        'password' => Hash::make('password123'),
        'customer_id' => null,
        'portal_name' => 'Legacy Customer',
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'status' => 'active',
    ]);
    $user->assignRole('customer');

    $this->postJson('/api/customer/auth/login', [
        'email' => 'legacy@example.test',
        'password' => 'password123',
    ])->assertOk()
        ->assertJsonPath('account.linked_customer', true)
        ->assertJsonPath('account.phone_verification.method', 'bypass');

    expect($user->fresh()->customer_id)->not->toBeNull();
});

it('lets an authenticated bypass only account verify its current phone after bypass is disabled', function (): void {
    Config::set('customers.verification_bypass', false);
    Config::set('customers.matching_enabled', false);
    $user = User::factory()->create([
        'customer_id' => null,
        'portal_name' => 'Current Phone',
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'portal_phone_verified_at' => now()->subDay(),
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $start = $this->postJson('/api/customer/profile/phone/verify-current/start')
        ->assertOk()
        ->assertJsonStructure(['verification_token', 'phone' => ['masked']]);

    $this->postJson('/api/customer/profile/phone/verify-current/verify', [
        'verification_token' => $start->json('verification_token'),
        'code' => $this->sms->latestCode(),
    ])->assertOk()
        ->assertJsonPath('account.linked_customer', true)
        ->assertJsonPath('account.phone_verification.method', 'sms')
        ->assertJsonPath('account.phone_verification.satisfied', true);

    $challenge = CustomerPhoneVerificationChallenge::query()
        ->where('purpose', 'portal_phone_verify')
        ->firstOrFail();
    expect($challenge->user_id)->toBe($user->id)
        ->and($challenge->verified_at)->not->toBeNull()
        ->and($user->fresh()->customer_id)->not->toBeNull();
});

it('binds a current phone verification token to its authenticated owner', function (): void {
    Config::set('customers.verification_bypass', false);
    $first = User::factory()->create([
        'customer_id' => null,
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'status' => 'active',
    ]);
    $first->assignRole('customer');
    Sanctum::actingAs($first, ['customer:*']);
    $start = $this->postJson('/api/customer/profile/phone/verify-current/start')->assertOk();

    $second = User::factory()->create([
        'customer_id' => null,
        'portal_phone' => '55222333',
        'portal_phone_e164' => '+97455222333',
        'status' => 'active',
    ]);
    $second->assignRole('customer');
    Sanctum::actingAs($second, ['customer:*']);

    $this->postJson('/api/customer/profile/phone/verify-current/verify', [
        'verification_token' => $start->json('verification_token'),
        'code' => $this->sms->latestCode(),
    ])->assertUnprocessable();
    expect($first->fresh()->customer_id)->toBeNull()
        ->and($second->fresh()->customer_id)->toBeNull();
});

it('allows current phone verification to resend after the configured cooldown', function (): void {
    Config::set('customers.verification_bypass', false);
    $user = User::factory()->create([
        'customer_id' => null,
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);
    $start = $this->postJson('/api/customer/profile/phone/verify-current/start')->assertOk();

    $this->postJson('/api/customer/profile/phone/verify-current/resend', [
        'verification_token' => $start->json('verification_token'),
    ])->assertUnprocessable();

    $challenge = CustomerPhoneVerificationChallenge::query()->firstOrFail();
    DB::table('customer_phone_verification_challenges')->where('id', $challenge->id)->update([
        'last_sent_at' => now()->subSeconds(61),
    ]);
    $this->postJson('/api/customer/profile/phone/verify-current/resend', [
        'verification_token' => $start->json('verification_token'),
    ])->assertOk();

    expect((int) $challenge->fresh()->send_count)->toBe(2)
        ->and($this->sms->messages)->toHaveCount(2);
});

it('persists invalid verification attempts and cancels the challenge at the configured limit', function (): void {
    Config::set('customers.verification_bypass', false);
    Config::set('customers.matching_enabled', false);
    Config::set('customers.verification_max_attempts', 3);
    $user = User::factory()->create([
        'customer_id' => null,
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);
    $start = $this->postJson('/api/customer/profile/phone/verify-current/start')->assertOk();

    foreach ([1, 2, 3] as $attempt) {
        $this->postJson('/api/customer/profile/phone/verify-current/verify', [
            'verification_token' => $start->json('verification_token'),
            'code' => '000000',
        ])->assertUnprocessable();
        expect((int) CustomerPhoneVerificationChallenge::query()->firstOrFail()->attempt_count)->toBe($attempt);
    }

    $challenge = CustomerPhoneVerificationChallenge::query()->firstOrFail();
    expect($challenge->cancelled_at)->not->toBeNull();
    $this->postJson('/api/customer/profile/phone/verify-current/verify', [
        'verification_token' => $start->json('verification_token'),
        'code' => $this->sms->latestCode(),
    ])->assertUnprocessable();
    expect($challenge->fresh()->verified_at)->toBeNull();
});

it('keeps a completed bypass signup successful when optional matching dispatch fails', function (): void {
    $dispatcher = \Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Queue Independent Customer',
        'email' => 'queue-independent@example.test',
        'password' => 'password123',
        'phone' => '55123456',
    ])->assertCreated()
        ->assertJsonPath('account.linked_customer', true);

    $user = User::query()->where('email', 'queue-independent@example.test')->firstOrFail();
    expect($user->customer_id)->not->toBeNull()
        ->and(AccountingAuditLog::query()->where('action', 'customer.identity.resolved')->exists())->toBeTrue();
});

it('keeps uncertain local and ai matches advisory only', function (): void {
    Config::set('customers.matching_ai_enabled', true);
    $candidate = Customer::factory()->create([
        'name' => 'Mariam Customer',
        'phone_e164' => '+97455999999',
    ]);
    $customer = Customer::factory()->create([
        'name' => 'Maryam Customer',
        'phone_e164' => '+97455123456',
    ]);
    $user = User::factory()->create([
        'name' => 'Maryam Customer',
        'portal_name' => 'Maryam Customer',
        'portal_phone_e164' => '+97455123456',
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    $captured = null;
    app()->instance(AiProviderInterface::class, new class($captured) implements AiProviderInterface
    {
        public function __construct(public mixed &$captured) {}

        public function generateStructured(array $messages, array $schema): array
        {
            $this->captured = $messages;

            return ['suggestions' => [[
                'label' => 'candidate_1',
                'similarity' => 0.91,
                'reason' => 'spelling_variant',
            ]]];
        }
    });

    $matching = app(CustomerMatchingService::class);
    $fingerprint = $matching->profileFingerprint($user, $customer);
    $matching->scan($user->id, $fingerprint);
    $review = CustomerMatchReview::query()->where('candidate_customer_id', $candidate->id)->firstOrFail();

    expect($user->fresh()->customer_id)->toBe($customer->id)
        ->and($review->status)->toBe(CustomerMatchReview::STATUS_PENDING)
        ->and($review->fresh()->ai_suggestion['reason'])->toBe('spelling_variant')
        ->and(json_encode($captured))->not->toContain('+97455123456')
        ->and(json_encode($captured))->not->toContain((string) $customer->id)
        ->and(json_encode($captured))->not->toContain((string) $candidate->id);
});

it('lets only an active admin resolve a review and preserves phone proof during merge', function (): void {
    $source = Customer::factory()->create();
    $destination = Customer::factory()->create();
    $portal = User::factory()->create(['customer_id' => $source->id, 'status' => 'active']);
    $portal->assignRole('customer');
    $challenge = CustomerPhoneVerificationChallenge::query()->create([
        'user_id' => $portal->id,
        'customer_id' => $source->id,
        'purpose' => 'signup',
        'phone_e164' => '+97455123456',
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
        'verified_at' => now(),
    ]);
    $review = CustomerMatchReview::query()->create([
        'user_id' => $portal->id,
        'customer_id' => $source->id,
        'candidate_customer_id' => $destination->id,
        'reason_codes' => ['name_variant'],
        'profile_fingerprint' => str_repeat('a', 64),
        'status' => CustomerMatchReview::STATUS_PENDING,
    ]);
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $resolved = app(CustomerMatchReviewService::class)->merge($review->id, $admin, 'Confirmed duplicate');

    expect($resolved->status)->toBe(CustomerMatchReview::STATUS_MERGED)
        ->and($resolved->merge_audit_id)->not->toBeNull()
        ->and($source->fresh()->merged_into_customer_id)->toBe($destination->id)
        ->and($portal->fresh()->customer_id)->toBe($destination->id)
        ->and($challenge->fresh()->customer_id)->toBe($source->id);

    $nonAdmin = User::factory()->create(['status' => 'active']);
    expect(fn () => app(CustomerMatchReviewService::class)->markDifferent($review->id, $nonAdmin))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects portal mutations for an inactive source login', function (): void {
    $user = User::factory()->create(['status' => 'inactive', 'customer_id' => null]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $this->getJson('/api/customer/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'CUSTOMER_ACCOUNT_INACTIVE');
});

it('recovers only missing current matching generations and pauses cleanly', function (): void {
    Queue::fake([ScanCustomerMatchCandidates::class]);
    $customer = Customer::factory()->create();
    $user = User::factory()->create([
        'customer_id' => $customer->id,
        'portal_name' => 'Recovery Customer',
        'portal_phone_e164' => '+97455123456',
        'status' => 'active',
    ]);
    $user->assignRole('customer');
    $fingerprint = app(CustomerMatchingService::class)->profileFingerprint($user, $customer);
    AccountingAuditLog::query()->create([
        'actor_id' => $user->id,
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'action' => 'customer.identity.resolved',
        'payload' => ['profile_fingerprint' => $fingerprint],
        'created_at' => now(),
    ]);

    $first = app(CustomerMatchingRecoveryService::class)->dispatchMissing();
    expect($first)->toMatchArray(['examined' => 1, 'dispatched' => 1, 'disabled' => false]);
    Queue::assertPushed(ScanCustomerMatchCandidates::class, 1);

    AccountingAuditLog::query()->create([
        'actor_id' => $user->id,
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'action' => 'customer.matching.scan_completed',
        'payload' => ['profile_fingerprint' => $fingerprint],
        'created_at' => now(),
    ]);
    Queue::fake([ScanCustomerMatchCandidates::class]);
    expect(app(CustomerMatchingRecoveryService::class)->dispatchMissing())
        ->toMatchArray(['examined' => 0, 'dispatched' => 0, 'disabled' => false]);
    Queue::assertNothingPushed();

    Config::set('customers.matching_enabled', false);
    expect(app(CustomerMatchingRecoveryService::class)->dispatchMissing())
        ->toBe(['examined' => 0, 'dispatched' => 0, 'disabled' => true]);
});
