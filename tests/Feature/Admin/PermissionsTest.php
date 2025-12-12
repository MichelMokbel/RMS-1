<?php

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('non-admin cannot access users index', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('users.index'))->assertForbidden();
});
