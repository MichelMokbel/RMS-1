<?php

use App\Models\MealPlanRequest;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->actingAs(User::factory()->create(['status' => 'active'])->assignRole('admin'));
});

it('searches meal plan requests by customer contact details while retaining the status filter', function (string $term) {
    $matching = MealPlanRequest::create([
        'customer_name' => 'Search Customer',
        'customer_phone' => '55587654',
        'customer_email' => 'findme@example.com',
        'plan_meals' => 20,
        'status' => 'new',
    ]);
    MealPlanRequest::create([
        'customer_name' => 'Unrelated Customer',
        'customer_phone' => '11111111',
        'plan_meals' => 10,
        'status' => 'new',
    ]);
    MealPlanRequest::create([
        'customer_name' => 'Search Customer Closed',
        'customer_phone' => '55587654',
        'customer_email' => 'findme@example.com',
        'plan_meals' => 20,
        'status' => 'closed',
    ]);

    Volt::test('meal-plan-requests.index')
        ->set('status', 'new')
        ->set('search', ' '.$term.' ')
        ->assertViewHas('requests', fn ($requests) => $requests->pluck('id')->all() === [$matching->id]);
})->with(['Search Customer', '55587654', 'findme@example.com']);

it('resets pagination when searching and restores results when clearing the search', function () {
    foreach (range(1, 26) as $number) {
        MealPlanRequest::create([
            'customer_name' => 'Customer '.$number,
            'customer_phone' => '55500000',
            'plan_meals' => 20,
            'status' => 'new',
        ]);
    }

    Volt::test('meal-plan-requests.index')
        ->call('setPage', 2)
        ->set('search', 'missing-customer')
        ->assertSet('paginators.page', 1)
        ->assertViewHas('requests', fn ($requests) => $requests->total() === 0)
        ->set('search', '')
        ->assertViewHas('requests', fn ($requests) => $requests->total() === 26);
});
