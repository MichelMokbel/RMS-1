<?php

namespace App\Services\PettyCash;

use App\Models\ExpenseCategory;
use App\Models\PettyCashExpense;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PettyCashQueryService
{
    public function wallets(string $activeFilter = 'active', string $search = ''): Collection
    {
        if (! Schema::hasTable('petty_cash_wallets')) {
            return collect();
        }

        return PettyCashWallet::query()
            ->when($activeFilter === 'active', fn ($q) => $q->where('active', 1))
            ->when($activeFilter === 'inactive', fn ($q) => $q->where('active', 0))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('driver_name', 'like', '%'.$search.'%')
                    ->orWhere('driver_id', 'like', '%'.$search.'%');
            }))
            ->orderBy('driver_name')
            ->get();
    }

    public function issues(?int $walletId = null, string $method = 'all', ?string $from = null, ?string $to = null): Collection
    {
        if (! Schema::hasTable('petty_cash_issues')) {
            return collect();
        }

        return PettyCashIssue::with(['wallet', 'voidedBy'])
            ->when($walletId, fn ($q) => $q->where('wallet_id', $walletId))
            ->when($method !== 'all', fn ($q) => $q->where('method', $method))
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get();
    }

    public function expenses(
        ?int $walletId = null,
        string $status = 'all',
        ?int $categoryId = null,
        ?string $from = null,
        ?string $to = null
    ): Collection {
        if (! Schema::hasTable('petty_cash_expenses')) {
            return collect();
        }

        return PettyCashExpense::with(['wallet', 'category', 'submitter', 'approver'])
            ->when($walletId, fn ($q) => $q->where('wallet_id', $walletId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to))
            ->orderByDesc('expense_date')
            ->limit(50)
            ->get();
    }

    public function reconciliations(?int $walletId = null): Collection
    {
        if (! Schema::hasTable('petty_cash_reconciliations')) {
            return collect();
        }

        return PettyCashReconciliation::with(['wallet', 'reconciler', 'voidedBy'])
            ->when($walletId, fn ($q) => $q->where('wallet_id', $walletId))
            ->orderByDesc('reconciled_at')
            ->limit(50)
            ->get();
    }

    public function categories(): Collection
    {
        if (! Schema::hasTable('expense_categories')) {
            return collect();
        }

        return ExpenseCategory::orderBy('name')->get();
    }
}

