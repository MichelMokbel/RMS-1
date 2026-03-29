<?php

use App\Models\Customer;
use App\Services\Customers\CustomerCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('resequences existing customers by id and generates the next code after resequencing', function () {
    DB::table('customers')->insert([
        [
            'id' => 10,
            'customer_code' => 'LEGACY-X',
            'name' => 'Customer Ten',
            'customer_type' => Customer::TYPE_RETAIL,
            'credit_limit' => 0,
            'credit_terms_days' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 22,
            'customer_code' => 'ABC',
            'name' => 'Customer Twenty Two',
            'customer_type' => Customer::TYPE_RETAIL,
            'credit_limit' => 0,
            'credit_terms_days' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $migration = require base_path('database/migrations/2026_03_28_000002_resequence_customer_codes.php');
    $migration->up();

    expect(Customer::query()->whereKey(10)->value('customer_code'))->toBe('CUST-0001');
    expect(Customer::query()->whereKey(22)->value('customer_code'))->toBe('CUST-0002');
    expect(app(CustomerCodeService::class)->nextCode())->toBe('CUST-0003');
});
