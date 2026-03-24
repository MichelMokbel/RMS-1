<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AP\RecurringBillService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('creates recurring bill templates and generates one draft per run date', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);

    $service = app(RecurringBillService::class);

    $template = $service->saveTemplate([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'name' => 'Monthly Cleaning',
        'frequency' => 'monthly',
        'start_date' => '2026-03-01',
        'next_run_date' => '2026-03-24',
        'due_day_offset' => 15,
        'is_active' => true,
        'lines' => [
            [
                'description' => 'Deep cleaning service',
                'quantity' => 1,
                'unit_price' => 250,
            ],
        ],
    ], $this->user->id);

    expect($template->lines)->toHaveCount(1);

    $generated = $service->generateTemplate($template, '2026-03-24', $this->user->id);
    $duplicate = $service->generateTemplate($template->fresh(), '2026-03-24', $this->user->id);

    expect($generated->id)->toBe($duplicate->id)
        ->and($generated->document_type)->toBe('recurring_bill')
        ->and((float) $generated->total_amount)->toBe(250.0);

    expect(ApInvoice::query()->where('recurring_template_id', $template->id)->count())->toBe(1);

    $this->actingAs($this->user)
        ->get(route('payables.index', ['tab' => 'recurring']))
        ->assertOk()
        ->assertSee('Recurring Bills')
        ->assertSee('Monthly Cleaning')
        ->assertSee($company->name)
        ->assertSee($generated->invoice_number);
});

it('dispatches scheduled recurring bill generation only once for due templates', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);

    app(RecurringBillService::class)->saveTemplate([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'name' => 'Weekly Linen',
        'frequency' => 'weekly',
        'start_date' => '2026-03-01',
        'next_run_date' => '2026-03-24',
        'due_day_offset' => 7,
        'is_active' => true,
        'lines' => [
            ['description' => 'Linen service', 'quantity' => 1, 'unit_price' => 90],
        ],
    ], $this->user->id);

    Artisan::call('accounting:generate-recurring-bills', ['--date' => '2026-03-24', '--actor-id' => $this->user->id]);
    Artisan::call('accounting:generate-recurring-bills', ['--date' => '2026-03-24', '--actor-id' => $this->user->id]);

    expect(ApInvoice::query()->where('document_type', 'recurring_bill')->count())->toBe(1);
});
