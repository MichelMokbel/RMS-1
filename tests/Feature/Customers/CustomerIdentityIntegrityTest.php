<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Customers\CustomerIdentityIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('classifies every customer reference in the migrated schema', function () {
    $result = app(CustomerIdentityIntegrityService::class)->report();

    expect($result['checks']['unclassified_references']['count'])->toBe(0);
});

it('reports merge, ownership, normalization, and untrusted timestamp issues without changing data', function () {
    $destination = Customer::factory()->create(['is_active' => true]);
    $source = Customer::factory()->create([
        'is_active' => true,
        'merged_into_customer_id' => $destination->id,
    ]);
    $user = User::factory()->create([
        'customer_id' => $destination->id,
        'portal_phone' => '55683442',
        'portal_phone_e164' => null,
        'portal_phone_verified_at' => now(),
    ]);
    $order = Order::factory()->create([
        'customer_id' => $source->id,
        'user_id' => $user->id,
    ]);

    $before = [
        'source_active' => $source->fresh()->is_active,
        'order_customer_id' => $order->fresh()->customer_id,
        'user_phone_e164' => $user->fresh()->portal_phone_e164,
    ];
    $result = app(CustomerIdentityIntegrityService::class)->report();

    expect($result['ok'])->toBeFalse()
        ->and($result['checks']['active_merged_sources']['count'])->toBe(1)
        ->and($result['checks']['live_references_on_merged_sources']['count'])->toBe(1)
        ->and($result['checks']['missing_normalized_phone']['count'])->toBeGreaterThanOrEqual(1)
        ->and($result['checks']['untrusted_phone_verification_timestamp']['count'])->toBe(1)
        ->and([
            'source_active' => $source->fresh()->is_active,
            'order_customer_id' => $order->fresh()->customer_id,
            'user_phone_e164' => $user->fresh()->portal_phone_e164,
        ])->toBe($before);
});
