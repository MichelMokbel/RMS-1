<?php

use App\Models\Customer;
use App\Models\PosSyncEvent;
use App\Models\PosTerminal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function seedPosTerminalForSync(string $deviceId, string $code = 'T01', int $branchId = 1): PosTerminal
{
    return PosTerminal::create([
        'branch_id' => $branchId,
        'code' => $code,
        'name' => $code,
        'device_id' => $deviceId,
        'active' => true,
        'last_seen_at' => now(),
    ]);
}

function posTokenForDevice(User $user, string $deviceId): string
{
    Permission::findOrCreate('pos.login', 'web');
    $user->givePermissionTo('pos.login');
    $user->forceFill(['pos_enabled' => true])->save();
    $branchId = (int) (PosTerminal::query()->where('device_id', $deviceId)->value('branch_id') ?? 1);
    DB::table('user_branch_access')->insertOrIgnore([
        'user_id' => (int) $user->id,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (string) $user->createToken('pos:'.$deviceId, ['pos:*', 'device:'.$deviceId])->plainTextToken;
}

test('test_processing_event_is_not_acked_as_ok_without_entity', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForSync('DEV-A', 'T01', 1);
    $token = posTokenForDevice($user, 'DEV-A');

    $eventUuid = (string) Str::uuid();

    PosSyncEvent::create([
        'terminal_id' => (int) $terminal->id,
        'event_id' => 'evt-001',
        'client_uuid' => $eventUuid,
        'type' => 'customer.upsert',
        'status' => 'processing',
        'server_entity_type' => null,
        'server_entity_id' => null,
        'applied_at' => null,
        'error_code' => null,
        'error_message' => null,
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-001-retry',
                'type' => 'customer.upsert',
                'client_uuid' => $eventUuid,
                'payload' => [
                    'customer' => [
                        'id' => null,
                        'name' => 'Alice',
                        'phone' => null,
                        'email' => null,
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeFalse();
    expect($resp['acks'][0]['error_code'])->toBe('INCOMPLETE_PROCESSING');

    $row = PosSyncEvent::query()->where('client_uuid', $eventUuid)->firstOrFail();
    expect((string) $row->status)->toBe('failed');
    expect((string) $row->error_code)->toBe('INCOMPLETE_PROCESSING');

    expect(Customer::query()->count())->toBe(0);
});

test('test_failed_event_is_retryable_and_can_succeed_on_retry', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForSync('DEV-A', 'T01', 1);
    $token = posTokenForDevice($user, 'DEV-A');

    $eventUuid = (string) Str::uuid();

    PosSyncEvent::create([
        'terminal_id' => (int) $terminal->id,
        'event_id' => 'evt-002',
        'client_uuid' => $eventUuid,
        'type' => 'customer.upsert',
        'status' => 'failed',
        'server_entity_type' => null,
        'server_entity_id' => null,
        'applied_at' => null,
        'error_code' => 'NETWORK_TIMEOUT',
        'error_message' => 'temporary',
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-002-retry',
                'type' => 'customer.upsert',
                'client_uuid' => $eventUuid,
                'payload' => [
                    'customer' => [
                        'id' => null,
                        'name' => 'Bob',
                        'phone' => null,
                        'email' => null,
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    expect((int) ($resp['acks'][0]['server_entity_id'] ?? 0))->toBeGreaterThan(0);
    expect((string) ($resp['acks'][0]['server_entity_type'] ?? ''))->toBe('customer');
    expect((string) ($resp['acks'][0]['applied_at'] ?? ''))->not()->toBe('');

    $row = PosSyncEvent::query()->where('client_uuid', $eventUuid)->firstOrFail();
    expect((string) $row->status)->toBe('applied');
    expect((int) $row->server_entity_id)->toBe((int) $resp['acks'][0]['server_entity_id']);
    expect($row->applied_at)->not()->toBeNull();

    expect(Customer::query()->count())->toBe(1);
});

test('test_duplicate_event_after_success_returns_ok_with_same_entity', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminalForSync('DEV-A', 'T01', 1);
    $token = posTokenForDevice($user, 'DEV-A');

    $eventUuid = (string) Str::uuid();

    $payload = [
        'customer' => [
            'id' => null,
            'name' => 'Carol',
            'phone' => null,
            'email' => null,
            'updated_at' => now()->toISOString(),
        ],
    ];

    $resp1 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-003',
                'type' => 'customer.upsert',
                'client_uuid' => $eventUuid,
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($resp1['acks'][0]['ok'])->toBeTrue();
    $customerId = (int) ($resp1['acks'][0]['server_entity_id'] ?? 0);
    $appliedAt = (string) ($resp1['acks'][0]['applied_at'] ?? '');
    expect($customerId)->toBeGreaterThan(0);
    expect($appliedAt)->not()->toBe('');
    expect(Customer::query()->count())->toBe(1);

    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-003-replay',
                'type' => 'customer.upsert',
                'client_uuid' => $eventUuid,
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    expect((int) ($resp2['acks'][0]['server_entity_id'] ?? 0))->toBe($customerId);
    expect((string) ($resp2['acks'][0]['applied_at'] ?? ''))->toBe($appliedAt);

    expect(Customer::query()->count())->toBe(1);
});
