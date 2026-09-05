<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Quotations\QuotationDraftService;
use App\Services\Quotations\QuotationInvoiceConversionService;
use App\Services\Quotations\QuotationLifecycleService;
use App\Services\Quotations\QuotationTemplateService;
use App\Services\Quotations\Storage\QuotationAssetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-21 12:00:00'));

    Storage::fake('s3');
    config()->set('quotations.storage.disk', 's3');

    $this->actor = User::factory()->create(['status' => 'active']);
    $this->actor->assignRole(Role::findOrCreate('admin', 'web'));
    $this->company = AccountingCompany::query()->create([
        'name' => 'Quotation Company', 'code' => 'QUOTE-CO', 'base_currency' => 'QAR',
        'is_active' => true, 'is_default' => true,
    ]);
    $this->branch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Main Branch', 'code' => 'QB', 'is_active' => true,
    ]);
    $this->customer = Customer::factory()->corporate()->create();
});

function quotationPayload(object $test): array
{
    return [
        'branch_id' => $test->branch->id,
        'customer_id' => $test->customer->id,
        'recipient_name' => $test->customer->name,
        'recipient_contact_name' => $test->customer->contact_name,
        'recipient_email' => $test->customer->email,
        'recipient_phone' => $test->customer->phone,
        'recipient_address' => $test->customer->billing_address,
        'issue_date' => '2026-07-21',
        'valid_until' => '2026-08-20',
        'quotation_discount_type' => 'percentage',
        'quotation_discount_value' => 500,
        'items' => [[
            'description' => 'Corporate catering / تموين شركات', 'unit' => 'event',
            'quantity' => '2.500', 'unit_price_cents' => 10000, 'discount_cents' => 500,
        ]],
    ];
}

it('finalizes immutable artifacts, accepts, and converts exactly once from the accepted revision', function () {
    $draft = app(QuotationDraftService::class)->create($this->actor, quotationPayload($this));
    expect($draft->status)->toBe(Quotation::STATUS_DRAFT)
        ->and($draft->quotation_number)->toBeNull()
        ->and($draft->total_cents)->toBe(23275);

    $version = app(QuotationLifecycleService::class)->finalize($this->actor, $draft);
    $draft->refresh();

    expect($draft->status)->toBe(Quotation::STATUS_SENT)
        ->and($draft->quotation_number)->toBe('QT-2026-00001')
        ->and($draft->current_revision)->toBe(1)
        ->and($version->artifacts)->toHaveCount(2);

    foreach ($version->artifacts as $artifact) {
        expect($artifact->generation_status)->toBe('ready')
            ->and($artifact->storage_key)->toStartWith("quotations/{$this->company->id}/{$draft->id}/versions/1/");
        Storage::disk('s3')->assertExists($artifact->storage_key);
    }

    actingAs($this->actor)
        ->get(route('quotations.preview', [$draft, $version]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('Corporate catering / تموين شركات', false);

    expect(fn () => $version->update(['total_cents' => 1]))->toThrow(LogicException::class);
    $version->refresh();

    $accepted = app(QuotationLifecycleService::class)->markAccepted($this->actor, $draft->fresh(), 'Approved offline');
    $otherCustomer = Customer::factory()->corporate()->create();
    $first = app(QuotationInvoiceConversionService::class)->convert($this->actor, $accepted, $otherCustomer->id);
    $second = app(QuotationInvoiceConversionService::class)->convert($this->actor, $accepted->fresh(), $this->customer->id);

    expect($first->id)->toBe($second->id)
        ->and($first->status)->toBe('draft')
        ->and($first->currency)->toBe('QAR')
        ->and($first->customer_id)->toBe($this->customer->id)
        ->and($first->tax_total_cents)->toBe(0)
        ->and($first->total_cents)->toBe($version->total_cents)
        ->and($first->source_quotation_version_id)->toBe($version->id)
        ->and(ArInvoice::query()->where('source_quotation_id', $draft->id)->count())->toBe(1)
        ->and($accepted->fresh()->status)->toBe(Quotation::STATUS_ACCEPTED);
});

it('rejects a template version owned by another company', function () {
    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Company', 'code' => 'OTHER-QUOTE', 'base_currency' => 'QAR',
        'is_active' => true, 'is_default' => false,
    ]);
    $foreignVersion = app(QuotationTemplateService::class)->ensureDefault($otherCompany, $this->actor)->currentVersion;
    $payload = quotationPayload($this);
    $payload['template_version_id'] = $foreignVersion->id;

    expect(fn () => app(QuotationDraftService::class)->create($this->actor, $payload))
        ->toThrow(ValidationException::class);
});

it('requires an active customer selection when converting a prospect quotation', function () {
    $payload = quotationPayload($this);
    $payload['customer_id'] = null;
    $payload['recipient_name'] = 'Prospect Company';
    $draft = app(QuotationDraftService::class)->create($this->actor, $payload);
    app(QuotationLifecycleService::class)->finalize($this->actor, $draft);
    $accepted = app(QuotationLifecycleService::class)->markAccepted($this->actor, $draft->fresh());

    expect(fn () => app(QuotationInvoiceConversionService::class)->convert($this->actor, $accepted))
        ->toThrow(ValidationException::class);

    $invoice = app(QuotationInvoiceConversionService::class)->convert($this->actor, $accepted->fresh(), $this->customer->id);
    expect($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->source_quotation_id)->toBe($draft->id);
});

it('enforces authentication, quotation permission, and explicit branch access on screens', function () {
    $quotation = app(QuotationDraftService::class)->create($this->actor, quotationPayload($this));

    get(route('quotations.index'))->assertRedirect();

    $unprivileged = User::factory()->create(['status' => 'active']);
    actingAs($unprivileged)->get(route('quotations.index'))->assertForbidden();

    actingAs($this->actor)->get(route('quotations.index'))->assertOk();

    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id, 'name' => 'Other Branch', 'code' => 'QB2', 'is_active' => true,
    ]);
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole(Role::findOrCreate('cashier', 'web'));
    $cashier->givePermissionTo(Permission::findOrCreate('quotations.access', 'web'));
    DB::table('user_branch_access')->insert([
        'user_id' => $cashier->id, 'branch_id' => $otherBranch->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($cashier)->get(route('quotations.show', $quotation))->assertForbidden();
});

it('stores validated reusable images privately with content checksums', function () {
    $asset = app(QuotationAssetService::class)->storeImage(
        $this->company->id,
        UploadedFile::fake()->image('brand.png', 120, 80),
        $this->actor->id,
    );

    Storage::disk('s3')->assertExists($asset->storage_key);
    $stored = Storage::disk('s3')->get($asset->storage_key);

    expect($asset->storage_key)->toStartWith("quotations/{$this->company->id}/assets/")
        ->and($asset->mime_type)->toBe('image/png')
        ->and($asset->checksum_sha256)->toBe(hash('sha256', $stored))
        ->and($asset->uploaded_by)->toBe($this->actor->id);
});
