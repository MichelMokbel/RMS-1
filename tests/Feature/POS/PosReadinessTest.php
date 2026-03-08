<?php

use App\Models\ArInvoice;
use App\Models\ApInvoice;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\PettyCashWallet;
use App\Models\PosDocumentSequence;
use App\Models\PosPrintJob;
use App\Models\PosPrintStreamEvent;
use App\Models\PosTerminal;
use App\Models\RestaurantArea;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableSession;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

function enablePosAccess(User $user): void
{
    Permission::findOrCreate('pos.login', 'web');
    $user->givePermissionTo('pos.login');
    $user->forceFill(['pos_enabled' => true])->save();
}

/**
 * @param  array<int, int>  $branchIds
 */
function grantBranchAccess(User $user, array $branchIds): void
{
    foreach ($branchIds as $branchId) {
        DB::table('branches')->insertOrIgnore([
            'id' => (int) $branchId,
            'name' => 'Branch '.(int) $branchId,
            'code' => 'B'.(int) $branchId,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_branch_access')->insertOrIgnore([
            'user_id' => (int) $user->id,
            'branch_id' => (int) $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function posToken(User $user, string $deviceId): string
{
    enablePosAccess($user);
    $branchId = (int) (PosTerminal::query()->where('device_id', $deviceId)->value('branch_id') ?? 1);
    grantBranchAccess($user, [$branchId]);

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

test('print job enqueue is idempotent by source terminal + client_job_id', function () {
    $user = User::factory()->create(['status' => 'active']);
    $source = seedPosTerminal('PRINT-DEV-A', 'T01', 1);
    seedPosTerminal('PRINT-DEV-A-TARGET', 'T02', 1);
    $token = posToken($user, 'PRINT-DEV-A');

    $clientJobId = (string) Str::uuid();
    $requestBody = [
        'client_job_id' => $clientJobId,
        'target_terminal_code' => 'T02',
        'target' => 'receipt_printer',
        'doc_type' => 'receipt',
        'payload_base64' => base64_encode('Invoice #123'),
        'created_at' => now()->toISOString(),
        'metadata' => ['order_number' => 'INV-123'],
    ];

    $r1 = $this->withToken($token)->postJson('/api/pos/print-jobs', $requestBody)->assertOk()->json();
    expect((string) ($r1['status'] ?? ''))->toBe(PosPrintJob::STATUS_QUEUED);
    $jobId = (int) ($r1['job_id'] ?? 0);
    expect($jobId)->toBeGreaterThan(0);

    $r2 = $this->withToken($token)->postJson('/api/pos/print-jobs', $requestBody)->assertOk()->json();
    expect((int) ($r2['job_id'] ?? 0))->toBe($jobId);

    expect(PosPrintJob::query()
        ->where('source_terminal_id', (int) $source->id)
        ->where('client_job_id', $clientJobId)
        ->count())->toBe(1);
    $targetTerminal = PosTerminal::query()
        ->where('branch_id', 1)
        ->where('code', 'T02')
        ->firstOrFail();
    expect(PosPrintStreamEvent::query()->where('terminal_id', (int) $targetTerminal->id)->count())->toBe(1);
});

test('print job pull claims and ack prints the job and updates terminal status', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('PRINT-DEV-B', 'T02', 1);
    $token = posToken($user, 'PRINT-DEV-B');

    $enqueue = $this->withToken($token)->postJson('/api/pos/print-jobs', [
        'client_job_id' => (string) Str::uuid(),
        'target_terminal_code' => 'T02',
        'target' => 'receipt_printer',
        'doc_type' => 'receipt',
        'payload_base64' => base64_encode('ticket body'),
        'created_at' => now()->toISOString(),
        'metadata' => ['source' => 'test'],
    ])->assertOk()->json();

    $jobId = (int) ($enqueue['job_id'] ?? 0);
    expect($jobId)->toBeGreaterThan(0);

    $pull = $this->withToken($token)->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=20')
        ->assertOk()
        ->json();

    expect((array) ($pull['jobs'] ?? []))->toHaveCount(1);
    expect((int) ($pull['jobs'][0]['job_id'] ?? 0))->toBe($jobId);
    expect((string) ($pull['jobs'][0]['claim_token'] ?? ''))->not->toBe('');
    expect((int) ($pull['jobs'][0]['attempt_count'] ?? 0))->toBe(1);

    $ack = $this->withToken($token)->postJson("/api/pos/print-jobs/{$jobId}/ack", [
        'claim_token' => (string) ($pull['jobs'][0]['claim_token'] ?? ''),
        'status' => 'printed',
        'processing_ms' => 54,
    ])->assertOk()->json();

    expect((int) ($ack['job_id'] ?? 0))->toBe($jobId);
    expect((string) ($ack['final_status'] ?? ''))->toBe(PosPrintJob::STATUS_PRINTED);
    expect((int) ($ack['attempt_count'] ?? 0))->toBe(1);
    expect(array_key_exists('next_retry_at', $ack))->toBeTrue();
    expect($ack['next_retry_at'])->toBeNull();

    $terminal = PosTerminal::query()->where('device_id', 'PRINT-DEV-B')->firstOrFail();
    expect($terminal->print_agent_seen_at)->not->toBeNull();

    $status = $this->withToken($token)->getJson('/api/pos/print-terminals/T02/status?branch_id=1')
        ->assertOk()
        ->json();

    expect((bool) ($status['online'] ?? false))->toBeTrue();
    expect($status['last_seen_at'] ?? null)->toBe($status['print_agent_seen_at'] ?? null);
    expect((int) ($status['pending_jobs'] ?? 1))->toBe(0);
});

test('print job ack failure schedules retry and fails terminally at max attempts', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('PRINT-DEV-C', 'T03', 1);
    $token = posToken($user, 'PRINT-DEV-C');

    $enqueue = $this->withToken($token)->postJson('/api/pos/print-jobs', [
        'client_job_id' => (string) Str::uuid(),
        'target_terminal_code' => 'T03',
        'target' => 'receipt_printer',
        'doc_type' => 'receipt',
        'payload_base64' => base64_encode('retry ticket'),
        'created_at' => now()->toISOString(),
        'metadata' => [],
    ])->assertOk()->json();

    $jobId = (int) ($enqueue['job_id'] ?? 0);
    expect($jobId)->toBeGreaterThan(0);

    PosPrintJob::query()->whereKey($jobId)->update(['max_attempts' => 2]);

    $pull1 = $this->withToken($token)->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=20')
        ->assertOk()
        ->json();
    $claim1 = (string) ($pull1['jobs'][0]['claim_token'] ?? '');
    expect($claim1)->not->toBe('');

    $ack1 = $this->withToken($token)->postJson("/api/pos/print-jobs/{$jobId}/ack", [
        'claim_token' => $claim1,
        'status' => 'failed',
        'error_code' => 'PAPER_JAM',
        'error_message' => 'Printer jam',
        'processing_ms' => 100,
    ])->assertOk()->json();

    expect((string) ($ack1['final_status'] ?? ''))->toBe(PosPrintJob::STATUS_FAILED);
    expect((string) ($ack1['next_retry_at'] ?? ''))->not->toBe('');

    $job = PosPrintJob::query()->findOrFail($jobId);
    expect((string) $job->status)->toBe(PosPrintJob::STATUS_QUEUED);
    $job->forceFill(['next_retry_at' => now()->subSecond()])->save();

    $pull2 = $this->withToken($token)->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=20')
        ->assertOk()
        ->json();
    $claim2 = (string) ($pull2['jobs'][0]['claim_token'] ?? '');

    expect((int) ($pull2['jobs'][0]['job_id'] ?? 0))->toBe($jobId);
    expect((int) ($pull2['jobs'][0]['attempt_count'] ?? 0))->toBe(2);

    $ack2 = $this->withToken($token)->postJson("/api/pos/print-jobs/{$jobId}/ack", [
        'claim_token' => $claim2,
        'status' => 'failed',
        'error_code' => 'OUT_OF_PAPER',
        'error_message' => 'No paper',
    ])->assertOk()->json();

    expect((string) ($ack2['final_status'] ?? ''))->toBe(PosPrintJob::STATUS_FAILED);
    expect(array_key_exists('next_retry_at', $ack2))->toBeTrue();
    expect($ack2['next_retry_at'])->toBeNull();
    expect((string) PosPrintJob::query()->findOrFail($jobId)->status)->toBe(PosPrintJob::STATUS_FAILED);
});

test('pull reclaims expired claims automatically', function () {
    $user = User::factory()->create(['status' => 'active']);
    $terminal = seedPosTerminal('PRINT-DEV-D', 'T04', 1);
    $token = posToken($user, 'PRINT-DEV-D');

    $job = PosPrintJob::query()->create([
        'client_job_id' => (string) Str::uuid(),
        'source_terminal_id' => (int) $terminal->id,
        'branch_id' => 1,
        'target_terminal_id' => (int) $terminal->id,
        'target' => 'receipt_printer',
        'doc_type' => 'receipt',
        'payload_base64' => base64_encode('expired claim'),
        'client_created_at' => now(),
        'job_type' => 'receipt',
        'payload' => ['content' => 'expired claim'],
        'metadata' => [],
        'status' => PosPrintJob::STATUS_CLAIMED,
        'attempt_count' => 1,
        'max_attempts' => 5,
        'next_retry_at' => null,
        'claimed_at' => now()->subMinute(),
        'claimed_by_terminal_id' => (int) $terminal->id,
        'claim_token' => 'expired-token',
        'claim_expires_at' => now()->subSecond(),
        'acked_at' => null,
        'processing_ms' => null,
        'last_error_code' => null,
        'last_error_message' => null,
        'created_by' => (int) $user->id,
    ]);

    $pull = $this->withToken($token)->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=20')
        ->assertOk()
        ->json();

    expect((int) ($pull['jobs'][0]['job_id'] ?? 0))->toBe((int) $job->id);
    expect((int) ($pull['jobs'][0]['attempt_count'] ?? 0))->toBe(2);
    expect((string) ($pull['jobs'][0]['claim_token'] ?? ''))->not->toBe('expired-token');
});

test('print pull returns 200 with empty jobs array when queue is empty', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('PRINT-DEV-EMPTY', 'T05', 1);
    $token = posToken($user, 'PRINT-DEV-EMPTY');

    $resp = $this->withToken($token)->getJson('/api/pos/print-jobs/pull?wait_seconds=0&limit=20')
        ->assertOk()
        ->json();

    expect($resp['jobs'] ?? null)->toBeArray()->toBe([]);
    expect((string) ($resp['server_timestamp'] ?? ''))->not->toBe('');
});

test('print stream emits ready, job_available, keepalive and terminal_status', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('PRINT-DEV-SSE', 'T06', 1);
    $token = posToken($user, 'PRINT-DEV-SSE');

    config()->set('pos.print_jobs.stream_heartbeat_seconds', 1);
    config()->set('pos.print_jobs.stream_max_seconds', 2);
    config()->set('pos.print_jobs.stream_idle_sleep_ms', 50);

    $this->withToken($token)->postJson('/api/pos/print-jobs', [
        'client_job_id' => (string) Str::uuid(),
        'target_terminal_code' => 'T06',
        'target' => 'receipt_printer',
        'doc_type' => 'receipt',
        'payload_base64' => base64_encode('sse ticket'),
        'created_at' => now()->toISOString(),
        'metadata' => [],
    ])->assertOk();

    $response = $this->withToken($token)->get('/api/pos/print-jobs/stream');
    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('text/event-stream');

    $content = $response->streamedContent();
    expect($content)->toContain('event: ready');
    expect($content)->toContain('event: job_available');
    expect($content)->toContain('event: keepalive');
    expect($content)->toContain('event: terminal_status');
});

test('print stream requires authentication', function () {
    $this->getJson('/api/pos/print-jobs/stream')->assertStatus(401);
});

test('prune print stream events command removes old rows', function () {
    $terminal = seedPosTerminal('PRINT-DEV-PRUNE', 'T07', 1);

    $oldEvent = PosPrintStreamEvent::query()->create([
        'terminal_id' => (int) $terminal->id,
        'event_type' => PosPrintStreamEvent::EVENT_JOB_AVAILABLE,
        'payload_json' => ['a' => 1],
        'created_at' => now()->subHours(30),
    ]);
    $recentEvent = PosPrintStreamEvent::query()->create([
        'terminal_id' => (int) $terminal->id,
        'event_type' => PosPrintStreamEvent::EVENT_JOB_AVAILABLE,
        'payload_json' => ['b' => 2],
        'created_at' => now()->subHour(),
    ]);

    Artisan::call('pos:prune-print-stream-events', ['--hours' => 24]);

    expect(PosPrintStreamEvent::query()->whereKey($oldEvent->id)->exists())->toBeFalse();
    expect(PosPrintStreamEvent::query()->whereKey($recentEvent->id)->exists())->toBeTrue();
});

test('bootstrap includes receipt_profile with normalized lines and fallbacks', function () {
    $user = User::factory()->create(['status' => 'active']);
    seedPosTerminal('DEV-A', 'T01', 1);
    $token = posToken($user, 'DEV-A');

    config()->set('pos.receipt_profile', [
        'brand_name_en' => 'Layla Kitchen',
        'brand_name_ar' => '',
        'legal_name_en' => 'LAYLA KITCHEN W.L.L',
        'legal_name_ar' => '',
        'branch_name_en' => '',
        'branch_name_ar' => '',
        'address_lines_en' => [' Line A ', '', 'Line B '],
        'address_lines_ar' => ' AR1 | | AR2 ',
        'phone' => '44413660',
        'logo_url' => 'https://example.com/logo.png',
        'footer_note_en' => 'Powered by qsale.qa',
        'footer_note_ar' => '',
        'timezone' => '',
    ]);

    $resp = $this->withToken($token)
        ->getJson('/api/pos/bootstrap')
        ->assertOk()
        ->json();

    $profile = (array) ($resp['receipt_profile'] ?? []);
    $expectedKeys = [
        'brand_name_en',
        'brand_name_ar',
        'legal_name_en',
        'legal_name_ar',
        'branch_name_en',
        'branch_name_ar',
        'address_lines_en',
        'address_lines_ar',
        'phone',
        'logo_url',
        'footer_note_en',
        'footer_note_ar',
        'timezone',
    ];
    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $profile))->toBeTrue();
    }

    expect((string) $profile['brand_name_en'])->toBe('Layla Kitchen');
    expect((string) $profile['legal_name_en'])->toBe('LAYLA KITCHEN W.L.L');
    expect((string) $profile['branch_name_en'])->toBe('Branch 1');
    expect($profile['address_lines_en'])->toBe(['Line A', 'Line B']);
    expect($profile['address_lines_ar'])->toBe(['AR1', 'AR2']);
    expect((string) $profile['phone'])->toBe('44413660');
    expect((string) $profile['logo_url'])->toBe('https://example.com/logo.png');
    expect((string) $profile['footer_note_en'])->toBe('Powered by qsale.qa');
    expect((string) $profile['timezone'])->toBe((string) config('app.timezone', 'UTC'));
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
    $invoice = ArInvoice::query()->whereKey($invoiceId)->firstOrFail();
    $expectedInvoiceNo = (string) ($invoice->invoice_number ?: $invoiceId);

    expect((string) ($resp1['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((string) ($resp1['acks'][0]['invoice_no'] ?? ''))->toBe($expectedInvoiceNo);
    expect((string) ($resp1['acks'][0]['ref_no'] ?? ''))->toBe($posReference);

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
    expect((string) ($resp2['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((string) ($resp2['acks'][0]['invoice_no'] ?? ''))->toBe($expectedInvoiceNo);
    expect((string) ($resp2['acks'][0]['ref_no'] ?? ''))->toBe($posReference);
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
    expect((string) ($resp3['acks'][0]['server_entity_type'] ?? ''))->toBe('ar_invoice');
    expect((string) ($resp3['acks'][0]['invoice_no'] ?? ''))->toBe($expectedInvoiceNo);
    expect((string) ($resp3['acks'][0]['ref_no'] ?? ''))->toBe($posReference);
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
    $supplier = Supplier::factory()->create();
    config()->set('spend.petty_cash_internal_supplier_id', $supplier->id);

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
    expect((string) ($r1['acks'][0]['server_entity_type'] ?? ''))->toBe('ap_invoice');

    $invoiceNumber = 'POS-PC-'.strtoupper(substr(str_replace('-', '', $expenseUuid), 0, 20));
    expect(ApInvoice::query()->where('invoice_number', $invoiceNumber)->where('is_expense', true)->count())->toBe(1);
    $invoice = ApInvoice::query()->where('invoice_number', $invoiceNumber)->firstOrFail();

    $this->assertDatabaseHas('expense_profiles', [
        'invoice_id' => $invoice->id,
        'channel' => 'petty_cash',
        'approval_status' => 'submitted',
    ]);

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
    expect((string) ($r2['acks'][0]['server_entity_type'] ?? ''))->toBe('ap_invoice');
    expect(ApInvoice::query()->where('invoice_number', $invoiceNumber)->where('is_expense', true)->count())->toBe(1);
});

test('pos login requires device_id bound to a terminal', function () {
    $user = User::factory()->create(['status' => 'active']);
    enablePosAccess($user);

    $this->postJson('/api/pos/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_id' => 'UNKNOWN-DEVICE',
    ])->assertStatus(403);
});

test('pos login fails when pos is disabled for user', function () {
    $user = User::factory()->create(['status' => 'active', 'pos_enabled' => false]);
    Permission::findOrCreate('pos.login', 'web');
    $user->givePermissionTo('pos.login');
    seedPosTerminal('DEV-A', 'T01', 1);

    $this->postJson('/api/pos/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_id' => 'DEV-A',
    ])->assertStatus(403);
});

test('pos login fails when user lacks pos.login permission', function () {
    $user = User::factory()->create(['status' => 'active', 'pos_enabled' => true]);
    seedPosTerminal('DEV-A', 'T01', 1);

    $this->postJson('/api/pos/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_id' => 'DEV-A',
    ])->assertStatus(403);
});

test('pos login fails when terminal branch is outside user allowlist', function () {
    $user = User::factory()->create(['status' => 'active']);
    enablePosAccess($user);
    grantBranchAccess($user, [1]);
    seedPosTerminal('DEV-BR2', 'T02', 2);

    $this->postJson('/api/pos/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_id' => 'DEV-BR2',
    ])->assertStatus(403);
});

