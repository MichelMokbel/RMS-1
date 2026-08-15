<?php

namespace Database\Factories;

use App\Enums\HR\EmployeeStatus;
use App\Models\AccountingCompany;
use App\Models\HrEmployee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HrEmployee> */
class HrEmployeeFactory extends Factory
{
    protected $model = HrEmployee::class;

    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'company_id' => fn () => AccountingCompany::query()->value('id')
                ?? AccountingCompany::query()->create([
                    'name' => 'Test Company',
                    'code' => 'TEST-'.fake()->unique()->numerify('####'),
                    'base_currency' => 'QAR',
                    'is_active' => true,
                    'is_default' => false,
                ])->id,
            'employee_number' => fake()->unique()->numerify('EMP-#####'),
            'legal_first_name' => $firstName,
            'legal_last_name' => $lastName,
            'display_name' => $firstName.' '.$lastName,
            'employment_type' => 'full_time',
            'employment_status' => EmployeeStatus::Active,
            'hire_date' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
        ];
    }

    public function onboarding(): static
    {
        return $this->state(fn () => ['employment_status' => EmployeeStatus::Onboarding]);
    }

    public function exited(): static
    {
        return $this->state(fn () => [
            'employment_status' => EmployeeStatus::Exited,
            'exit_date' => now()->toDateString(),
        ]);
    }
}
