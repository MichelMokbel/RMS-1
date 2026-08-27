<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Spend\ExpenseWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApExpenseCreationService
{
    public function __construct(
        protected ApInvoiceTotalsService $totals,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected SupplierAccountingPolicyService $supplierPolicy,
        protected ExpenseWorkflowService $workflow,
    ) {}

    /**
     * Create a canonical AP expense draft and its expense profile.
     *
     * The method participates in an existing transaction when one is open.
     * It deliberately does not approve, post, or settle the expense.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, int $actorId): ApInvoice
    {
        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $expenseChannel = (string) ($payload['expense_channel'] ?? (filled($payload['wallet_id'] ?? null) ? 'petty_cash' : 'vendor'));
        $data = Validator::make($payload, [
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'expense_channel' => ['nullable', Rule::in(['petty_cash', 'vendor'])],
            'wallet_id' => [Rule::requiredIf($expenseChannel === 'petty_cash'), 'nullable', 'integer', 'exists:petty_cash_wallets,id'],
            'invoice_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ap_invoices', 'invoice_number')
                    ->where(fn ($query) => $query->where('supplier_id', $supplierId)),
            ],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'tax_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'source_document_type' => ['nullable', 'string', 'max:50'],
            'source_document_id' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ])->validate();

        $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
        if ($supplier->company_id && (int) $supplier->company_id !== (int) $data['company_id']) {
            throw ValidationException::withMessages([
                'supplier_id' => __('The supplier does not belong to the selected company.'),
            ]);
        }
        if (! $supplier->isActive()) {
            throw ValidationException::withMessages([
                'supplier_id' => __('The supplier is inactive.'),
            ]);
        }
        $this->supplierPolicy->assertCanCreateDraft($supplier);

        $category = ExpenseCategory::query()->findOrFail((int) $data['category_id']);
        if (! $category->active) {
            throw ValidationException::withMessages(['category_id' => __('The expense category is inactive.')]);
        }

        if ($expenseChannel === 'petty_cash') {
            $wallet = PettyCashWallet::query()->findOrFail((int) $data['wallet_id']);
            if (! $wallet->isActive()) {
                throw ValidationException::withMessages(['wallet_id' => __('The petty cash wallet is inactive.')]);
            }
        }

        $companyId = $this->accountingContext->resolveCompanyId(
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            (int) $data['company_id']
        );
        $periodId = $this->accountingContext->resolvePeriodId((string) $data['invoice_date'], $companyId);
        $this->periodGate->assertDateOpen(
            (string) $data['invoice_date'],
            $companyId,
            $periodId,
            'ap',
            'invoice_date'
        );

        return DB::transaction(function () use ($data, $actorId, $companyId, $periodId, $expenseChannel): ApInvoice {
            $attributes = [
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'period_id' => $periodId,
                'supplier_id' => $data['supplier_id'],
                'category_id' => $data['category_id'],
                'is_expense' => true,
                'document_type' => 'expense',
                'currency_code' => $data['currency_code'] ?? config('pos.currency', 'QAR'),
                'source_document_type' => $data['source_document_type'] ?? null,
                'source_document_id' => $data['source_document_id'] ?? null,
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'tax_amount' => round((float) $data['tax_amount'], 2),
                'total_amount' => 0,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ];
            if (Schema::hasColumn('ap_invoices', 'reference_number')) {
                $attributes['reference_number'] = $data['reference_number'] ?? null;
            }

            $invoice = ApInvoice::query()->create($attributes);
            foreach ($data['items'] as $item) {
                ApInvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'purchase_order_item_id' => null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                ]);
            }

            $invoice = $this->totals->recalc($invoice);
            $this->workflow->initializeProfile(
                $invoice,
                $expenseChannel,
                $expenseChannel === 'petty_cash' ? (int) $data['wallet_id'] : null
            );

            return $invoice->fresh(['items', 'expenseProfile.wallet', 'supplier']);
        });
    }
}
