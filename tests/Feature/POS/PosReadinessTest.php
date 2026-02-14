<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\PettyCashExpense;
use App\Models\PettyCashWallet;
use App\Models\PosDocumentSequence;
use App\Models\PosTerminal;
use App\Models\RestaurantArea;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableSession;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

function seedPosTerminal(string $deviceId, string $code = 'T01', int $branchId = 1): PosTerminal
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

function posToken(User $user, string $deviceId): string
{
    return (string) $user->createToken('pos:'.$deviceId, ['pos:*', 'device:'.$deviceId])->plainTextToken;
}

test('sequence reservation produces non-overlapping ranges', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminal('DEV-A', 'T01', 1);
    $token = posToken($user, 'DEV-A');

    $date = '2026-02-04';

    $r1 = $this->withToken($token)->postJson('/api/pos/sequences/reserve', [
        'business_date' => $date,
        'count' => 5,
    ])->assertOk()->json();

    expect($r1['reserved_start'])->toBe(1);
    expect($r1['reserved_end'])->toBe(5);

    $r2 = $this->withToken($token)->postJson('/api/pos/sequences/reserve', [
        'business_date' => $date,
        'count' => 5,
    ])->assertOk()->json();

    expect($r2['reserved_start'])->toBe(6);
    expect($r2['reserved_end'])->toBe(10);

    $row = PosDocumentSequence::query()
        ->where('terminal_id', $terminal->id)
        ->where('business_date', $date)
        ->firstOrFail();

    expect((int) $row->last_number)->toBe(10);
});

test('invoice.finalize is idempotent by client_uuid and by (branch_id, pos_reference)', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('DEV-A', 'T01', 1);
    $token = posToken($user, 'DEV-A');

    $customer = Customer::factory()->create(['is_active' => true]);
    $menu = MenuItem::factory()->create(['is_active' => true]);

    $posReference = 'T01-20260204-000001';
    $invoiceUuid1 = (string) Str::uuid();

    $payloadBase = [
        'pos_reference' => $posReference,
        'client_uuid' => $invoiceUuid1,
        'payment_type' => 'cash',
        'customer_id' => $customer->id,
        'issue_date' => '2026-02-04',
        'lines' => [
            [
                'menu_item_id' => $menu->id,
                'qty' => '1.000',
                'unit_price_cents' => 1000,
                'line_discount_cents' => 0,
                'line_total_cents' => 1000,
            ],
        ],
        'totals' => [
            'subtotal_cents' => 1000,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 1000,
        ],
        'payments' => [
            [
                'client_uuid' => (string) Str::uuid(),
                'method' => 'cash',
                'amount_cents' => 1000,
                'received_at' => now()->toISOString(),
            ],
        ],
    ];

    $event1Uuid = (string) Str::uuid();
    $resp1 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'e1',
                'type' => 'invoice.finalize',
                'client_uuid' => $event1Uuid,
                'payload' => $payloadBase,
            ],
        ],
    ])->assertOk()->json();

    expect($resp1['acks'][0]['ok'])->toBeTrue();

    $invoiceId = (int) ($resp1['acks'][0]['server_entity_id'] ?? 0);
    expect($invoiceId)->toBeGreaterThan(0);
    expect(ArInvoice::query()->where('pos_reference', $posReference)->count())->toBe(1);

    // Same invoice client_uuid, different sync event client_uuid.
    $event2Uuid = (string) Str::uuid();
    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'e2',
                'type' => 'invoice.finalize',
                'client_uuid' => $event2Uuid,
                'payload' => $payloadBase,
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    expect(ArInvoice::query()->where('pos_reference', $posReference)->count())->toBe(1);

    // Same pos_reference, different invoice client_uuid should still be treated as success with existing invoice.
    $payloadOtherUuid = $payloadBase;
    $payloadOtherUuid['client_uuid'] = (string) Str::uuid();

    $event3Uuid = (string) Str::uuid();
    $resp3 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'e3',
                'type' => 'invoice.finalize',
                'client_uuid' => $event3Uuid,
                'payload' => $payloadOtherUuid,
            ],
        ],
    ])->assertOk()->json();

    expect($resp3['acks'][0]['ok'])->toBeTrue();
    expect(ArInvoice::query()->where('pos_reference', $posReference)->count())->toBe(1);
});

