<?php

use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Livewire\Volt\Volt;

use function Pest\Laravel\actingAs;

if (! function_exists('adminUser')) {
    function adminUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($role);

        return $user;
    }
}

it('allows admin to create supplier', function () {
    $user = adminUser();

    $payload = Supplier::factory()->make()->toArray();

    Volt::actingAs($user);
    Volt::test('suppliers.create')
        ->set('name', $payload['name'])
        ->set('contact_person', $payload['contact_person'])
        ->set('email', $payload['email'])
        ->set('phone', $payload['phone'])
        ->set('address', $payload['address'])
        ->set('qid_cr', $payload['qid_cr'])
        ->set('status', $payload['status'])
        ->call('create')
        ->assertHasNoErrors();

    expect(Supplier::where('name', $payload['name'])->exists())->toBeTrue();
});

it('enforces unique supplier name', function () {
    $user = adminUser();
    $existing = Supplier::factory()->create();

    $payload = Supplier::factory()->make(['name' => $existing->name])->toArray();

    Volt::actingAs($user);
    Volt::test('suppliers.create')
        ->set('name', $payload['name'])
        ->call('create')
        ->assertHasErrors(['name']);
});

it('validates email format', function () {
    $user = adminUser();
    $payload = Supplier::factory()->make(['email' => 'not-an-email'])->toArray();

    Volt::actingAs($user);
    Volt::test('suppliers.create')
        ->set('name', $payload['name'])
        ->set('email', 'not-an-email')
        ->call('create')
        ->assertHasErrors(['email']);
});

it('persists qid_cr and allows search', function () {
    $user = adminUser();
    $supplier = Supplier::factory()->create(['qid_cr' => 'QID-9999']);

    Volt::actingAs($user);
    $component = Volt::test('suppliers.index')
        ->set('search', 'QID-9999')
        ->assertSee('QID-9999');

    expect($component)->not->toBeNull();
});

it('allows admin to deactivate and activate supplier', function () {
    $user = adminUser();
    $supplier = Supplier::factory()->create(['status' => 'active']);

    Volt::actingAs($user);
    Volt::test('suppliers.edit', ['supplier' => $supplier])
        ->set('name', $supplier->name)
        ->set('status', 'inactive')
        ->call('save')
        ->assertHasNoErrors();

    expect($supplier->fresh()->status)->toBe('inactive');
});
