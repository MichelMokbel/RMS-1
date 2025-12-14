<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks deleting category in use', function () {
    $user = User::factory()->create();
    $cat = ExpenseCategory::factory()->create();
    Expense::factory()->create(['category_id' => $cat->id]);

    $this->actingAs($user);
    $resp = $this->deleteJson(route('api.expense-categories.destroy', $cat));
    $resp->assertStatus(422);
});
