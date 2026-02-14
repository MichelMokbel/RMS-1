<?php

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

function seedPosTerminalForExtendedSync(string $deviceId, string $code = 'T01', int $branchId = 1): PosTerminal
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

function posTokenForExtendedDevice(User $user, string $deviceId): string
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

test('category.upsert creates, updates, and rejects cycles', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminalForExtendedSync('DEV-A', 'T01', 1);
    $token = posTokenForExtendedDevice($user, 'DEV-A');

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'cat-1',
                'type' => 'category.upsert',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'category' => [
                        'name' => 'Main',
                        'description' => 'Top level',
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    $categoryId = (int) ($resp['acks'][0]['server_entity_id'] ?? 0);
    expect($categoryId)->toBeGreaterThan(0);
    $category = Category::query()->whereKey($categoryId)->firstOrFail();
    expect($category->name)->toBe('Main');

    $stale = $category->updated_at?->copy()->subDay()?->toISOString();

    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'cat-2',
                'type' => 'category.upsert',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'category' => [
                        'id' => $categoryId,
                        'name' => 'Main Updated',
                        'updated_at' => $stale,
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    expect(Category::query()->whereKey($categoryId)->value('name'))->toBe('Main');

    $child = Category::create([
        'name' => 'Child',
        'parent_id' => $categoryId,
    ]);

    $resp3 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'cat-3',
                'type' => 'category.upsert',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'category' => [
                        'id' => $categoryId,
                        'name' => 'Main',
                        'parent_id' => $child->id,
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp3['acks'][0]['ok'])->toBeFalse();
    expect($resp3['acks'][0]['error_code'])->toBe('VALIDATION_ERROR');
});

test('customer payment and advance flows create payments and allocations', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForExtendedSync('DEV-A', 'T01', 1);
    $token = posTokenForExtendedDevice($user, 'DEV-A');

    $shift = PosShift::create([
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'device_id' => 'DEV-A',
        'user_id' => $user->id,
        'active' => true,
        'status' => 'open',
        'opening_cash_cents' => 0,
        'opened_at' => now(),
        'created_by' => $user->id,
    ]);

    $customer = Customer::factory()->create(['is_active' => true]);

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'unit_price_cents' => 2500,
        'line_total_cents' => 2500,
    ]);
    app(ArInvoiceService::class)->recalc($invoice->fresh(['items']));
    $invoice->update([
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $paymentUuid = (string) Str::uuid();
    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'pay-1',
                'type' => 'customer.payment.create',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'payment' => [
                        'client_uuid' => $paymentUuid,
                        'customer_id' => $customer->id,
                        'amount_cents' => 2500,
                        'method' => 'card',
                        'received_at' => now()->toISOString(),
                        'pos_shift_id' => $shift->id,
                    ],
                    'allocations' => [
                        ['invoice_id' => $invoice->id, 'amount_cents' => 2500],
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    $paymentId = (int) ($resp['acks'][0]['server_entity_id'] ?? 0);
    expect($paymentId)->toBeGreaterThan(0);

    $payment = Payment::query()->whereKey($paymentId)->firstOrFail();
    expect($payment->source)->toBe('ar');
    expect((string) $payment->client_uuid)->toBe($paymentUuid);
    expect((int) $payment->terminal_id)->toBe((int) $terminal->id);
    expect((int) $payment->pos_shift_id)->toBe((int) $shift->id);
    expect(PaymentAllocation::query()->where('payment_id', $paymentId)->count())->toBe(1);

    $advanceInvoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
    ]);
    ArInvoiceItem::factory()->create([
        'invoice_id' => $advanceInvoice->id,
        'unit_price_cents' => 4000,
        'line_total_cents' => 4000,
    ]);
    app(ArInvoiceService::class)->recalc($advanceInvoice->fresh(['items']));
    $advanceInvoice->update([
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $advanceUuid = (string) Str::uuid();
    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'adv-1',
                'type' => 'customer.advance.create',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'payment' => [
                        'client_uuid' => $advanceUuid,
                        'customer_id' => $customer->id,
                        'amount_cents' => 4000,
                        'method' => 'cash',
                        'received_at' => now()->toISOString(),
                        'pos_shift_id' => $shift->id,
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    $advancePaymentId = (int) ($resp2['acks'][0]['server_entity_id'] ?? 0);
    expect($advancePaymentId)->toBeGreaterThan(0);

    $resp3 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'adv-apply-1',
                'type' => 'customer.advance.apply',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'payment_client_uuid' => $advanceUuid,
                    'invoice_id' => $advanceInvoice->id,
                    'amount_cents' => 1500,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp3['acks'][0]['ok'])->toBeTrue();
    expect(PaymentAllocation::query()->where('payment_id', $advancePaymentId)->count())->toBe(1);

    $advanceInvoice->refresh();
    expect($advanceInvoice->status)->toBe('partially_paid');
});

test('supplier payment create records AP payment and allocations', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminalForExtendedSync('DEV-A', 'T01', 1);
    $token = posTokenForExtendedDevice($user, 'DEV-A');

    $supplier = Supplier::factory()->create();
    $invoice = ApInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'total_amount' => 100,
        'subtotal' => 100,
        'tax_amount' => 0,
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'ap-1',
                'type' => 'supplier.payment.create',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'supplier_id' => $supplier->id,
                    'payment_date' => now()->toDateString(),
                    'amount_cents' => 10000,
                    'payment_method' => 'bank_transfer',
                    'allocations' => [
                        ['invoice_id' => $invoice->id, 'amount_cents' => 10000],
                    ],
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    $paymentId = (int) ($resp['acks'][0]['server_entity_id'] ?? 0);
    expect($paymentId)->toBeGreaterThan(0);
    expect(ApPayment::query()->whereKey($paymentId)->exists())->toBeTrue();
    expect(ApPaymentAllocation::query()->where('payment_id', $paymentId)->count())->toBe(1);
});

