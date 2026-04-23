<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingCompany extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (AccountingCompany $company) {
            self::ensureOperationalPeriods($company);
        });
    }

    protected $fillable = [
        'name',
        'code',
        'base_currency',
        'is_active',
        'is_default',
        'parent_company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class, 'company_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'company_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'company_id');
    }

    public static function ensureOperationalPeriods(AccountingCompany $company, ?array $years = null): void
    {
        if (! Schema::hasTable('fiscal_years') || ! Schema::hasTable('accounting_periods')) {
            return;
        }

        foreach ($years ?? [now()->year, now()->addYear()->year] as $year) {
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
    }
}
