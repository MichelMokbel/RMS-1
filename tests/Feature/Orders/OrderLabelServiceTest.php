<?php

use App\Models\AccountingCompany;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLabelPrint;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PosPrintJob;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Orders\OrderLabelPdfRenderer;
use App\Services\Orders\OrderLabelService;
use App\Services\POS\PosPrintJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    Permission::findOrCreate('order-labels.print', 'web');
    Role::findByName('admin', 'web')->givePermissionTo('order-labels.print');
});

function labelFixture(): array
{
    $company = AccountingCompany::query()->create([
        'name' => 'Layla Kitchen',
        'code' => 'LK-LABEL',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);
    DB::table('branches')->where('id', 1)->update(['company_id' => $company->id]);

    $terminal = PosTerminal::query()->create([
        'branch_id' => 1,
        'code' => 'T90',
        'name' => 'Label agent',
        'device_id' => 'LABEL-AGENT-TEST',
        'active' => true,
    ]);
    $profile = OrderLabelPrinterProfile::query()->create([
        'company_id' => $company->id,
        'branch_id' => 1,
        'terminal_id' => $terminal->id,
        'code' => 'KITCHEN',
        'name' => 'Kitchen labels',
        'department' => 'kitchen',
        'model_code' => 'TEST-300',
        'os_queue_name' => 'Test_Label_Queue',
        'resolution_dpi' => 300,
        'media_mode' => 'fixed',
        'width_tenths_mm' => 580,
        'height_tenths_mm' => 900,
        'default_copies' => 1,
        'is_verified' => true,
        'is_active' => true,
        'revision' => 1,
    ]);
    $actor = User::factory()->create(['status' => 'active']);
    $actor->assignRole('admin');
    $menuItem = MenuItem::factory()->create([
        'name' => 'Chicken Machboos',
    ]);
    $order = Order::factory()->create([
        'branch_id' => 1,
        'status' => 'Draft',
        'scheduled_date' => '2026-09-12',
        'scheduled_time' => '2026-09-12 16:30:00',
        'customer_name_snapshot' => 'Label Customer',
        'customer_phone_snapshot' => '66752347',
        'delivery_address_snapshot' => 'West Bay, Building 10',
        'total_amount' => 777,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $menuItem->id,
        'description_snapshot' => 'Daily Dish (Main) - '.$menuItem->code.' Chicken Machboos',
        'quantity' => 2,
        'unit_price' => 388.5,
        'line_total' => 777,
    ]);

    return compact('company', 'terminal', 'profile', 'actor', 'menuItem', 'order');
}

it('renders and queues an immutable price-free server label idempotently', function () {
    $fixture = labelFixture();
    $uuid = (string) Str::uuid();

    $label = app(OrderLabelService::class)->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 1, $uuid
    );
    $same = app(OrderLabelService::class)->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 1, $uuid
    );

    expect($same->id)->toBe($label->id)
        ->and(OrderLabelPrint::query()->count())->toBe(1)
        ->and(PosPrintJob::query()->count())->toBe(1)
        ->and($label->status)->toBe(OrderLabelPrint::STATUS_QUEUED)
        ->and($label->snapshot)->toMatchArray([
            'order_number' => $fixture['order']->order_number,
            'customer_name' => 'Label Customer',
            'destination' => 'West Bay, Building 10',
        ])
        ->and($label->snapshot['items'][0]['description'])->toBe('Chicken Machboos')
        ->and(json_encode($label->snapshot))->not->toContain('66752347')
        ->and(json_encode($label->snapshot))->not->toContain('777');

    $job = $label->printJob;
    expect($job->source_terminal_id)->toBeNull()
        ->and($job->server_job_uuid)->toBe($uuid)
        ->and($job->doc_type)->toBe('order_label_pdf')
        ->and(base64_decode($job->payload_base64, true))->toStartWith('%PDF-');
});

