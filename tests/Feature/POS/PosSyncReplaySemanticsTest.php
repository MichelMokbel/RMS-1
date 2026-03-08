<?php

use App\Models\Customer;
use App\Models\ArInvoice;
use App\Models\MenuItem;
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

test('invoice replay ack includes invoice_no and ref_no', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminalForSync('DEV-A', 'T01', 1);
    $token = posTokenForDevice($user, 'DEV-A');

    $customer = Customer::factory()->create(['is_active' => true]);
    $menu = MenuItem::factory()->create(['is_active' => true]);

    $eventUuid = (string) Str::uuid();
    $invoiceUuid = (string) Str::uuid();
    $posReference = 'T01-20260204-000555';

    $payload = [
        'pos_reference' => $posReference,
        'client_uuid' => $invoiceUuid,
        'payment_type' => 'cash',
        'customer_id' => $customer->id,
        'issue_date' => '2026-02-04',
        'lines' => [
            [
                'menu_item_id' => $menu->id,
                'qty' => '1.000',
                'unit_price_cents' => 1500,
                'line_discount_cents' => 0,
                'line_total_cents' => 1500,
            ],
        ],
        'totals' => [
            'subtotal_cents' => 1500,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 1500,
        ],
        'payments' => [
            [
                'client_uuid' => (string) Str::uuid(),
                'method' => 'cash',
                'amount_cents' => 1500,
                'received_at' => now()->toISOString(),
            ],
        ],
    ];

    $resp1 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-inv-1',
                'type' => 'invoice.finalize',
                'client_uuid' => $eventUuid,
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($resp1['acks'][0]['ok'])->toBeTrue();
    expect((string) ($resp1['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((string) ($resp1['acks'][0]['ref_no'] ?? ''))->toBe($posReference);
    $invoiceId = (int) ($resp1['acks'][0]['server_entity_id'] ?? 0);
    $invoiceNo = (string) ($resp1['acks'][0]['invoice_no'] ?? '');
    expect($invoiceId)->toBeGreaterThan(0);
    expect($invoiceNo)->not()->toBe('');

    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-inv-1-replay',
                'type' => 'invoice.finalize',
                'client_uuid' => $eventUuid,
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    expect((string) ($resp2['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((int) ($resp2['acks'][0]['server_entity_id'] ?? 0))->toBe($invoiceId);
    expect((string) ($resp2['acks'][0]['invoice_no'] ?? ''))->toBe($invoiceNo);
    expect((string) ($resp2['acks'][0]['ref_no'] ?? ''))->toBe($posReference);
});

test('applied invoice replay ack keeps invoice_no and ref_no non-empty when legacy fields are null', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForSync('DEV-A', 'T01', 1);
    $token = posTokenForDevice($user, 'DEV-A');

    $customer = Customer::factory()->create(['is_active' => true]);
    $invoice = ArInvoice::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'status' => 'issued',
        'invoice_number' => null,
        'pos_reference' => null,
    ]);

    $eventUuid = (string) Str::uuid();
    PosSyncEvent::create([
        'terminal_id' => (int) $terminal->id,
        'event_id' => 'evt-inv-legacy',
        'client_uuid' => $eventUuid,
        'type' => 'invoice.finalize',
        'status' => 'applied',
        'server_entity_type' => 'ar_invoice',
        'server_entity_id' => (int) $invoice->id,
        'applied_at' => now(),
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'evt-inv-legacy-replay',
                'type' => 'invoice.finalize',
                'client_uuid' => $eventUuid,
                'payload' => ['noop' => true],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    expect((string) ($resp['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((string) ($resp['acks'][0]['invoice_no'] ?? ''))->toBe((string) $invoice->id);
    expect((string) ($resp['acks'][0]['ref_no'] ?? ''))->toBe((string) $invoice->id);
});
