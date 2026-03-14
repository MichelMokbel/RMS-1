<?php

namespace App\Http\Requests\AP;

use App\Support\AP\DocumentTypeMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ApInvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $supplierId = $this->input('supplier_id', $invoice?->supplier_id);

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'expense_channel' => ['nullable', 'in:vendor,petty_cash,reimbursement'],
            'wallet_id' => ['nullable', 'integer', 'exists:petty_cash_wallets,id'],
            'document_type' => ['required', Rule::in(DocumentTypeMap::types())],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')
                    ->where(fn ($q) => $q->where('supplier_id', $supplierId))
                    ->ignore($invoice?->id),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $documentType = DocumentTypeMap::normalizeDocumentType($this->input('document_type'));
            $expenseChannel = DocumentTypeMap::normalizeExpenseChannel($documentType, $this->input('expense_channel'));

            if (DocumentTypeMap::requiresCategory($documentType) && Schema::hasTable('expense_categories') && empty($this->category_id)) {
                $v->errors()->add('category_id', __('Category is required for expenses.'));
            }

            if (DocumentTypeMap::requiresWallet($documentType, $expenseChannel) && empty($this->wallet_id)) {
                $v->errors()->add('wallet_id', __('Wallet is required for petty cash expenses.'));
            }
        });
    }
}
