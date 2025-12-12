<?php

use App\Models\Category;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function makeAdmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'admin']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

test('admin can create a category', function () {
    $this->actingAs(makeAdmin());

    Volt::test('categories.create')
        ->set('name', 'New Category')
        ->set('description', 'Desc')
        ->call('save')
        ->assertRedirect(route('categories.index'));

    expect(Category::where('name', 'New Category')->exists())->toBeTrue();
});

test('admin can edit a category', function () {
    $this->actingAs(makeAdmin());
    $category = Category::create(['name' => 'Original', 'description' => null]);

    Volt::test('categories.edit', ['category' => $category])
        ->set('name', 'Updated')
        ->call('save')
        ->assertRedirect(route('categories.index'));

    expect($category->fresh()->name)->toEqual('Updated');
});

test('prevent self parent selection', function () {
    $this->actingAs(makeAdmin());
    $category = Category::create(['name' => 'Self', 'description' => null]);

    Volt::test('categories.edit', ['category' => $category])
        ->set('parent_id', $category->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('prevent cycle loops when selecting parent', function () {
    $this->actingAs(makeAdmin());
    $root = Category::create(['name' => 'Root', 'description' => null]);
    $child = Category::create(['name' => 'Child', 'description' => null, 'parent_id' => $root->id]);

    Volt::test('categories.edit', ['category' => $root])
        ->set('parent_id', $child->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

test('prevent duplicate names under same parent', function () {
    $this->actingAs(makeAdmin());
    $parent = Category::create(['name' => 'Parent', 'description' => null]);
    Category::create(['name' => 'Dup', 'description' => null, 'parent_id' => $parent->id]);

    Volt::test('categories.create')
        ->set('name', 'Dup')
        ->set('parent_id', $parent->id)
        ->call('save')
        ->assertHasErrors(['name']);
});

test('prevent deletion when category is in use', function () {
    $this->actingAs(makeAdmin());
    $parent = Category::create(['name' => 'Parent', 'description' => null]);
    Category::create(['name' => 'Child', 'description' => null, 'parent_id' => $parent->id]);

    Volt::test('categories.index')
        ->call('deleteCategory', $parent->id)
        ->assertHasErrors(['delete']);

    expect($parent->fresh())->not->toBeNull();
});

test('soft delete behavior', function () {
    $this->actingAs(makeAdmin());
    $category = Category::create(['name' => 'Disposable', 'description' => null]);

    Volt::test('categories.index')
        ->call('deleteCategory', $category->id);

    expect(Category::withTrashed()->find($category->id)->trashed())->toBeTrue();
});

test('non admin cannot access categories index', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->get(route('categories.index'))
        ->assertForbidden();
});
