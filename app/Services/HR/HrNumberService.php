<?php

namespace App\Services\HR;

use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class HrNumberService
{
    public function __construct(protected DocumentSequenceService $sequences) {}

    public function employee(int $companyId, mixed $date = null): string
    {
        $year = $this->date($date)->format('Y');

        return "EMP-{$year}-".$this->padded('hr_employee', $companyId, $year, 6);
    }

    public function document(int $companyId, mixed $date = null): string
    {
        $year = $this->date($date)->format('Y');

        return "HRD-{$year}-".$this->padded('hr_document', $companyId, $year, 6);
    }

    public function payrollRun(int $companyId, mixed $periodStart): string
    {
        $date = $this->date($periodStart);
        $period = $date->format('Ym');

        return "PAY-{$period}-".$this->padded('hr_payroll_run_'.$date->format('m'), $companyId, $date->format('Y'), 4);
    }

    public function paymentBatch(int $companyId, mixed $paymentDate): string
    {
        $year = $this->date($paymentDate)->format('Y');

        return "PB-{$year}-".$this->padded('hr_payroll_payment_batch', $companyId, $year, 6);
    }

    private function padded(string $type, int $companyId, string $period, int $width): string
    {
        if ($companyId <= 0 || ! Schema::hasTable('document_sequences')) {
            throw new RuntimeException('The HR identifier sequence store is unavailable.');
        }

        return str_pad((string) $this->sequences->next($type, $companyId, $period), $width, '0', STR_PAD_LEFT);
    }

    private function date(mixed $date): Carbon
    {
        return $date ? Carbon::parse($date) : now();
    }
}
