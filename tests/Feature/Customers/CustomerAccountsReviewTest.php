<?php

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Order;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeCustomerAccountsReviewAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));

    return $admin;
}

function makeCustomerAccountsPortalUser(Customer $customer): User
{
    $user = User::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);
    $user->assignRole(Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']));

    return $user;
}

function makeCustomerMatchReview(User $user, Customer $customer, Customer $candidate): CustomerMatchReview
{
    return CustomerMatchReview::query()->create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'candidate_customer_id' => $candidate->id,
        'reason_codes' => ['name_variant'],
        'profile_fingerprint' => str_repeat('a', 64),
        'status' => CustomerMatchReview::STATUS_PENDING,
    ]);
}

it('lets an admin review a possible duplicate without blocking either customer', function () {
    $admin = makeCustomerAccountsReviewAdmin();
    $customer = Customer::factory()->create(['name' => 'Portal Customer']);
    $candidate = Customer::factory()->create(['name' => 'Portal Customer Variant']);
    $user = makeCustomerAccountsPortalUser($customer);
    $review = makeCustomerMatchReview($user, $customer, $candidate);

    $this->actingAs($admin);

    Volt::test('customers.accounts')
        ->assertSee('Possible Duplicates')
        ->assertSee('Portal Customer Variant')
        ->set("reviewNotes.{$review->id}", 'Confirmed separate household')
        ->call('markReviewDifferent', $review->id)
        ->assertHasNoErrors();

    expect($review->fresh()->status)->toBe(CustomerMatchReview::STATUS_DIFFERENT)
        ->and($review->fresh()->decision_note)->toBe('Confirmed separate household')
        ->and($customer->fresh()->is_active)->toBeTrue()
        ->and($candidate->fresh()->is_active)->toBeTrue();
});

it('keeps customer account review restricted to administrators', function () {
    $customer = Customer::factory()->create();
    $portalUser = makeCustomerAccountsPortalUser($customer);

    $this->actingAs($portalUser)
        ->get(route('customers.accounts.index'))
        ->assertForbidden();
});

it('merges a reviewed source into the selected destination and keeps the destination login', function () {
    $admin = makeCustomerAccountsReviewAdmin();
    $source = Customer::factory()->create(['name' => 'Source Customer']);
    $destination = Customer::factory()->create(['name' => 'Destination Customer']);
    $sourceUser = makeCustomerAccountsPortalUser($source);
    $destinationUser = makeCustomerAccountsPortalUser($destination);
    $review = makeCustomerMatchReview($sourceUser, $source, $destination);

    $this->actingAs($admin);

    Volt::test('customers.accounts')
        ->call('mergeReview', $review->id)
        ->assertHasNoErrors();

    expect($review->fresh()->status)->toBe(CustomerMatchReview::STATUS_MERGED)
        ->and($review->fresh()->merge_audit_id)->not->toBeNull()
        ->and($source->fresh()->merged_into_customer_id)->toBe($destination->id)
        ->and($sourceUser->fresh()->status)->toBe('inactive')
        ->and($sourceUser->fresh()->customer_id)->toBeNull()
        ->and($destinationUser->fresh()->status)->toBe('active')
        ->and($destinationUser->fresh()->customer_id)->toBe($destination->id);
});

it('requires the merge flow when a linked account owns customer activity', function () {
    $admin = makeCustomerAccountsReviewAdmin();
    $customer = Customer::factory()->create();
    $portalUser = makeCustomerAccountsPortalUser($customer);
    Order::factory()->create([
        'customer_id' => $customer->id,
        'user_id' => $portalUser->id,
    ]);

    $this->actingAs($admin);

    Volt::test('customers.accounts')
        ->call('unlinkCustomer', $portalUser->id)
        ->assertHasErrors('account');

    expect($portalUser->fresh()->customer_id)->toBe($customer->id);
});

it('does not reactivate a login disabled by a customer merge', function () {
    $admin = makeCustomerAccountsReviewAdmin();
    $source = Customer::factory()->create();
    $destination = Customer::factory()->create();
    $sourceUser = makeCustomerAccountsPortalUser($source);
    makeCustomerAccountsPortalUser($destination);
    app(CustomerMergeService::class)->merge($source, $destination, $admin->id);

    $this->actingAs($admin);

    Volt::test('customers.accounts')
        ->call('toggleStatus', $sourceUser->id)
        ->assertHasErrors('account');

    expect($sourceUser->fresh()->status)->toBe('inactive')
        ->and($sourceUser->fresh()->customer_id)->toBeNull();
});