it('tracks claims, acknowledgement, and explicit reprint lineage', function () {
    $fixture = labelFixture();
    $service = app(OrderLabelService::class);
    $label = $service->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 2, (string) Str::uuid()
    );

    $claimed = app(PosPrintJobService::class)->pull($fixture['terminal'], 0, 1)[0];
    expect($label->fresh()->status)->toBe(OrderLabelPrint::STATUS_CLAIMED);

    app(PosPrintJobService::class)->ack($fixture['terminal'], $claimed->id, [
        'claim_token' => $claimed->claim_token,
        'status' => PosPrintJob::STATUS_PRINTED,
        'processing_ms' => 100,
    ]);
    expect($label->fresh()->status)->toBe(OrderLabelPrint::STATUS_PRINTED)
        ->and($label->fresh()->printed_at)->not->toBeNull();

    $reprint = $service->request(
        $fixture['actor'],
        'order',
        $fixture['order']->id,
        $fixture['profile']->id,
        1,
        (string) Str::uuid(),
        $label->id,
        'Damaged during packing',
    );

    expect($reprint->sequence)->toBe(2)
        ->and($reprint->reprint_of_id)->toBe($label->id)
        ->and($reprint->reprint_reason)->toBe('Damaged during packing')
        ->and(PosPrintJob::query()->count())->toBe(2);
});

