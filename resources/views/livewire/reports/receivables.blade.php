<?php

use App\Models\ArInvoice;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
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
    }

    public function updating($name): void
    {
        if (in_array($name, ['branch_id', 'status', 'payment_type', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        return [
            'invoices' => $this->query()->paginate(15),
            'branches' => Schema::hasTable('branches') ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect(),
            'exportParams' => $this->exportParams(),
        ];
    }

    private function query()
    {
        $paymentType = $this->payment_type !== 'all' ? $this->payment_type : null;

        return ArInvoice::query()
            ->with(['customer', 'paymentAllocations.payment'])
            ->when($this->branch_id > 0, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($paymentType !== null, fn (Builder $q) => $this->applyPaymentTypeFilter($q, $paymentType))
            ->when($this->date_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->date_to))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');
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

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
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
        <div class="flex flex-wrap items-end gap-3">
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

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Payment Type') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($invoices as $inv)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number ?: ('#'.$inv->id) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->customer?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->issue_date?->format('Y-m-d') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->status }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $this->resolvedPaymentType($inv) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($inv->total_cents) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($inv->balance_cents) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No invoices found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($invoices->count() > 0)
                <tfoot class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <td colspan="5" class="px-3 py-2 text-right text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($invoices->getCollection()->sum('total_cents')) }}</td>
                        <td class="px-3 py-2 text-right text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatCents($invoices->getCollection()->sum('balance_cents')) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div>{{ $invoices->links() }}</div>
</div>
