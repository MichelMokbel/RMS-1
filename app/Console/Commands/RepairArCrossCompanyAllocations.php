<?php

namespace App\Console\Commands;

use App\Models\PaymentAllocation;
use App\Services\AR\ArAllocationIntegrityService;
use Illuminate\Console\Command;

class RepairArCrossCompanyAllocations extends Command
{
    protected $signature = 'accounting:repair-ar-cross-company-allocations
        {--company-id= : Restrict detection to one accounting company}
        {--allocation-id=* : Specific allocation ids to repair}
        {--actor-id= : User id recorded on repairs}
        {--reason= : Optional reason for the repair}
        {--dry-run : Show affected allocations only}';

    protected $description = 'Detect and optionally repair cross-company AR payment allocations.';

    public function handle(ArAllocationIntegrityService $integrity): int
    {
        $companyId = $this->option('company-id') !== null ? (int) $this->option('company-id') : null;
        $allocationIds = collect((array) $this->option('allocation-id'))
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->values();
        $actorId = $this->option('actor-id') !== null
            ? (int) $this->option('actor-id')
            : ((int) config('app.system_user_id') ?: 1);
        $reason = $this->option('reason') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        $rows = $integrity->mismatchedAllocations($companyId);

        if ($allocationIds->isNotEmpty()) {
            $rows = $rows->filter(fn ($row) => $allocationIds->contains((int) $row['allocation']->id))->values();
        }

        if ($rows->isEmpty()) {
            $this->info('No cross-company AR allocations found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Allocation', 'Payment', 'Invoice', 'Customer', 'Payment Company', 'Invoice Company', 'Amount'],
            $rows->map(fn ($row) => [
                $row['allocation']->id,
                $row['payment']->id,
                $row['invoice']->id,
                $row['customer_name'] ?: '—',
                $row['payment_company_name'] ?: 'Unresolved',
                $row['invoice_company_name'] ?: 'Unresolved',
                number_format((float) $row['amount'], 2),
            ])->all()
        );

        if ($dryRun) {
            $this->comment('Dry run only. No allocations were changed.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            /** @var PaymentAllocation $allocation */
            $allocation = $row['allocation'];
            $integrity->repairAllocation($allocation, $actorId, $reason);
        }

        $this->info('Repaired allocations: '.$rows->count());

        return self::SUCCESS;
    }
}
