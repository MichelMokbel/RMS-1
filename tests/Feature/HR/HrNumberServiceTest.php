<?php

use App\Services\HR\HrNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('allocates company-scoped HR identifiers through the shared sequence store', function () {
    $numbers = app(HrNumberService::class);
    $company = 7711;

    expect($numbers->employee($company, '2026-08-12'))->toBe('EMP-2026-000001')
        ->and($numbers->employee($company, '2026-08-12'))->toBe('EMP-2026-000002')
        ->and($numbers->document($company, '2026-08-12'))->toBe('HRD-2026-000001')
        ->and($numbers->payrollRun($company, '2026-08-01'))->toBe('PAY-202608-0001')
        ->and($numbers->paymentBatch($company, '2026-08-31'))->toBe('PB-2026-000001');

    expect(DB::table('document_sequences')->where('branch_id', $company)
        ->where('type', 'hr_payroll_run_08')->where('year', '2026')->exists())->toBeTrue();
});

it('fails closed when the shared HR sequence store is unavailable', function () {
    Schema::rename('document_sequences', 'document_sequences_unavailable');
    try {
        expect(fn () => app(HrNumberService::class)->employee(7712, '2026-08-12'))
            ->toThrow(RuntimeException::class, 'sequence store is unavailable');
    } finally {
        Schema::rename('document_sequences_unavailable', 'document_sequences');
    }
});