it('keeps inactive profiles and cross-branch sources from queueing', function () {
    $fixture = labelFixture();
    $fixture['profile']->update(['is_active' => false]);

    expect(fn () => app(OrderLabelService::class)->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 1, (string) Str::uuid()
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(PosPrintJob::query()->count())->toBe(0);
});

it('calculates bounded continuous media height', function () {
    $fixture = labelFixture();
    $fixture['profile']->update([
        'media_mode' => 'continuous',
        'height_tenths_mm' => null,
        'min_height_tenths_mm' => 500,
        'max_height_tenths_mm' => 700,
    ]);

    $height = app(OrderLabelPdfRenderer::class)->heightTenthsMm([
        'destination' => str_repeat('A', 200),
        'items' => [['description' => str_repeat('B', 500)]],
    ], $fixture['profile']->fresh());

    expect($height)->toBe(700);
});

it('rejects fixed labels that would clip order contents', function () {
    $fixture = labelFixture();
    $fixture['profile']->update(['height_tenths_mm' => 400]);
    foreach (range(1, 8) as $index) {
        OrderItem::factory()->create([
            'order_id' => $fixture['order']->id,
            'description_snapshot' => 'A very long item description that needs several lines '.$index,
            'quantity' => 1,
        ]);
    }

    expect(fn () => app(OrderLabelService::class)->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 1, (string) Str::uuid()
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(PosPrintJob::query()->count())->toBe(0);
});

it('cancels and reassigns only queued labels without changing the order', function () {
    $fixture = labelFixture();
    $service = app(OrderLabelService::class);
    $label = $service->request(
        $fixture['actor'], 'order', $fixture['order']->id, $fixture['profile']->id, 1, (string) Str::uuid()
    );
    $secondTerminal = PosTerminal::query()->create([
        'branch_id' => 1,
        'code' => 'T93',
        'name' => 'Backup label agent',
        'device_id' => 'BACKUP-LABEL-AGENT',
        'active' => true,
    ]);
    $secondProfile = OrderLabelPrinterProfile::query()->create([
        'company_id' => $fixture['company']->id,
        'branch_id' => 1,
        'terminal_id' => $secondTerminal->id,
        'code' => 'BACKUP',
        'name' => 'Backup labels',
        'department' => 'packing',
        'model_code' => 'TEST-300-B',
        'os_queue_name' => 'Backup_Label_Queue',
        'resolution_dpi' => 300,
        'media_mode' => 'fixed',
        'width_tenths_mm' => 580,
        'height_tenths_mm' => 900,
        'default_copies' => 1,
        'is_verified' => true,
        'is_active' => true,
        'revision' => 1,
    ]);

    $moved = $service->reassign($fixture['actor'], $label, $secondProfile);
    expect($moved->printer_profile_id)->toBe($secondProfile->id)
        ->and($moved->printJob->target_terminal_id)->toBe($secondTerminal->id)
        ->and($moved->printJob->metadata['printer_queue'])->toBe('Backup_Label_Queue');

    $cancelled = $service->cancel($fixture['actor'], $moved);
    expect($cancelled->status)->toBe(OrderLabelPrint::STATUS_CANCELLED)
        ->and($cancelled->printJob->status)->toBe(PosPrintJob::STATUS_CANCELLED)
        ->and($fixture['order']->fresh()->status)->toBe('Draft')
        ->and($fixture['order']->fresh()->total_amount)->toBe('777.000');
});

it('opens an inactive label format in the local browser print dialog without queueing a job', function () {
    $fixture = labelFixture();
    $fixture['profile']->update([
        'width_tenths_mm' => 370,
        'height_tenths_mm' => 570,
        'is_verified' => false,
        'is_active' => false,
    ]);

    $response = $this->actingAs($fixture['actor'])->get(route('order-labels.print.show', [
        'sourceType' => 'order',
        'sourceId' => $fixture['order']->id,
        'profile_id' => $fixture['profile']->id,
        'copies' => 1,
    ]));

    $response->assertOk()
        ->assertSee('@page order-label-landscape { margin: 0; size: 57mm 37mm; }', false)
        ->assertSee('window.print()', false)
        ->assertSee('class="heading"', false)
        ->assertDontSee('class="footer"', false)
        ->assertDontSee('class="qr"', false)
        ->assertDontSee('class="destination"', false)
        ->assertSee('Label Customer')
        ->assertDontSee('West Bay, Building 10')
        ->assertSee('Chicken Machboos')
        ->assertDontSee('Daily Dish (Main)')
        ->assertDontSee($fixture['menuItem']->code)
        ->assertDontSee('66752347')
        ->assertDontSee('QAR')
        ->assertDontSee('388.5');
    expect(OrderLabelPrint::query()->count())->toBe(0)
        ->and(PosPrintJob::query()->count())->toBe(0);
});

it('blocks browser label printing across branches and for cancelled orders', function () {
    $fixture = labelFixture();
    DB::table('branches')->insert([
        'id' => 2,
        'name' => 'Other Branch',
        'company_id' => $fixture['company']->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $fixture['order']->update(['branch_id' => 2]);

    $this->actingAs($fixture['actor'])->get(route('order-labels.print.show', [
        'sourceType' => 'order',
        'sourceId' => $fixture['order']->id,
        'profile_id' => $fixture['profile']->id,
    ]))->assertStatus(422);

    $fixture['order']->update(['branch_id' => 1, 'status' => 'Cancelled']);
    $this->actingAs($fixture['actor'])->get(route('order-labels.print.show', [
        'sourceType' => 'order',
        'sourceId' => $fixture['order']->id,
        'profile_id' => $fixture['profile']->id,
    ]))->assertStatus(422);
});

it('prints the filtered service date as one local browser batch without queue state', function () {
    $fixture = labelFixture();
    $fixture['profile']->update(['width_tenths_mm' => 570, 'height_tenths_mm' => 370]);
    $second = Order::factory()->create([
        'branch_id' => 1,
        'status' => 'Draft',
        'scheduled_date' => '2026-09-12',
        'customer_name_snapshot' => 'Second Customer',
        'delivery_address_snapshot' => 'Al Sadd',
    ]);

    $response = $this->actingAs($fixture['actor'])->get(route('order-labels.print.batch', [
        'branch_id' => 1,
        'date' => '2026-09-12',
        'source_type' => 'order',
        'profile_id' => $fixture['profile']->id,
        'copies' => 1,
    ]));

    $response->assertOk()
        ->assertSee($fixture['order']->order_number)
        ->assertSee($second->order_number)
        ->assertSee('@page order-label-landscape { margin: 0; size: 57mm 37mm; }', false)
        ->assertSee('window.print()', false);
    expect(OrderLabelPrint::query()->count())->toBe(0)
        ->and(PosPrintJob::query()->count())->toBe(0);
});
