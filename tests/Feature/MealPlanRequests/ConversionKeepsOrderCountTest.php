<?php

use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\Order;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedMealPlanBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

it('converting a meal plan request does not create extra orders', function () {
    seedMealPlanBranch(1);
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = MealPlanRequest::create([
        'customer_name' => 'Subscription Customer',
        'customer_phone' => '55512345',
        'customer_email' => 'sub@example.com',
        'delivery_address' => 'Doha',
        'notes' => 'convert test',
        'plan_meals' => 20,
        'status' => 'new',
    ]);
    $customer = Customer::factory()->create([
        'phone' => '55512345',
        'email' => 'sub@example.com',
    ]);

    $order1 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-10',
        'status' => 'Draft',
    ]);
    $order2 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-11',
        'status' => 'Draft',
    ]);
    $order3 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-12',
        'status' => 'Draft',
    ]);

    foreach ([$order1, $order2, $order3] as $order) {
        DB::table('meal_plan_request_orders')->insert([
            'meal_plan_request_id' => $request->id,
            'order_id' => $order->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $beforeIds = Order::query()->pluck('id')->sort()->values()->all();
    expect($beforeIds)->toHaveCount(3);

    Livewire::test('meal-plan-requests.index')
        ->call('openConvertModal', $request->id)
        ->set('convertCustomerId', $customer->id)
        ->set('convertCreateCustomer', false)
        ->set('convertConfirmCreateCustomer', false)
        ->set('convertAttachOrders', true)
        ->set('convertBranchId', 1)
        ->set('convertStartDate', '2026-03-10')
        ->call('convertToSubscription')
        ->assertHasNoErrors();

    $afterIds = Order::query()->pluck('id')->sort()->values()->all();
    expect($afterIds)->toBe($beforeIds);

    $subscription = MealSubscription::query()->where('meal_plan_request_id', $request->id)->first();
    expect($subscription)->not->toBeNull();

    $linkedCount = DB::table('meal_subscription_orders')
        ->where('subscription_id', $subscription->id)
        ->count();

    expect($linkedCount)->toBe(3);

    $sources = Order::query()->whereIn('id', $afterIds)->pluck('source')->unique()->sort()->values()->all();
    expect($sources)->toBe(['Subscription']);
});
