<?php

use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\AR\DeliveryNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    $this->user = User::factory()->create(['status' => 'active'])->assignRole('admin');
    $this->actingAs($this->user);
    $this->branch = Branch::query()->firstOrCreate(['id' => 1], ['name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
    $this->customer = Customer::factory()->create(['delivery_address' => 'Customer delivery address']);
});

it('generates one issued delivery note from an issued invoice with exact item snapshots', function () {
    $invoice = ArInvoice::factory()->issued()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'company_id' => $this->branch->company_id,
        'invoice_number' => 'INV9001',
    ]);
    ArInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Delivered item',
        'qty' => '2.500',
        'unit' => 'tray',
        'unit_price_cents' => 12500,
        'discount_cents' => 500,
        'tax_cents' => 250,
        'line_total_cents' => 31000,
        'line_notes' => 'Handle carefully',
    ]);

    $service = app(DeliveryNoteService::class);
    $first = $service->createFromInvoice($invoice, $this->user->id);
    $second = $service->createFromInvoice($invoice, $this->user->id);

    expect($first->id)->toBe($second->id)
        ->and($first->status)->toBe('issued')
        ->and($first->delivery_note_number)->toMatch('/^DN-\d{4}-\d{6}$/')
        ->and($first->source_invoice_id)->toBe($invoice->id)
        ->and($first->delivery_address_snapshot)->toBe('Customer delivery address')
        ->and($first->items)->toHaveCount(1)
        ->and($first->items->first()->qty)->toBe('2.500')
        ->and($first->items->first()->unit_price_cents)->toBe(12500)
        ->and(DeliveryNote::where('source_invoice_id', $invoice->id)->count())->toBe(1);
});

it('creates one draft invoice from an issued delivery note and preserves pricing', function () {
    $service = app(DeliveryNoteService::class);
    $note = $service->createDraft([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'delivery_date' => now()->toDateString(),
        'delivery_address' => 'West Bay',
        'reference' => 'PO-77',
        'notes' => 'Invoice later',
        'items' => [[
            'description' => 'Catering package',
            'qty' => '3.000',
            'unit' => 'package',
            'unit_price_cents' => 15000,
            'discount_cents' => 1000,
            'tax_cents' => 500,
            'line_notes' => 'Three deliveries',
        ]],
    ], $this->user->id);
    $note = $service->issue($note, $this->user->id);
    $first = $service->createInvoice($note, $this->user->id);
    $second = $service->createInvoice($note, $this->user->id);
    $draftStatus = $first->status;
    $first->update(['status' => 'issued', 'invoice_number' => 'INV-DN-1']);
    $sameNote = $service->createFromInvoice($first->fresh(), $this->user->id);

    expect($first->id)->toBe($second->id)
        ->and($draftStatus)->toBe('draft')
        ->and($first->source)->toBe('delivery_note')
        ->and($first->source_delivery_note_id)->toBe($note->id)
        ->and($first->items)->toHaveCount(1)
        ->and($first->items->first()->unit_price_cents)->toBe(15000)
        ->and($first->items->first()->line_total_cents)->toBe(44500)
        ->and($sameNote->id)->toBe($note->id)
        ->and(ArInvoice::where('source_delivery_note_id', $note->id)->count())->toBe(1);
});

it('rejects conversion before a delivery note is issued', function () {
    $note = app(DeliveryNoteService::class)->createDraft([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'delivery_date' => now()->toDateString(),
        'items' => [['description' => 'Draft item', 'qty' => 1, 'unit_price_cents' => 1000]],
    ], $this->user->id);

    expect(fn () => app(DeliveryNoteService::class)->createInvoice($note, $this->user->id))
        ->toThrow(ValidationException::class);
});

it('keeps issued delivery notes and their item snapshots immutable', function () {
    $service = app(DeliveryNoteService::class);
    $note = $service->createDraft([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'delivery_date' => now()->toDateString(),
        'items' => [['description' => 'Immutable item', 'qty' => 1, 'unit_price_cents' => 1000]],
    ], $this->user->id);
    $note = $service->issue($note, $this->user->id);

    expect(fn () => $note->update(['reference' => 'Changed']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $note->items->first()->update(['qty' => '2.000']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $note->delete())
        ->toThrow(ValidationException::class);
});

it('renders delivery note pages and protects another branch', function () {
    $note = app(DeliveryNoteService::class)->createDraft([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'delivery_date' => now()->toDateString(),
        'items' => [['description' => 'Visible item', 'qty' => 1, 'unit_price_cents' => 1000]],
    ], $this->user->id);
    $note = app(DeliveryNoteService::class)->issue($note, $this->user->id);

    $this->get(route('delivery-notes.index'))->assertOk()->assertSee('Delivery Notes');
    $this->get(route('delivery-notes.show', $note))->assertOk()->assertSee('Visible item');
    $this->get(route('delivery-notes.print', $note))->assertOk()->assertSee('DELIVERY NOTE')->assertDontSee('Invoice Price');

    $other = User::factory()->create(['status' => 'active'])->assignRole('manager');
    $this->actingAs($other);
    Volt::test('receivables.delivery-notes.show', ['deliveryNote' => $note])->assertForbidden();
});

it('generates a delivery note from the invoice page action', function () {
    $invoice = ArInvoice::factory()->issued()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'invoice_number' => 'INV-PAGE-1',
    ]);
    ArInvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'description' => 'Page action item']);

    Volt::test('receivables.invoices.show', ['invoice' => $invoice])
        ->assertSee(__('Generate Delivery Note'))
        ->call('generateDeliveryNote')
        ->assertRedirect();

    expect(DeliveryNote::where('source_invoice_id', $invoice->id)->where('status', 'issued')->count())->toBe(1);
});

it('creates a manual draft through the delivery note page', function () {
    Volt::test('receivables.delivery-notes.create')
        ->set('branch_id', $this->branch->id)
        ->set('customer_id', $this->customer->id)
        ->set('delivery_date', now()->toDateString())
        ->set('delivery_address', 'Manual address')
        ->set('items', [[
            'description' => 'Manual page item',
            'qty' => '2',
            'unit' => 'box',
            'unit_price' => '125.00',
            'line_notes' => 'Keep upright',
        ]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $note = DeliveryNote::with('items')->firstOrFail();
    expect($note->status)->toBe('draft')
        ->and($note->delivery_address_snapshot)->toBe('Manual address')
        ->and($note->items->first()->unit_price_cents)->toBe(12500);
});
