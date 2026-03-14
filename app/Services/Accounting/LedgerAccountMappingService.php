<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LedgerAccountMappingService
{
    /**
     * @return array<string, array{label:string, description:string, required:bool, fallback:string|null}>
     */
    public function definitions(): array
    {
        return [
            'cash' => ['label' => 'Cash account', 'description' => 'Used for physical cash receipts and payments.', 'required' => true, 'fallback' => 'cash'],
            'petty_cash_asset' => ['label' => 'Petty cash account', 'description' => 'Tracks petty cash wallet funding and reductions.', 'required' => true, 'fallback' => 'petty_cash_asset'],
            'expense_default' => ['label' => 'Default expense account', 'description' => 'Fallback expense account when no supplier-specific account is set.', 'required' => true, 'fallback' => 'expense_default'],
            'ap_control' => ['label' => 'Accounts payable control', 'description' => 'Offset account for vendor bills and supplier settlements.', 'required' => true, 'fallback' => 'ap_control'],
            'ap_prepay' => ['label' => 'Supplier advances', 'description' => 'Tracks unapplied supplier prepayments.', 'required' => true, 'fallback' => 'ap_prepay'],
            'ar_control' => ['label' => 'Accounts receivable control', 'description' => 'Offset account for customer invoices and receipts.', 'required' => true, 'fallback' => 'ar_control'],
            'customer_advances' => ['label' => 'Customer advances', 'description' => 'Tracks unapplied customer receipts.', 'required' => true, 'fallback' => 'customer_advances'],
            'sales_revenue' => ['label' => 'Sales revenue', 'description' => 'Revenue account used for AR invoice posting.', 'required' => true, 'fallback' => 'sales_revenue'],
            'tax_input' => ['label' => 'Input tax', 'description' => 'Tax input account for AP documents.', 'required' => false, 'fallback' => 'tax_input'],
            'tax_output' => ['label' => 'Output tax', 'description' => 'Tax output account for AR documents.', 'required' => false, 'fallback' => 'tax_output'],
            'inventory_asset' => ['label' => 'Inventory asset', 'description' => 'Inventory balance sheet account.', 'required' => false, 'fallback' => 'inventory_asset'],
            'grni' => ['label' => 'GRNI clearing', 'description' => 'Goods received not invoiced clearing account.', 'required' => false, 'fallback' => 'grni'],
            'cogs' => ['label' => 'Cost of goods sold', 'description' => 'Used for inventory consumption and recipe output.', 'required' => false, 'fallback' => 'cogs'],
            'inventory_adjustment' => ['label' => 'Inventory adjustments', 'description' => 'Used for manual inventory adjustments.', 'required' => false, 'fallback' => 'inventory_adjustment'],
            'petty_cash_over_short' => ['label' => 'Petty cash over/short', 'description' => 'Variance account for petty cash reconciliation.', 'required' => false, 'fallback' => 'petty_cash_over_short'],
            'card_clearing' => ['label' => 'Card clearing', 'description' => 'Tracks supplier/customer card settlements outside cash and bank.', 'required' => true, 'fallback' => 'card_clearing'],
            'cheque_clearing' => ['label' => 'Cheque clearing', 'description' => 'Tracks cheque settlements until fully cleared.', 'required' => true, 'fallback' => 'cheque_clearing'],
            'other_clearing' => ['label' => 'Other clearing', 'description' => 'Fallback settlement account for unsupported payment instruments.', 'required' => true, 'fallback' => 'other_clearing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return [
            'ap_invoice_expense' => 'expense_default',
            'ap_invoice_inventory' => 'inventory_asset',
            'ap_invoice_ap' => 'ap_control',
            'ap_invoice_tax' => 'tax_input',
            'inventory_asset' => 'inventory_asset',
            'inventory_clearing' => 'grni',
            'inventory_adjustment' => 'inventory_adjustment',
            'inventory_cogs' => 'cogs',
            'ap_payment_cash' => 'cash',
            'ap_payment_prepay' => 'ap_prepay',
            'ar_invoice_ar' => 'ar_control',
            'ar_invoice_revenue' => 'sales_revenue',
            'ar_invoice_tax' => 'tax_output',
            'ar_payment_cash' => 'cash',
            'ar_payment_advance' => 'customer_advances',
            'expense_cash' => 'cash',
            'petty_cash_asset' => 'petty_cash_asset',
            'petty_cash_issue_cash' => 'cash',
            'petty_cash_expense' => 'expense_default',
            'petty_cash_over_short' => 'petty_cash_over_short',
            'ap_payment_card' => 'card_clearing',
            'ar_payment_card' => 'card_clearing',
            'ap_payment_cheque' => 'cheque_clearing',
            'ar_payment_cheque' => 'cheque_clearing',
            'ap_payment_other' => 'other_clearing',
            'ar_payment_other' => 'other_clearing',
        ];
    }

    public function normalizeKey(string $key): string
    {
        $aliases = $this->aliases();

        return $aliases[$key] ?? $key;
    }

    public function bootstrapCompanyMappings(?int $companyId): void
    {
        if (! $companyId || ! Schema::hasTable('accounting_account_mappings') || ! Schema::hasTable('ledger_accounts')) {
            return;
        }

        foreach ($this->definitions() as $mappingKey => $definition) {
            $existing = AccountingAccountMapping::query()
                ->where('company_id', $companyId)
                ->where('mapping_key', $mappingKey)
                ->exists();

            if ($existing) {
                continue;
            }

            $account = $this->defaultAccountForKey($companyId, $definition['fallback']);
            if (! $account) {
                continue;
            }

            AccountingAccountMapping::query()->create([
                'company_id' => $companyId,
                'mapping_key' => $mappingKey,
                'ledger_account_id' => $account->id,
            ]);
        }
    }

    public function resolveAccountId(string $key, ?int $companyId = null): ?int
    {
        $account = $this->resolveAccount($key, $companyId);

        return $account?->id ? (int) $account->id : null;
    }

    public function resolveAccount(string $key, ?int $companyId = null): ?LedgerAccount
    {
        $normalizedKey = $this->normalizeKey($key);
        $this->bootstrapCompanyMappings($companyId);

        if (Schema::hasTable('accounting_account_mappings') && $companyId) {
            $account = AccountingAccountMapping::query()
                ->with('ledgerAccount')
                ->where('company_id', $companyId)
                ->where('mapping_key', $normalizedKey)
                ->first()
                ?->ledgerAccount;

            if ($account) {
                return $account;
            }
        }

        return $this->defaultAccountForKey($companyId, $normalizedKey);
    }

    public function resolveSettlementAccountId(string $method, ?int $companyId = null, ?int $bankAccountId = null): ?int
    {
        $normalized = $this->normalizePaymentMethod($method);

        if ($normalized === 'bank_transfer') {
            $bankAccount = $this->resolveBankAccount($bankAccountId, $companyId);
            if (! $bankAccount || ! $bankAccount->ledger_account_id) {
                throw ValidationException::withMessages([
                    'bank_account_id' => __('A linked bank account ledger is required for bank transfers.'),
                ]);
            }

            return (int) $bankAccount->ledger_account_id;
        }

        return match ($normalized) {
            'cash' => $this->resolveAccountId('cash', $companyId),
            'card' => $this->resolveAccountId('card_clearing', $companyId),
            'cheque' => $this->resolveAccountId('cheque_clearing', $companyId),
            'petty_cash' => $this->resolveAccountId('petty_cash_asset', $companyId),
            default => $this->resolveAccountId('other_clearing', $companyId),
        };
    }

    public function resolveBankAccount(?int $bankAccountId = null, ?int $companyId = null): ?BankAccount
    {
        if (! Schema::hasTable('bank_accounts')) {
            return null;
        }

        $query = BankAccount::query()->where('is_active', true);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($bankAccountId && $bankAccountId > 0) {
            return $query->whereKey($bankAccountId)->first();
        }

        return $query->where('is_default', true)->first();
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function assertRequiredMappings(?int $companyId, array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            $normalized = $this->normalizeKey($key);
            if (! $this->resolveAccountId($normalized, $companyId)) {
                $missing[] = $this->definitions()[$normalized]['label'] ?? $normalized;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'account_mappings' => __('Required posting mappings are missing: :items', ['items' => implode(', ', $missing)]),
            ]);
        }
    }

    /**
     * @return Collection<int, AccountingAccountMapping>
     */
    public function mappingsForCompany(?int $companyId): Collection
    {
        if (! $companyId || ! Schema::hasTable('accounting_account_mappings')) {
            return new Collection();
        }

        $this->bootstrapCompanyMappings($companyId);

        return AccountingAccountMapping::query()
            ->with('ledgerAccount')
            ->where('company_id', $companyId)
            ->orderBy('mapping_key')
            ->get();
    }

    public function normalizePaymentMethod(?string $method): string
    {
        $method = strtolower(trim((string) $method));

        return match ($method) {
            '', 'bank' => 'bank_transfer',
            default => $method,
        };
    }

    private function defaultAccountForKey(?int $companyId, ?string $key): ?LedgerAccount
    {
        if (! $key || ! Schema::hasTable('ledger_accounts')) {
            return null;
        }

        $config = Config::get('ledger.accounts', []);
        $meta = $config[$key] ?? null;
        $code = $meta['code'] ?? $key;

        $query = LedgerAccount::query()->where('code', $code);
        if ($companyId) {
            $query->where(function ($builder) use ($companyId) {
                $builder->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        $account = $query->orderByRaw('case when company_id is null then 1 else 0 end')->first();
        if ($account) {
            return $account;
        }

        if (! $meta) {
            return null;
        }

        return LedgerAccount::query()->create([
            'company_id' => $companyId ?: AccountingCompany::query()->where('is_default', true)->value('id'),
            'code' => $code,
            'name' => $meta['name'] ?? $code,
            'type' => $meta['type'] ?? 'asset',
            'account_class' => $meta['type'] ?? 'asset',
            'detail_type' => $meta['detail_type'] ?? null,
            'is_active' => true,
            'allow_direct_posting' => true,
        ]);
    }
}
