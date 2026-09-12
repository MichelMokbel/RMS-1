<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('manager', 'web');
    Permission::findOrCreate('order-labels.print', 'web');
    Permission::findOrCreate('order-label-printers.manage', 'web');
    Role::findByName('admin', 'web')->givePermissionTo(['order-labels.print', 'order-label-printers.manage']);
    Role::findByName('manager', 'web')->givePermissionTo('order-labels.print');
});

it('lets administrators configure printers and open the label queue', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/settings/order-label-printers')
        ->assertOk()
        ->assertSee('Order Label Printers')
        ->assertSee('Profiles remain inactive');

    $this->actingAs($admin)
        ->get('/order-labels')
        ->assertOk()
        ->assertSee('Order labels')
        ->assertSee('No verified active printer');
});

it('allows managers to print but not configure printers', function () {
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('manager');
    DB::table('user_branch_access')->insert([
        'user_id' => $manager->id,
        'branch_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($manager)->get('/order-labels')->assertOk();
    $this->actingAs($manager)->get('/settings/order-label-printers')->assertForbidden();
});

it('denies unrelated users from label surfaces', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get('/order-labels')->assertForbidden();
    $this->actingAs($user)->get('/settings/order-label-printers')->assertForbidden();
});