test('table session open is multi-device safe (second open rejected)', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('DEV-A', 'T01', 1);
    seedPosTerminal('DEV-B', 'T02', 1);

    $tokenA = posToken($user, 'DEV-A');
    $tokenB = posToken($user, 'DEV-B');

    $area = RestaurantArea::create(['branch_id' => 1, 'name' => 'Main', 'display_order' => 0, 'active' => true]);
    $table = RestaurantTable::create([
        'branch_id' => 1,
        'area_id' => $area->id,
        'code' => 'A1',
        'name' => 'A1',
        'capacity' => 4,
        'display_order' => 0,
        'active' => true,
    ]);

    $openA = $this->withToken($tokenA)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 't1',
                'type' => 'table_session.open',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'table_id' => $table->id,
                    'opened_at' => now()->toISOString(),
                    'guests' => 2,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($openA['acks'][0]['ok'])->toBeTrue();
    expect(RestaurantTableSession::query()->where('table_id', $table->id)->where('active', 1)->count())->toBe(1);

    // In long-running test app instances, the auth manager may cache the authenticated user/token.
    // Reset guards so the second request re-authenticates with its own bearer token.
    app('auth')->forgetGuards();

    $openB = $this->withToken($tokenB)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-B',
        'terminal_code' => 'T02',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 't2',
                'type' => 'table_session.open',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'table_id' => $table->id,
                    'opened_at' => now()->toISOString(),
                    'guests' => 2,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($openB['acks'][0]['ok'])->toBeFalse();
    expect($openB['acks'][0]['error_code'])->toBe('TABLE_ALREADY_OPEN');
    expect(RestaurantTableSession::query()->where('table_id', $table->id)->where('active', 1)->count())->toBe(1);
});

test('petty cash expense create is idempotent by client_uuid', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('DEV-A', 'T01', 1);
    $token = posToken($user, 'DEV-A');

    $wallet = PettyCashWallet::factory()->create(['active' => true, 'balance' => 100.00]);
    $category = ExpenseCategory::factory()->create(['active' => true]);

    $expenseUuid = (string) Str::uuid();

    $payload = [
        'client_uuid' => $expenseUuid,
        'wallet_id' => $wallet->id,
        'category_id' => $category->id,
        'expense_date' => '2026-02-04',
        'amount_cents' => 500,
        'description' => 'POS cash out',
    ];

    $r1 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'x1',
                'type' => 'petty_cash.expense.create',
                'client_uuid' => (string) Str::uuid(),
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($r1['acks'][0]['ok'])->toBeTrue();
    expect(PettyCashExpense::query()->where('client_uuid', $expenseUuid)->count())->toBe(1);

    $r2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'x2',
                'type' => 'petty_cash.expense.create',
                'client_uuid' => (string) Str::uuid(),
                'payload' => $payload,
            ],
        ],
    ])->assertOk()->json();

    expect($r2['acks'][0]['ok'])->toBeTrue();
    expect(PettyCashExpense::query()->where('client_uuid', $expenseUuid)->count())->toBe(1);
});

test('pos login requires device_id bound to a terminal', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->postJson('/api/pos/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_id' => 'UNKNOWN-DEVICE',
    ])->assertStatus(403);
});

test('pos login works with username', function () {
    $user = User::factory()->create([
        'username' => 'cashier.user',
        'email' => 'cashier@example.com',
        'status' => 'active',
    ]);
    seedPosTerminal('DEV-A', 'T01', 1);

    $response = $this->postJson('/api/pos/login', [
        'username' => 'cashier.user',
        'password' => 'password',
        'device_id' => 'DEV-A',
    ])->assertOk()->json();

    expect((string) ($response['user']['email'] ?? ''))->toBe('cashier@example.com');
    expect((string) ($response['token'] ?? ''))->not->toBe('');
});

test('pos setup branches returns active branches for valid credentials', function () {
    $user = User::factory()->create([
        'username' => 'setup.user',
        'email' => 'setup@example.com',
        'status' => 'active',
    ]);

    DB::table('branches')->insertOrIgnore([
        'id' => 2001,
        'name' => 'Main Branch',
        'code' => 'MAIN',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('branches')->insertOrIgnore([
        'id' => 2002,
        'name' => 'Closed Branch',
        'code' => 'CLOSED',
        'is_active' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson('/api/pos/setup/branches', [
        'username' => 'setup.user',
        'password' => 'password',
    ])->assertOk()->json();

    $ids = collect($response['branches'] ?? [])->pluck('id')->all();
    expect($ids)->toContain(2001);
    expect($ids)->not->toContain(2002);
});

test('pos setup terminal registration creates active terminal', function () {
    $user = User::factory()->create([
        'username' => 'register.user',
        'email' => 'register@example.com',
        'status' => 'active',
    ]);

    DB::table('branches')->insertOrIgnore([
        'id' => 2003,
        'name' => 'Register Branch',
        'code' => 'REG',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson('/api/pos/setup/terminals/register', [
        'email' => 'register@example.com',
        'password' => 'password',
        'branch_id' => 2003,
        'code' => 'T99',
        'name' => 'Front Desk POS',
        'device_id' => 'REG-DEVICE-001',
    ])->assertOk()->json();

    expect((bool) ($response['terminal']['active'] ?? false))->toBeTrue();
    expect((string) ($response['terminal']['code'] ?? ''))->toBe('T99');
    expect((int) ($response['terminal']['branch_id'] ?? 0))->toBe(2003);

    $terminal = PosTerminal::query()->where('device_id', 'REG-DEVICE-001')->first();
    expect($terminal)->not->toBeNull();
    expect((bool) $terminal->active)->toBeTrue();
});
