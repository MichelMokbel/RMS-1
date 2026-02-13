<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\PosTerminal;
use App\Models\PosShift;
use App\Models\Customer;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use Illuminate\Support\Str;
use App\Services\POS\PosSyncService;

$user = User::factory()->create(['status' => 'active']);
$terminalCode = 'T'.str_pad((string) random_int(10, 99), 2, '0', STR_PAD_LEFT);
$terminal = PosTerminal::create([
    'branch_id' => 1,
    'code' => $terminalCode,
    'name' => $terminalCode,
    'device_id' => 'DEV-'.Str::upper(Str::random(4)),
    'active' => true,
    'last_seen_at' => now(),
]);
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
$invoice = ArInvoice::factory()->issued()->create([
    'customer_id' => $customer->id,
    'branch_id' => 1,
]);
ArInvoiceItem::factory()->create([
    'invoice_id' => $invoice->id,
    'unit_price_cents' => 2500,
    'line_total_cents' => 2500,
]);

$service = app(PosSyncService::class);
$resp = $service->sync($terminal, $user, 'DEV-A', null, [[
    'event_id' => 'pay-1',
    'type' => 'customer.payment.create',
    'client_uuid' => (string) Str::uuid(),
    'payload' => [
        'payment' => [
            'client_uuid' => (string) Str::uuid(),
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
]]);

var_export($resp);
