<?php

namespace App\Http\Requests\Spend;

use Illuminate\Foundation\Http\FormRequest;

class SpendExpenseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'in:vendor,petty_cash,reimbursement'],
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'wallet_id' => ['nullable', 'integer', 'exists:petty_cash_wallets,id'],
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $channel = (string) $this->input('channel', 'vendor');
            $hasSupplier = $this->filled('supplier_id');
            $hasWallet = $this->filled('wallet_id');

            if ($channel === 'petty_cash') {
                if (! $hasWallet) {
                    $v->errors()->add('wallet_id', __('Wallet is required for petty cash channel.'));
                }

                if (! $hasSupplier && ! config('spend.petty_cash_internal_supplier_id')) {
                    $v->errors()->add('supplier_id', __('Supplier is required unless SPEND_PETTY_CASH_INTERNAL_SUPPLIER_ID is configured.'));
                }
            }

            if (in_array($channel, ['vendor', 'reimbursement'], true) && ! $hasSupplier) {
                $v->errors()->add('supplier_id', __('Supplier is required for this channel.'));
            }
        });
    }
}
