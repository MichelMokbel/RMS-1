<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an expense with computed total', function () {
    $user = User::factory()->create();
    $cat = ExpenseCategory::factory()->create();

    $this->actingAs($user);

    $response = $this->postJson(route('api.expenses.store'), [
        'category_id' => $cat->id,
        'expense_date' => now()->toDateString(),
        'description' => 'Test',
        'amount' => 100,
        'tax_amount' => 10,
        'payment_status' => 'paid',
        'payment_method' => 'cash',
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('expenses', ['description' => 'Test', 'total_amount' => 110]);
});

it('prevents delete when payments exist', function () {
    $user = User::factory()->create();
    $expense = Expense::factory()->create();
    $expense->payments()->create([
        'payment_date' => now()->toDateString(),
        'amount' => 5,
        'payment_method' => 'cash',
    ]);

    $this->actingAs($user);
    $response = $this->deleteJson(route('api.expenses.destroy', $expense));
    $response->assertStatus(422);
});

it('computes payment status unpaid/partial/paid', function () {
    $user = User::factory()->create();
    $expense = Expense::factory()->create([
        'amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 100,
        'payment_status' => 'unpaid',
    ]);

    $this->actingAs($user);
    $this->postJson(route('api.expenses.payments.store', $expense), [
        'payment_date' => now()->toDateString(),
        'amount' => 40,
        'payment_method' => 'cash',
    ])->assertCreated();

    $expense->refresh();
    expect($expense->payment_status)->toBe('partial');

    $this->postJson(route('api.expenses.payments.store', $expense), [
        'payment_date' => now()->toDateString(),
        'amount' => 60,
        'payment_method' => 'cash',
    ])->assertCreated();

    $expense->refresh();
    expect($expense->payment_status)->toBe('paid');
});
