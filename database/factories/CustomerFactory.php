<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'customer_code' => $this->faker->bothify('CUST-###??'),
            'name' => $this->faker->company(),
            'customer_type' => Customer::TYPE_RETAIL,
            'contact_name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'billing_address' => $this->faker->address(),
            'delivery_address' => $this->faker->address(),
            'country' => $this->faker->country(),
            'default_payment_method_id' => null,
            'credit_limit' => 0,
            'credit_terms_days' => 0,
            'credit_status' => null,
            'is_active' => true,
            'notes' => $this->faker->sentence(),
        ];
    }

    public function corporate(): self
    {
        return $this->state(fn () => [
            'customer_type' => Customer::TYPE_CORPORATE,
            'credit_limit' => 5000,
            'credit_terms_days' => 30,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
