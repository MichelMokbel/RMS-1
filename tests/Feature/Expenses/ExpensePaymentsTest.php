<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a payment and updates status', function () {
    $user = User::factory()->create();
    $cat = ExpenseCategory::factory()->create();
    $expense = Expense::factory()->create([
        'category_id' => $cat->id,
        'amount' => 50,
        'tax_amount' => 0,
        'total_amount' => 50,
        'payment_status' => 'unpaid',
    ]);

    $this->actingAs($user);
    $resp = $this->postJson(route('api.expenses.payments.store', $expense), [
        'payment_date' => now()->toDateString(),
        'amount' => 50,
        'payment_method' => 'cash',
    ]);

    $resp->assertCreated();
    $this->assertDatabaseHas('expense_payments', ['expense_id' => $expense->id, 'amount' => 50]);
});
