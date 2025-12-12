<?php

use App\Models\User;
use function Pest\Laravel\post;

test('users can log in with username', function () {
    $user = User::factory()->create([
        'username' => 'adminuser',
        'password' => 'password',
        'status' => 'active',
    ]);

    $response = post('/login', [
        'username' => 'adminuser',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard'));
    expect(auth()->user()->is($user))->toBeTrue();
});

test('login fails with wrong password', function () {
    $user = User::factory()->create([
        'username' => 'wrongpass',
        'password' => 'password',
        'status' => 'active',
    ]);

    $response = post('/login', [
        'username' => 'wrongpass',
        'password' => 'not-the-password',
    ]);

    $response->assertSessionHasErrors('username');
    expect(auth()->check())->toBeFalse();
});

test('inactive users cannot log in', function () {
    $user = User::factory()->create([
        'username' => 'inactive-user',
        'password' => 'password',
        'status' => 'inactive',
    ]);

    $response = post('/login', [
        'username' => 'inactive-user',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
    expect(auth()->check())->toBeFalse();
});
