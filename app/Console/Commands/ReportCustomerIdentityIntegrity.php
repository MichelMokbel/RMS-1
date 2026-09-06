<?php

namespace App\Console\Commands;

use App\Services\Customers\CustomerIdentityIntegrityService;
use Illuminate\Console\Command;

class ReportCustomerIdentityIntegrity extends Command
{
    protected $signature = 'customers:identity-report {--details=5 : Maximum sample identifiers per check}';

    protected $description = 'Read only report for customer identity, merge, phone proof, and reference integrity.';

    public function handle(CustomerIdentityIntegrityService $integrity): int
    {
        $result = $integrity->report((int) $this->option('details'));
        $rows = collect($result['checks'])->map(fn (array $check, string $code): array => [
            $code,
            $check['count'] === 0 ? 'OK' : 'REVIEW',
            $check['count'],
            implode(', ', array_map('strval', $check['samples'])),
        ])->values()->all();

        $this->table(['Check', 'Status', 'Count', 'Samples'], $rows);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
