<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;
    public ?int $customer_id = null;
    public string $customer_search = '';
    public ?string $date_from = null;
    public ?string $date_to = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->endOfMonth()->toDateString();
    }

    public function updatedCustomerSearch(): void
    {
        if ($this->customer_id === null) {
            return;
        }

        $selected = Customer::find($this->customer_id);
        $selectedLabel = $selected ? trim($selected->name.' '.($selected->phone ?? '')) : '';
        if (trim($this->customer_search) !== $selectedLabel) {
            $this->customer_id = null;
        }
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? trim($c->name.' '.($c->phone ?? '')) : '';
    }

    public function with(): array
    {
        $rows = $this->statementRows();

        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        return [
            'rows' => $rows,
            'summary' => $this->statementSummary($rows),
            'aging' => $this->agingSummary(),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'customers' => $customers,
            'exportParams' => $this->exportParams(),
        ];
    }

    /**
     * @return Collection<int, array<string, int|string>>
     */
    private function statementRows(): Collection
    {
        if (! $this->customer_id) {
            return collect();
        }

        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $dateTo = $this->date_to ? now()->parse($this->date_to)->endOfDay() : now()->endOfMonth()->endOfDay();
        $asOf = $dateTo->copy();

        $invoices = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereDate('issue_date', '>=', $dateFrom)
            ->whereDate('issue_date', '<=', $dateTo)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $branchNames = Schema::hasTable('branches')
            ? DB::table('branches')
                ->whereIn('id', $invoices->pluck('branch_id')->filter()->unique()->values())
                ->pluck('name', 'id')
            : collect();

        return $invoices->values()->map(function (ArInvoice $invoice, int $index) use ($asOf, $branchNames): array {
            $dueDate = $invoice->due_date ?: $invoice->issue_date;
            $days = $dueDate ? max(0, (int) floor((float) $dueDate->diffInDays($asOf, false))) : 0;
            $paymentType = strtolower((string) ($invoice->payment_type ?? 'credit'));

            return [
                'line_no' => $index + 1,
                'document_no' => $invoice->invoice_number ?: (string) $invoice->id,
                'document_type' => 'AR Invoice',
                'location' => (string) ($branchNames[(int) $invoice->branch_id] ?? ('Branch '.$invoice->branch_id)),
                'type' => $paymentType === 'credit' ? 'On Credit' : ucfirst((string) ($invoice->payment_type ?: 'Credit')),
                'date' => $invoice->issue_date?->format('d-M-Y') ?? '-',
                'due_date' => $dueDate?->format('d-M-Y') ?? '-',
                'reference_no' => $invoice->lpo_reference ?: ($invoice->pos_reference ?: '-'),
                'amount_cents' => (int) ($invoice->total_cents ?? 0),
                'paid_cents' => (int) ($invoice->paid_total_cents ?? 0),
                'balance_cents' => (int) ($invoice->balance_cents ?? 0),
                'aging_label' => $days.' Days',
                'payment_no' => '-',
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, int|string>>  $rows
     * @return array{period_amount_cents:int,period_paid_cents:int,period_balance_cents:int,previous_balance_cents:int,total_outstanding_cents:int}
     */
    private function statementSummary(Collection $rows): array
    {
        $dateFrom = $this->date_from ? now()->parse($this->date_from)->startOfDay() : now()->startOfMonth()->startOfDay();

        $periodAmount = (int) $rows->sum('amount_cents');
        $periodPaid = (int) $rows->sum('paid_cents');
        $periodBalance = (int) $rows->sum('balance_cents');

        $previousBalance = 0;
        if ($this->customer_id) {
            $previousBalance = (int) ArInvoice::query()
                ->where('customer_id', $this->customer_id)
                ->where('type', 'invoice')
                ->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->where('balance_cents', '>', 0)
                ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
                ->whereDate('issue_date', '<', $dateFrom)
                ->sum('balance_cents');
        }

        return [
            'period_amount_cents' => $periodAmount,
            'period_paid_cents' => $periodPaid,
            'period_balance_cents' => $periodBalance,
            'previous_balance_cents' => $previousBalance,
            'total_outstanding_cents' => $previousBalance + $periodBalance,
        ];
    }

    /**
     * @return array{not_due:int,bucket_1_30:int,bucket_31_60:int,bucket_61_90:int,bucket_over_90:int,total:int}
     */
    private function agingSummary(): array
    {
        if (! $this->customer_id) {
            return [
                'not_due' => 0,
                'bucket_1_30' => 0,
                'bucket_31_60' => 0,
                'bucket_61_90' => 0,
                'bucket_over_90' => 0,
                'total' => 0,
            ];
        }

        $asOf = $this->date_to ? now()->parse($this->date_to)->endOfDay() : now()->endOfMonth()->endOfDay();

        $invoices = ArInvoice::query()
            ->where('customer_id', $this->customer_id)
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->where('balance_cents', '>', 0)
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->whereDate('issue_date', '<=', $asOf)
            ->get();

        $aging = [
            'not_due' => 0,
            'bucket_1_30' => 0,
            'bucket_31_60' => 0,
            'bucket_61_90' => 0,
            'bucket_over_90' => 0,
            'total' => 0,
        ];

        foreach ($invoices as $invoice) {
            $balance = (int) ($invoice->balance_cents ?? 0);
            if ($balance <= 0) {
                continue;
            }

            $dueDate = $invoice->due_date ?: $invoice->issue_date;
            $days = $dueDate ? $dueDate->diffInDays($asOf, false) : 0;

            if ($days <= 0) {
                $aging['not_due'] += $balance;
            } elseif ($days <= 30) {
                $aging['bucket_1_30'] += $balance;
            } elseif ($days <= 60) {
                $aging['bucket_31_60'] += $balance;
            } elseif ($days <= 90) {
                $aging['bucket_61_90'] += $balance;
            } else {
                $aging['bucket_over_90'] += $balance;
            }

            $aging['total'] += $balance;
        }

        return $aging;
    }

    public function exportParams(): array
    {
        return array_filter([
            'branch_id' => $this->branch_id ?: null,
            'customer_id' => $this->customer_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Statement') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('reports.index')" wire:navigate variant="ghost">{{ __('Back to Reports') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.print') . '?' . http_build_query($exportParams) }}" target="_blank" variant="ghost">{{ __('Print') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.csv') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export CSV') }}</flux:button>
            <flux:button href="{{ route('reports.customer-statement.pdf') . '?' . http_build_query($exportParams) }}" variant="ghost">{{ __('Export PDF') }}</flux:button>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <x-reports.branch-select name="branch_id" :branches="$branches" />
            <div class="min-w-[220px] relative">
                <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Customer')" placeholder="{{ __('Search by name/phone/code') }}" />
                @if($customer_id === null && trim($customer_search) !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $c)
                                <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $c->id }})">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $c->name }}</span>
                                        @if($c->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->customer_code }}</span>
                                        @endif
                                    </div>
                                    @if($c->phone)
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->phone }}</div>
                                    @endif
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
            <x-reports.date-range fromName="date_from" toName="date_to" />
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('#') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('No') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Document Type') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Location') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference No') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Paid') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Aging') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment No') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($rows as $row)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $row['line_no'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['document_no'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['document_type'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['location'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['type'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['due_date'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['reference_no'] }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['amount_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['paid_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($row['balance_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['aging_label'] }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $row['payment_no'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select a customer to view statement.') }}</td></tr>
                @endforelse
            </tbody>
            @if ($rows->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="8" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total Amount') }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_amount_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_paid_cents']) }}</td>
                        <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_balance_cents']) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Current Balance') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['period_balance_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Previous Balance') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['previous_balance_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Total Outstanding') }}</div>
            <div class="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($summary['total_outstanding_cents']) }}</div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Not in Due') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('1-30') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('31-60') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('61-90') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Over 90 Days') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['not_due']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_1_30']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_31_60']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_61_90']) }}</td>
                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['bucket_over_90']) }}</td>
                    <td class="px-3 py-2 text-sm text-right font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($aging['total']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
