<?php

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks guests from expenses', function () {
    $this->get('/expenses')->assertRedirect('/login');
});

it('allows admin to view expenses index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->get('/expenses')->assertStatus(200);
});
