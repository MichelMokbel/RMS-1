<?php

use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public int $branch_id = 1;
    public string $status = 'all';
    public string $payment_type = 'all';
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['branch_id', 'status', 'payment_type', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $rows = $this->customerReceivables();
        $perPage = 50;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageItems = $rows->forPage($page, $perPage)->values();
        $receivables = new LengthAwarePaginator(
            $pageItems,
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'receivables' => $receivables,
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        $paymentType = $this->payment_type !== 'all' ? $this->payment_type : null;

        return ArInvoice::query()
            ->with(['customer:id,name,customer_code', 'paymentAllocations.payment'])
            ->where('type', 'invoice')
            ->whereNull('voided_at')
            ->where('balance_cents', '>', 0)
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($paymentType !== null, fn (Builder $q) => $this->applyPaymentTypeFilter($q, $paymentType))
            ->when($this->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    private function customerReceivables()
    {
        return $this->query()
            ->groupBy(fn (ArInvoice $invoice) => (int) $invoice->customer_id)
            ->map(function ($group, $customerId) {
                /** @var \Illuminate\Support\Collection<int, ArInvoice> $group */
                $customer = $group->first()?->customer;
                $latestIssueDate = $group
                    ->pluck('issue_date')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'customer_id' => (int) $customerId,
                    'customer_name' => $customer?->name ?? '—',
                    'customer_code' => $customer?->customer_code,
                    'open_invoices' => $group->count(),
                    'receivable_cents' => (int) $group->sum('balance_cents'),
                    'last_invoice_date' => $latestIssueDate?->format('Y-m-d'),
                ];
            })
            ->sort(function (array $a, array $b): int {
                $amountCmp = ($b['receivable_cents'] <=> $a['receivable_cents']);
                if ($amountCmp !== 0) {
                    return $amountCmp;
                }

                $nameCmp = strnatcasecmp((string) $a['customer_name'], (string) $b['customer_name']);
                if ($nameCmp !== 0) {
                    return $nameCmp;
                }

                return ((int) $a['customer_id']) <=> ((int) $b['customer_id']);
            })
            ->values();
    }

    private function applyPaymentTypeFilter(Builder $query, string $paymentType): Builder
    {
        $hasCash = fn (Builder $q) => $q
            ->where('amount_cents', '>', 0)
            ->whereHas('payment', fn (Builder $p) => $p->where('method', 'cash'));
        $hasNonCash = fn (Builder $q) => $q
            ->where('amount_cents', '>', 0)
            ->whereHas('payment', fn (Builder $p) => $p->where('method', '!=', 'cash'));

        return match (strtolower($paymentType)) {
            'cash' => $query
                ->whereHas('paymentAllocations', $hasCash)
                ->whereDoesntHave('paymentAllocations', $hasNonCash),
            'card' => $query
                ->whereHas('paymentAllocations', $hasNonCash)
                ->whereDoesntHave('paymentAllocations', $hasCash),
            'mixed' => $query
                ->whereHas('paymentAllocations', $hasCash)
                ->whereHas('paymentAllocations', $hasNonCash),
            'credit' => $query
                ->where('payment_type', 'credit')
                ->whereDoesntHave('paymentAllocations', fn (Builder $q) => $q->where('amount_cents', '>', 0)),
            default => $query->where('payment_type', $paymentType),
        };
    }

    public function resolvedPaymentType(ArInvoice $invoice): string
    {
        $hasCash = false;
        $hasCard = false;

        foreach ($invoice->paymentAllocations as $allocation) {
            $amount = max(0, (int) ($allocation->amount_cents ?? 0));
            if ($amount <= 0) {
                continue;
            }

            $method = strtolower((string) ($allocation->payment?->method ?? ''));
            if ($method === 'cash') {
                $hasCash = true;
            } else {
                // Non-cash settled methods are grouped under card in this report.
                $hasCard = true;
            }
        }

        if ($hasCash && $hasCard) {
            return 'mixed';
        }
        if ($hasCash) {
            return 'cash';
        }
        if ($hasCard) {
            return 'card';
        }

        return strtolower((string) ($invoice->payment_type ?: 'credit'));
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id,
            'status' => $this->status !== 'all' ? $this->status : null,
            'payment_type' => $this->payment_type !== 'all' ? $this->payment_type : null,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatCents(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Receivables (AR) Report') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.receivables.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.receivables.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.receivables.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <x-reports.date-range fromName="date_from" toName="date_to" />
            <x-reports.status-select name="status" :options="[
                ['value' => 'all', 'label' => __('All')],
                ['value' => 'draft', 'label' => __('Draft')],
                ['value' => 'issued', 'label' => __('Issued')],
                ['value' => 'partially_paid', 'label' => __('Partially Paid')],
                ['value' => 'paid', 'label' => __('Paid')],
                ['value' => 'voided', 'label' => __('Voided')],
            ]" />
            <x-reports.status-select name="payment_type" :options="[
                ['value' => 'all', 'label' => __('All')],
                ['value' => 'credit', 'label' => __('Credit')],
                ['value' => 'cash', 'label' => __('Cash')],
                ['value' => 'card', 'label' => __('Card')],
                ['value' => 'mixed', 'label' => __('Mixed')],
            ]" />
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer Code') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Open Invoices') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Last Invoice Date') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Receivable') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($receivables as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['customer_code'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['customer_name'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ $row['open_invoices'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['last_invoice_date'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($row['receivable_cents']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No receivables found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($receivables->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="4" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total Receivable') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($receivables->getCollection()->sum('receivable_cents')) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div>{{ $receivables->links() }}</div>
</div>
