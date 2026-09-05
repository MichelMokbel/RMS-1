<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Reports\MealPlanRequestReportService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('manager');
    Role::findOrCreate('admin');
    $this->actor = User::factory()->create(['status' => 'active'])->assignRole('manager');
    $this->actingAs($this->actor);
    $this->customer = Customer::factory()->create(['name' => 'Selected Customer']);
    $this->branch = Branch::query()->firstOrFail();
    DB::table('user_branch_access')->insert(['user_id' => $this->actor->id, 'branch_id' => $this->branch->id]);
});

function bulkPlan(Customer $customer, array $attributes = []): MealPlanRequest
{
    return MealPlanRequest::create(array_merge([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_phone' => $customer->phone,
        'plan_meals' => 20,
        'status' => 'new',
    ], $attributes));
}

function bulkPlanOrder(MealPlanRequest $plan, int $branchId, string $date, string $amount, string $name): Order
{
    $order = Order::factory()->dailyDish()->create([
        'customer_id' => $plan->customer_id, 'branch_id' => $branchId,
        'scheduled_date' => $date, 'total_amount' => $amount,
    ]);
    $plan->orders()->attach($order);
    $item = MenuItem::factory()->create(['name' => $name]);
    OrderItem::create([
        'order_id' => $order->id, 'menu_item_id' => $item->id, 'description_snapshot' => $name, 'quantity' => 1,
        'unit_price' => $amount, 'discount_amount' => 0, 'line_total' => $amount,
        'status' => 'Pending', 'sort_order' => 1, 'role' => 'main',
    ]);

    return $order;
}

it('prints all orders from selected requests once with combined daily totals and excludes unselected plans', function () {
    $first = bulkPlan($this->customer);
    $second = bulkPlan($this->customer, ['status' => 'converted']);
    $unselected = bulkPlan($this->customer);
    $shared = bulkPlanOrder($first, $this->branch->id, '2026-09-10', '10.125', 'First selection meal');
    $second->orders()->attach($shared);
    bulkPlanOrder($second, $this->branch->id, '2026-09-10', '20.250', 'Second selection meal');
    bulkPlanOrder($second, $this->branch->id, '2026-09-11', '5.125', 'Later selection meal');
    bulkPlanOrder($unselected, $this->branch->id, '2026-09-10', '999.000', 'Unselected meal');

    $response = $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$first->id, $second->id],
    ])->assertOk()->assertSee('Selected Requests')->assertSee('Selected Customer')
        ->assertSee('First selection meal')->assertSee('Second selection meal')->assertSee('Later selection meal')
        ->assertDontSee('Unselected meal')->assertSee('Day Total: 30.375')->assertSee('Day Total: 5.125')
        ->assertSee('Total Amount of Cart: 35.500')->assertSee('10-Sep-2026')->assertSee('11-Sep-2026');
    expect(substr_count($response->getContent(), 'First selection meal'))->toBe(1);
    $response->assertViewHas('days', fn ($days) => $days->sum(fn ($day) => $day['orders']->count()) === 3);
});

it('rejects requests from a different customer even when their contact name matches', function () {
    $other = Customer::factory()->create(['name' => $this->customer->name]);
    $own = bulkPlan($this->customer);
    $foreign = bulkPlan($other);
    $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$own->id, $foreign->id],
    ])->assertForbidden();
});

it('hides and rejects the whole request if any linked order is outside the actors branches', function () {
    $plan = bulkPlan($this->customer);
    bulkPlanOrder($plan, $this->branch->id, '2026-09-10', '10.000', 'Allowed meal');
    $otherBranch = Branch::create(['name' => 'Other company branch', 'code' => 'OTHER-PRINT', 'is_active' => true]);
    bulkPlanOrder($plan, $otherBranch->id, '2026-09-11', '20.000', 'Restricted meal');
    Volt::test('meal-plan-requests.print-selection')->call('selectCustomer', $this->customer->id)
        ->assertViewHas('requests', fn ($requests) => $requests->total() === 0);
    $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$plan->id],
    ])->assertForbidden();
    $this->actor->assignRole('admin');
    $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$plan->id],
    ])->assertOk()->assertSee('Allowed meal')->assertSee('Restricted meal');
});

it('supports legacy requests through persisted customer associations without matching contact text', function () {
    $orderLinked = bulkPlan($this->customer, ['customer_id' => null]);
    $order = bulkPlanOrder($orderLinked, $this->branch->id, '2026-09-10', '10.000', 'Legacy meal');
    $order->update(['customer_id' => $this->customer->id]);
    $subscriptionLinked = bulkPlan($this->customer, ['customer_id' => null, 'status' => 'converted']);
    MealSubscription::factory()->create([
        'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id,
        'meal_plan_request_id' => $subscriptionLinked->id,
    ]);
    $unlinked = bulkPlan($this->customer, ['customer_id' => null]);
    $ids = app(MealPlanRequestReportService::class)->requestsForCustomer($this->actor, $this->customer->id)->pluck('id');
    expect($ids->all())->toContain($orderLinked->id, $subscriptionLinked->id)->not->toContain($unlinked->id);
});

it('preserves selections across pages and clears them when the customer changes', function () {
    foreach (range(1, 26) as $number) {
        bulkPlan($this->customer);
    }
    $other = Customer::factory()->create();
    bulkPlan($other);
    Volt::test('meal-plan-requests.print-selection')
        ->set('customerSearch', 'Selected Customer')
        ->assertSee('Selected Customer')
        ->call('selectCustomer', $this->customer->id)
        ->call('selectPage')->assertSet('selectedRequestIds', fn ($ids) => count($ids) === 25)
        ->call('setPage', 2)->call('selectPage')
        ->assertSet('selectedRequestIds', fn ($ids) => count($ids) === 26)
        ->call('selectCustomer', $other->id)
        ->assertSet('selectedRequestIds', [])->assertSet('paginators.page', 1)
        ->call('changeCustomer')->assertSet('customerId', null);
});

it('validates that at least one distinct existing request and a customer are supplied', function () {
    $plan = bulkPlan($this->customer);
    foreach ([[], [$plan->id, $plan->id], [999999999]] as $ids) {
        $this->postJson(route('meal-plan-requests.print-selected'), [
            'customer_id' => $this->customer->id, 'request_ids' => $ids,
        ])->assertUnprocessable();
    }
    $this->postJson(route('meal-plan-requests.print-selected'), ['request_ids' => [$plan->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
});

it('protects the selection page and combined print endpoint from unauthorized actors', function () {
    $plan = bulkPlan($this->customer);
    $this->actingAs(User::factory()->create(['status' => 'active']));
    $this->get(route('meal-plan-requests.print-selection'))->assertForbidden();
    $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$plan->id],
    ])->assertForbidden();
});

it('renders a useful report for selected requests that have no orders', function () {
    $plan = bulkPlan($this->customer);
    $this->post(route('meal-plan-requests.print-selected'), [
        'customer_id' => $this->customer->id, 'request_ids' => [$plan->id],
    ])->assertOk()->assertSee('No orders are attached to the selected requests.')
        ->assertSee('Total Amount of Cart: 0.000');
});
