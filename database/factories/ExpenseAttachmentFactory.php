<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseAttachmentFactory extends Factory
{
    protected $model = ExpenseAttachment::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'file_path' => 'expenses/sample.pdf',
            'original_name' => 'sample.pdf',
        ];
    }
}
