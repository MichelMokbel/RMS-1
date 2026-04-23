<?php

use App\Models\AccountingCompany;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_companies')
            || ! Schema::hasTable('fiscal_years')
            || ! Schema::hasTable('accounting_periods')) {
            return;
        }

        $years = [now()->year, now()->addYear()->year];

        AccountingCompany::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id'])
            ->each(function (AccountingCompany $company) use ($years): void {
                foreach ($years as $year) {
                    $fyId = DB::table('fiscal_years')
                        ->where('company_id', $company->id)
                        ->whereDate('start_date', Carbon::create($year, 1, 1)->toDateString())
                        ->value('id');

                    if (! $fyId) {
                        $fyId = DB::table('fiscal_years')->insertGetId([
                            'company_id' => $company->id,
                            'name' => 'FY '.$year,
                            'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                            'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                            'status' => 'open',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    for ($month = 1; $month <= 12; $month++) {
                        $start = Carbon::create($year, $month, 1)->startOfMonth();
                        $end = $start->copy()->endOfMonth();

                        $exists = DB::table('accounting_periods')
                            ->where('company_id', $company->id)
                            ->where('fiscal_year_id', $fyId)
                            ->where('period_number', $month)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        DB::table('accounting_periods')->insert([
                            'company_id' => $company->id,
                            'fiscal_year_id' => $fyId,
                            'name' => $start->format('M Y'),
                            'period_number' => $month,
                            'start_date' => $start->toDateString(),
                            'end_date' => $end->toDateString(),
                            'status' => 'open',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally no-op: these are canonical accounting periods and should not be removed.
    }
};
