<?php

namespace Database\Factories;

use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        $companyId = AccountingCompany::query()->where('is_default', true)->value('id') ?? 1;

        return [
            'company_id' => $companyId,
            'ledger_account_id' => LedgerAccount::factory()->state(fn () => ['company_id' => $companyId]),
            'branch_id' => null,
            'name' => $this->faker->company().' Account',
            'code' => strtoupper($this->faker->unique()->bothify('BA-###')),
            'account_type' => 'checking',
            'bank_name' => $this->faker->company(),
            'account_number_last4' => (string) $this->faker->numerify('####'),
            'currency_code' => (string) config('pos.currency', 'QAR'),
            'is_default' => false,
            'is_active' => true,
            'opening_balance' => 0,
            'opening_balance_date' => now()->toDateString(),
        ];
    }
}

