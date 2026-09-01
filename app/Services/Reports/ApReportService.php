<?php

namespace App\Services\Reports;

use App\Models\AccountingCompany;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ApReportService
{
    public const JOURNAL_SOURCES = ['ap_invoice', 'ap_payment', 'ap_payment_allocation', 'ap_cheque_clearance'];

    public function __construct(private readonly BranchAccessService $branchAccess) {}

    public function companies(User $user)
    {
        return AccountingCompany::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->whereIn('id', DB::table('branches')
                ->whereIn('id', $this->branchAccess->allowedBranchIds($user))->select('company_id')))
            ->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function company(User $user, ?int $companyId): AccountingCompany
    {
        $companies = $this->companies($user);
        $company = $companyId ? $companies->firstWhere('id', $companyId) : $companies->first();
        abort_unless($company, 403);

        return $company;
    }

    public function expensesByCategory(int $companyId, array $filters, User $actor): array
    {
        $query = DB::table('ap_invoices as i')
            ->leftJoin('expense_categories as c', 'c.id', '=', 'i.category_id')
            ->where('i.company_id', $companyId)
            ->where('i.is_expense', true)
            ->whereIn('i.status', ['posted', 'partially_paid', 'paid'])
            ->whereNull('i.voided_at')
            ->whereBetween('i.invoice_date', [$filters['date_from'], $filters['date_to']]);
        $this->scope($query, 'i', $companyId, $filters, $actor);

        $rows = $query->selectRaw('i.category_id, c.name as category, i.currency_code, COUNT(*) as invoice_count, SUM(i.subtotal) as subtotal, SUM(i.tax_amount) as tax, SUM(i.total_amount) as total')
            ->groupBy('i.category_id', 'c.name', 'i.currency_code')
            ->orderBy('c.name')->orderBy('i.currency_code')->get();

        return [
            'title' => __('Expenses by Category'),
            'description' => __('Posted expenses by invoice date, including paid and partially paid expenses. Draft and void expenses are excluded.'),
            'headers' => [__('Category'), __('Currency'), __('Invoices'), __('Subtotal'), __('Tax'), __('Total')],
            'rows' => $rows->map(fn ($row) => [$row->category ?: __('Uncategorized'), $row->currency_code, (int) $row->invoice_count,
                $this->money($row->subtotal, 1000), $this->money($row->tax, 1000), $this->money($row->total, 1000)])->all(),
            'totals' => $rows->groupBy('currency_code')->map(fn ($group, $currency) => [__('Total'), $currency, $group->sum('invoice_count'),
                $this->sum($group, 'subtotal', 1000), $this->sum($group, 'tax', 1000), $this->sum($group, 'total', 1000)])->values()->all(),
        ];
    }

    public function journal(int $companyId, array $filters, ?User $actor = null): array
    {
        $query = DB::table('subledger_entries as e')
            ->join('subledger_lines as l', 'l.entry_id', '=', 'e.id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.company_id', $companyId)
            ->whereIn('e.source_type', self::JOURNAL_SOURCES)
            ->where('e.status', 'posted')
            ->whereNull('e.voided_at')
            ->whereBetween('e.entry_date', [$filters['date_from'], $filters['date_to']]);
        $this->scope($query, 'e', $companyId, $filters, $actor);

        $rows = $query->select(['e.id', 'e.entry_date', 'e.posted_at', 'e.event', 'e.source_type', 'e.source_id',
            'e.description', 'e.currency_code', 'b.name as branch', 'a.code', 'a.name as account', 'l.debit', 'l.credit'])
            ->orderBy('e.posted_at')->orderBy('e.id')->orderBy('l.id')->get();

        return [
            'title' => __('AP Journal Entries'),
            'description' => __('Posted AP activity by accounting calendar date, including original entries and their reversal entries.'),
            'headers' => [__('Entry'), __('Accounting date'), __('Posted at'), __('Event'), __('Source'), __('Description'), __('Branch'), __('Account'), __('Currency'), __('Debit'), __('Credit')],
            'rows' => $rows->map(fn ($row) => [$row->id, $row->entry_date, $row->posted_at, $row->event,
                $row->source_type.':'.$row->source_id, $row->description, $row->branch, $row->code.' '.$row->account,
                $row->currency_code, $this->money($row->debit, 10000), $this->money($row->credit, 10000)])->all(),
            'totals' => $rows->groupBy('currency_code')->map(fn ($group, $currency) => [__('Total'), '', '', '', '', '', '', '', $currency,
                $this->sum($group, 'debit', 10000), $this->sum($group, 'credit', 10000)])->values()->all(),
        ];
    }

    private function scope(Builder $query, string $alias, int $companyId, array $filters, ?User $actor): void
    {
        if ($actor) {
            $this->company($actor, $companyId);
            $this->branchAccess->applyBranchScope($query, $actor, $alias.'.branch_id');
            // Exclude malformed cross-company branch references as well as unauthorized branches.
            if (! $actor->isAdmin()) {
                $query->whereIn($alias.'.branch_id', DB::table('branches')->where('company_id', $companyId)->select('id'));
            }
        }
        if (! empty($filters['branch_id'])) {
            if ($actor) {
                abort_unless($this->branchAccess->canAccessBranch($actor, (int) $filters['branch_id']), 403);
            }
            if (! DB::table('branches')->where('id', (int) $filters['branch_id'])->where('company_id', $companyId)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['branch_id' => __('Select a branch belonging to the report company.')]);
            }
            $query->where($alias.'.branch_id', (int) $filters['branch_id']);
        }
    }

    private function money(string $amount, int $scale): string
    {
        return MinorUnits::format(MinorUnits::parse($amount, $scale), $scale);
    }

    private function sum($rows, string $column, int $scale): string
    {
        return MinorUnits::format($rows->sum(fn ($row) => MinorUnits::parse((string) $row->$column, $scale)), $scale);
    }
}
