<?php

use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

it('imports customers from csv create mode', function () {
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    Storage::fake('local');
    $csv = "customer_code,name,customer_type,phone,email,is_active\nC1,Import One,retail,111,import1@example.com,1\n";
    $path = 'imports/test_customers.csv';
    Storage::disk('local')->put($path, $csv);

    $service = app(\App\Services\Customers\CustomerImportService::class);
    $result = $service->import(Storage::disk('local')->path($path), 'create');

    expect($result['created'])->toBe(1);
    expect(Customer::where('customer_code', 'C1')->exists())->toBeTrue();
});
