<?php

namespace App\Services\Reports;

use App\Models\AccountingCompany;
use App\Models\ApChequeClearance;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApReportService
{
    public const JOURNAL_SOURCES = ['ap_invoice', 'ap_payment', 'ap_payment_allocation', 'ap_cheque_clearance'];

    public const JOURNAL_LAYOUT_VERSION = 3;

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

        $lines = $query->select(['e.id', 'e.entry_date', 'e.posted_at', 'e.event', 'e.source_type', 'e.source_id',
            'e.description', 'e.currency_code', 'b.name as branch', 'l.account_id', 'a.code', 'a.name as account', 'l.debit', 'l.credit'])
            ->orderBy('e.posted_at')->orderBy('e.id')->orderBy('l.id')->get();
        $sourceDetails = $this->journalSourceDetails($lines, $companyId);
        $entries = $lines->groupBy('id')->map(function (Collection $entryLines) use ($sourceDetails) {
            $entry = $entryLines->first();
            $details = $sourceDetails[$entry->source_type][(int) $entry->source_id] ?? [];
            $postedTime = $entry->posted_at ? Carbon::parse($entry->posted_at)->format('H:i') : '';
            $context = collect([$details['details'] ?? null, $entry->branch])->filter()->unique()->implode(' / ');
            $amount = $entryLines->sum(fn ($line) => MinorUnits::parse((string) $line->debit, 10000));

            return [
                'currency' => $entry->currency_code,
                'amount' => $amount,
                'source_type' => $entry->source_type,
                'net_amount' => str_contains(strtolower($entry->event), 'void') ? -$amount : $amount,
                'row' => [
                    trim($entry->entry_date.' '.$postedTime),
                    $this->journalType($entry->source_type, $entry->event),
                    $details['reference'] ?? $this->journalFallbackReference($entry->source_type, (int) $entry->source_id, (string) $entry->description),
                    $context,
                    $details['category'] ?? '',
                    $this->journalAccounts($entryLines, 'debit'),
                    $this->journalAccounts($entryLines, 'credit'),
                    $entry->currency_code.' '.$this->journalAmount($amount),
                ],
            ];
        })->values();

        return [
            'title' => __('AP Journal Entries'),
            'description' => __('Each row is one AP accounting event. Debit and credit accounts are the two sides of that event. VOID rows reverse an earlier event. Net totals subtract reversals separately for each transaction type; gross movement includes all events and is not an expense or outstanding balance.'),
            'headers' => [__('Date / time'), __('Transaction'), __('Reference'), __('Supplier / branch'), __('Category'), __('Debit account'), __('Credit account'), __('Amount')],
            'rows' => $entries->pluck('row')->all(),
            'totals' => $entries->groupBy('currency')->flatMap(function (Collection $group, string $currency) {
                $totals = $group->groupBy('source_type')->map(fn (Collection $events, string $sourceType) => [
                    __('Net :transaction', ['transaction' => $this->journalType($sourceType, 'post')]), '', '', '', '', '', '',
                    $currency.' '.$this->journalAmount($events->sum('net_amount')),
                ])->values();
                $totals->push([__('Gross journal movement'), '', '', '', '', '', '', $currency.' '.$this->journalAmount($group->sum('amount'))]);

                return $totals;
            })->values()->all(),
            'entryCount' => $entries->count(),
            'layoutVersion' => self::JOURNAL_LAYOUT_VERSION,
        ];
    }

    /** @return array<string, array<int, array{reference: string, details: string, category?: string}>> */
    private function journalSourceDetails(Collection $lines, int $companyId): array
    {
        $ids = $lines->groupBy('source_type')->map(fn (Collection $rows) => $rows->pluck('source_id')->map(fn ($id) => (int) $id)->unique()->values());
        $details = [];

        if ($invoiceIds = $ids->get('ap_invoice')) {
            ApInvoice::query()->with(['supplier:id,name', 'category:id,name'])->where('company_id', $companyId)->whereKey($invoiceIds)->get()
                ->each(function (ApInvoice $invoice) use (&$details) {
                    $details['ap_invoice'][$invoice->id] = [
                        'reference' => $invoice->invoice_number ?: __('Invoice #:id', ['id' => $invoice->id]),
                        'details' => (string) ($invoice->supplier?->name ?? ''),
                        'category' => $invoice->category?->name ?? ($invoice->is_expense ? __('Uncategorized') : ''),
                    ];
                });
        }

        if ($paymentIds = $ids->get('ap_payment')) {
            ApPayment::query()->with('supplier:id,name')->where('company_id', $companyId)->whereKey($paymentIds)->get()
                ->each(function (ApPayment $payment) use (&$details) {
                    $context = collect([$payment->supplier?->name, $payment->reference ? __('Reference: :reference', ['reference' => $payment->reference]) : null])->filter()->implode(' / ');
                    $details['ap_payment'][$payment->id] = [
                        'reference' => $payment->voucherNumber(),
                        'details' => $context,
                    ];
                });
        }

        if ($allocationIds = $ids->get('ap_payment_allocation')) {
            ApPaymentAllocation::query()->with(['payment.supplier:id,name', 'invoice:id,invoice_number,category_id,is_expense', 'invoice.category:id,name'])
                ->whereKey($allocationIds)
                ->whereHas('payment', fn ($query) => $query->where('company_id', $companyId))
                ->get()->each(function (ApPaymentAllocation $allocation) use (&$details) {
                    $payment = $allocation->payment;
                    $details['ap_payment_allocation'][$allocation->id] = [
                        'reference' => collect([$payment?->voucherNumber(), $allocation->invoice?->invoice_number])->filter()->implode(' / '),
                        'details' => (string) ($payment?->supplier?->name ?? ''),
                        'category' => $allocation->invoice?->category?->name ?? ($allocation->invoice?->is_expense ? __('Uncategorized') : ''),
                    ];
                });
        }

        if ($clearanceIds = $ids->get('ap_cheque_clearance')) {
            ApChequeClearance::query()->with('apPayment.supplier:id,name')->where('company_id', $companyId)->whereKey($clearanceIds)->get()
                ->each(function (ApChequeClearance $clearance) use (&$details) {
                    $details['ap_cheque_clearance'][$clearance->id] = [
                        'reference' => $clearance->reference ?: __('Clearance #:id', ['id' => $clearance->id]),
                        'details' => collect([$clearance->apPayment?->supplier?->name, $clearance->apPayment?->voucherNumber()])->filter()->implode(' / '),
                    ];
                });
        }

        return $details;
    }

    private function journalType(string $sourceType, string $event): string
    {
        $void = str_contains(strtolower($event), 'void');

        return match ($sourceType) {
            'ap_invoice' => $void ? __('VOID - Supplier invoice') : __('Supplier invoice'),
            'ap_payment' => $void ? __('VOID - Supplier payment') : __('Supplier payment'),
            'ap_payment_allocation' => $void ? __('VOID - Advance allocation') : __('Advance allocation'),
            'ap_cheque_clearance' => $void ? __('VOID - Cheque clearance') : __('Cheque clearance'),
            default => $void ? __('VOID - AP event') : __('AP event'),
        };
    }

    private function journalFallbackReference(string $sourceType, int $sourceId, string $description): string
    {
        if ($sourceType === 'ap_invoice') {
            $reference = trim((string) preg_replace('/^AP Invoice(?: void)?\s*/i', '', $description));

            return $reference !== '' ? $reference : __('Invoice #:id', ['id' => $sourceId]);
        }

        return match ($sourceType) {
            'ap_payment' => __('Payment #:id', ['id' => $sourceId]),
            'ap_payment_allocation' => __('Allocation #:id', ['id' => $sourceId]),
            'ap_cheque_clearance' => __('Clearance #:id', ['id' => $sourceId]),
            default => '#'.$sourceId,
        };
    }

    private function journalAccounts(Collection $lines, string $side): string
    {
        $accounts = $lines->filter(fn ($line) => MinorUnits::parse((string) $line->$side, 10000) > 0)->groupBy('account_id');
        $showAmounts = $accounts->count() > 1;

        return $accounts->map(function (Collection $accountLines) use ($side, $showAmounts) {
            $line = $accountLines->first();
            $label = trim($line->code.' '.$line->account);
            if (! $showAmounts) {
                return $label;
            }
            $amount = $accountLines->sum(fn ($accountLine) => MinorUnits::parse((string) $accountLine->$side, 10000));

            return $label.' ('.$this->journalAmount($amount).')';
        })->implode('; ');
    }

    private function journalAmount(int $minorUnitsAtFourDecimals): string
    {
        $sign = $minorUnitsAtFourDecimals < 0 ? -1 : 1;
        $rounded = intdiv(abs($minorUnitsAtFourDecimals) + 5, 10) * $sign;

        return MinorUnits::format($rounded, 1000);
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
