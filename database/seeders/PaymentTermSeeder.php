<?php

namespace Database\Seeders;

use App\Models\PaymentTerm;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Immediate', 'days' => 0, 'is_credit' => false, 'is_active' => true],
            ['name' => 'Credit - 15 days', 'days' => 15, 'is_credit' => true, 'is_active' => true],
            ['name' => 'Credit - 30 days', 'days' => 30, 'is_credit' => true, 'is_active' => true],
            ['name' => 'Credit - 45 days', 'days' => 45, 'is_credit' => true, 'is_active' => true],
        ];

        foreach ($defaults as $row) {
            PaymentTerm::firstOrCreate(
                ['name' => $row['name']],
                $row
            );
        }
    }
}
