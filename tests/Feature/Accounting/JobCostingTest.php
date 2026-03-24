<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Job;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\JobCostingService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('tracks job profitability from recorded transactions', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->orderBy('id')->firstOrFail();

    $jobResponse = $this->actingAs($this->user)->postJson(route('api.accounting.jobs.store'), [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Kitchen Refit',
        'code' => 'JOB-KITCHEN',
        'status' => 'active',
        'estimated_revenue' => 500,
        'estimated_cost' => 250,
    ])->assertCreated();

    $jobId = (int) $jobResponse->json('job.id');

    $this->actingAs($this->user)->postJson(route('api.accounting.jobs.transactions.store', ['job' => $jobId]), [
        'transaction_date' => '2026-03-11',
        'amount' => 100,
        'transaction_type' => 'cost',
        'memo' => 'Labour',
    ])->assertCreated();

    $this->actingAs($this->user)->postJson(route('api.accounting.jobs.transactions.store', ['job' => $jobId]), [
        'transaction_date' => '2026-03-12',
        'amount' => 250,
        'transaction_type' => 'revenue',
        'memo' => 'Milestone billing',
    ])->assertCreated();

    $this->actingAs($this->user)->getJson(route('api.accounting.jobs.profitability', ['job' => $jobId]))
        ->assertOk()
        ->assertJsonPath('actual_cost', 100)
        ->assertJsonPath('actual_revenue', 250)
        ->assertJsonPath('actual_margin', 150);

    $this->actingAs($this->user)
        ->get(route('reports.accounting-job-profitability'))
        ->assertOk()
        ->assertSee('Job Profitability');
});

it('groups profitability by cost code and records inventory sourced job costs from po receipts', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->orderBy('id')->firstOrFail();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);
    $item = InventoryItem::factory()->create(['supplier_id' => $supplier->id, 'cost_per_unit' => 10]);

    $service = app(JobCostingService::class);

    $job = $service->createJob([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Fitout',
        'code' => 'JOB-FITOUT',
        'status' => 'active',
    ], $this->user->id);

    $phase = $service->savePhase($job, [
        'name' => 'Install',
        'code' => 'INSTALL',
        'status' => 'active',
    ], $this->user->id);

    $costCode = $service->saveCostCode($company->id, [
        'name' => 'Materials',
        'code' => 'MAT',
        'is_active' => true,
    ], $this->user->id);

    $service->saveBudget($job, [
        'job_phase_id' => $phase->id,
        'job_cost_code_id' => $costCode->id,
        'budget_amount' => 300,
    ], $this->user->id);

    $service->recordTransaction($job, [
        'transaction_date' => '2026-03-12',
        'amount' => 125,
        'transaction_type' => 'cost',
        'job_phase_id' => $phase->id,
        'job_cost_code_id' => $costCode->id,
        'memo' => 'Manual material issue',
    ], $this->user->id);

    $profitability = $service->profitability($job->fresh());

    expect($profitability['cost_code_breakdown'])->toHaveCount(1)
        ->and($profitability['cost_code_breakdown'][0]['cost_code'])->toBe('MAT')
        ->and((float) $profitability['cost_code_breakdown'][0]['budget_total'])->toBe(300.0);

    $po = PurchaseOrder::factory()->approved()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
        'job_id' => $job->id,
        'status' => PurchaseOrder::STATUS_APPROVED,
    ]);

    $line = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'item_id' => $item->id,
        'quantity' => 5,
        'unit_price' => 10,
        'total_price' => 50,
        'received_quantity' => 0,
    ]);

    app(PurchaseOrderReceivingService::class)->receive($po->fresh('job'), [$line->id => 5], $this->user->id);

    expect(Job::query()->findOrFail($job->id)->transactions()->where('source_type', PurchaseOrder::class)->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->get(route('accounting.jobs', ['job' => $job->id, 'tab' => 'transactions']))
        ->assertOk()
        ->assertSee('Cost Code Breakdown')
        ->assertSee('PurchaseOrder');
});
