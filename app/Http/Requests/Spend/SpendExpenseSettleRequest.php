<?php

namespace App\Http\Requests\Spend;

use Illuminate\Foundation\Http\FormRequest;

class SpendExpenseSettleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'in:cash,bank_transfer,card,cheque,other,petty_cash'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
