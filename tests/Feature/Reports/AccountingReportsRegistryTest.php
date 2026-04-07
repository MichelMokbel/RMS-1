<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

it('shows the accounting category on the reports index', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Accounting');
});

it('lists accounting report entries from the reports workspace', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.index', ['category' => 'accounting']))
        ->assertOk()
        ->assertSee('Trial Balance')
        ->assertSee('Profit & Loss')
        ->assertSee('Balance Sheet')
        ->assertSee('Cash Flow')
        ->assertSee('Bank Reconciliation Summary')
        ->assertSee('Budget Variance')
        ->assertSee('Job Profitability')
        ->assertSee('AP Aging')
        ->assertSee('AR Credit Balance Exceptions');
});

it('redirects the legacy accounting reports route to the reports accounting category', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('accounting.reports'))
        ->assertRedirect(route('reports.index', ['category' => 'accounting']));
});

it('renders the accounting trial balance report route', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.accounting-trial-balance'))
        ->assertOk()
        ->assertSee('Trial Balance');
});

it('renders the accounting ap aging report route', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.accounting-ap-aging'))
        ->assertOk()
        ->assertSee('AP Aging');
});

it('renders the accounting ar credit balance exceptions report route', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(route('reports.accounting-ar-credit-exceptions'))
        ->assertOk()
        ->assertSee('AR Credit Balance Exceptions');
});
