<?php

namespace Database\Factories;

use App\Models\AccountingCompany;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    public function definition(): array
    {
        $companyId = AccountingCompany::query()->where('is_default', true)->value('id') ?? 1;

        return [
            'company_id' => $companyId,
            'code' => (string) $this->faker->unique()->numerify('9###'),
            'parent_account_id' => null,
            'name' => $this->faker->words(2, true),
            'type' => 'asset',
            'account_class' => 'asset',
            'detail_type' => null,
            'default_tax_code' => null,
            'is_active' => true,
            'allow_direct_posting' => true,
        ];
    }
}