test('pos login works with username', function () {
    $user = User::factory()->create([
        'username' => 'cashier.user',
        'email' => 'cashier@example.com',
        'status' => 'active',
    ]);
    enablePosAccess($user);
    grantBranchAccess($user, [1]);
    seedPosTerminal('DEV-A', 'T01', 1);

    $response = $this->postJson('/api/pos/login', [
        'username' => 'cashier.user',
        'password' => 'password',
        'device_id' => 'DEV-A',
    ])->assertOk()->json();

    expect((string) ($response['user']['email'] ?? ''))->toBe('cashier@example.com');
    expect((string) ($response['token'] ?? ''))->not->toBe('');
});

test('pos login works for waiter role when pos is enabled', function () {
    Permission::findOrCreate('pos.login', 'web');
    $waiterRole = Role::findOrCreate('waiter', 'web');
    $waiterRole->givePermissionTo('pos.login');

    $user = User::factory()->create([
        'username' => 'waiter.user',
        'email' => 'waiter@example.com',
        'status' => 'active',
        'pos_enabled' => true,
    ]);
    $user->assignRole('waiter');

    grantBranchAccess($user, [1]);
    seedPosTerminal('DEV-WAITER', 'T03', 1);

    $this->postJson('/api/pos/login', [
        'username' => 'waiter.user',
        'password' => 'password',
        'device_id' => 'DEV-WAITER',
    ])->assertOk();
});

test('pos setup branches returns active branches for valid credentials', function () {
    $user = User::factory()->create([
        'username' => 'setup.user',
        'email' => 'setup@example.com',
        'status' => 'active',
    ]);
    enablePosAccess($user);

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
    grantBranchAccess($user, [2001]);

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
    enablePosAccess($user);

    DB::table('branches')->insertOrIgnore([
        'id' => 2003,
        'name' => 'Register Branch',
        'code' => 'REG',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantBranchAccess($user, [2003]);

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
