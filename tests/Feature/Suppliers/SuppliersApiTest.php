<?php

use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function adminApiUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    return $user;
}

it('returns only active suppliers by default (light)', function () {
    Supplier::factory()->count(2)->create(['status' => 'active']);
    Supplier::factory()->count(1)->inactive()->create();

    $user = adminApiUser();

    $response = actingAs($user)->getJson('/api/suppliers');
    $response->assertOk();
    $data = $response->json();

    expect(collect($data)->every(fn ($row) => str_contains($row['text'], 'QID/CR') || isset($row['id'])))->toBeTrue();
});

it('status=all returns active and inactive', function () {
    $active = Supplier::factory()->create(['status' => 'active']);
    $inactive = Supplier::factory()->inactive()->create();

    $user = adminApiUser();
    $response = actingAs($user)->getJson('/api/suppliers?status=all&light=false');

    $response->assertOk();
    $response->assertJsonFragment(['id' => $active->id]);
    $response->assertJsonFragment(['id' => $inactive->id]);
});

it('prevents archive when referenced', function () {
    $supplier = Supplier::factory()->create();

    // Dynamically create a reference table for the test
    \Illuminate\Support\Facades\Schema::create('tmp_refs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('supplier_id')->nullable();
    });
    \Illuminate\Support\Facades\DB::table('tmp_refs')->insert(['supplier_id' => $supplier->id]);

    // Register temporary table in checker list by overriding config via binding
    app()->bind(\App\Services\SupplierReferenceChecker::class, function () {
        return new class extends \App\Services\SupplierReferenceChecker {
            protected array $references = [
                ['table' => 'tmp_refs', 'column' => 'supplier_id'],
            ];
        };
    });

    $user = adminApiUser();
    $response = actingAs($user)->deleteJson("/api/suppliers/{$supplier->id}", ['force_archive' => true]);
    $response->assertStatus(422);

    \Illuminate\Support\Facades\Schema::dropIfExists('tmp_refs');
});
