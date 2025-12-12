<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'contact_person' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'qid_cr' => $this->faker->bothify('QID-####'),
            'status' => 'active',
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