test('shift opening cash update only works for open shifts', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForExtendedSync('DEV-A', 'T01', 1);
    $token = posTokenForExtendedDevice($user, 'DEV-A');

    $shift = PosShift::create([
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'device_id' => 'DEV-A',
        'user_id' => $user->id,
        'active' => true,
        'status' => 'open',
        'opening_cash_cents' => 0,
        'opened_at' => now(),
        'created_by' => $user->id,
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'sh-1',
                'type' => 'shift.opening_cash.update',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'shift_id' => $shift->id,
                    'opening_cash_cents' => 5000,
                    'reason' => 'Correction',
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    $shift->refresh();
    expect((int) $shift->opening_cash_cents)->toBe(5000);
    expect((int) $shift->opening_cash_adjusted_by)->toBe((int) $user->id);

    $shift->update(['status' => 'closed', 'active' => null, 'closed_at' => now()]);

    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'sh-2',
                'type' => 'shift.opening_cash.update',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'shift_id' => $shift->id,
                    'opening_cash_cents' => 6000,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeFalse();
    expect($resp2['acks'][0]['error_code'])->toBe('VALIDATION_ERROR');
});

test('shift close stores closing card cents and defaults to zero when omitted', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminalForExtendedSync('DEV-A', 'T01', 1);
    $token = posTokenForExtendedDevice($user, 'DEV-A');

    $shift = PosShift::create([
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'device_id' => 'DEV-A',
        'user_id' => $user->id,
        'active' => true,
        'status' => 'open',
        'opening_cash_cents' => 0,
        'opened_at' => now(),
        'created_by' => $user->id,
    ]);

    $resp = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'sh-close-1',
                'type' => 'shift.close',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'shift_id' => $shift->id,
                    'closed_at' => now()->toISOString(),
                    'closing_cash_cents' => 12000,
                    'closing_card_cents' => 34500,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp['acks'][0]['ok'])->toBeTrue();
    $shift->refresh();
    expect((int) $shift->closing_card_cents)->toBe(34500);

    $shift2 = PosShift::create([
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'device_id' => 'DEV-A',
        'user_id' => $user->id,
        'active' => true,
        'status' => 'open',
        'opening_cash_cents' => 0,
        'opened_at' => now(),
        'created_by' => $user->id,
    ]);

    $resp2 = $this->withToken($token)->postJson('/api/pos/sync', [
        'device_id' => 'DEV-A',
        'terminal_code' => 'T01',
        'branch_id' => 1,
        'last_pulled_at' => null,
        'events' => [
            [
                'event_id' => 'sh-close-2',
                'type' => 'shift.close',
                'client_uuid' => (string) Str::uuid(),
                'payload' => [
                    'shift_id' => $shift2->id,
                    'closed_at' => now()->toISOString(),
                    'closing_cash_cents' => 8000,
                ],
            ],
        ],
    ])->assertOk()->json();

    expect($resp2['acks'][0]['ok'])->toBeTrue();
    $shift2->refresh();
    expect((int) $shift2->closing_card_cents)->toBe(0);
});
