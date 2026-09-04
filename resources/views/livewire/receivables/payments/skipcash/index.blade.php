<?php

use App\Services\Payments\PaymentOperationsQueryService;
use App\Services\Payments\PaymentOperationsHealthService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $view = 'attention';
    public string $search = '';
    public string $state = '';
    public ?string $date_from = null;
    public ?string $date_to = null;

    protected $paginationTheme = 'tailwind';

    public function updating(string $field): void
    {
        if (in_array($field, ['view', 'search', 'state', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function with(
        PaymentOperationsQueryService $queries,
        PaymentOperationsHealthService $healthService,
    ): array
    {
        return [
            'checkouts' => $queries->query(Auth::user(), [
                'view' => $this->view,
                'search' => $this->search,
                'state' => $this->state,
                'date_from' => $this->date_from,
                'date_to' => $this->date_to,
            ])->paginate(15),
            'health' => $healthService->summary(Auth::user()),
        ];
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Payments · SkipCash') }}</h1>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Verified payment, RMS completion, email, and settlement are shown separately.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if(auth()->user()?->hasRole('admin') || auth()->user()?->can('payments.settings.manage'))
                <flux:button :href="route('settings.payments')" variant="ghost" wire:navigate>{{ __('Payment settings') }}</flux:button>
            @endif
            <flux:button :href="route('receivables.payments.index')" variant="ghost" wire:navigate>{{ __('Payment receipts') }}</flux:button>
        </div>
    </div>

    <div class="flex gap-2" role="tablist" aria-label="{{ __('SkipCash checkout view') }}">
        <flux:button type="button" :variant="$view === 'attention' ? 'primary' : 'ghost'" wire:click="$set('view', 'attention')" wire:loading.attr="disabled" wire:target="view">
            {{ __('Needs attention') }}
        </flux:button>
        <flux:button type="button" :variant="$view === 'all' ? 'primary' : 'ghost'" wire:click="$set('view', 'all')" wire:loading.attr="disabled" wire:target="view">
            {{ __('All checkouts') }}
        </flux:button>
    </div>

    <section class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="skipcash-health-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="skipcash-health-heading" class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Operations health') }}</h2>
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Health reflects recorded command runs and overdue work; an idle queue alone is not treated as healthy.') }}</p>
            </div>
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $health['collection_enabled'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-100' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200' }}">
                {{ $health['collection_enabled'] ? __('New collection enabled') : __('New collection disabled') }}
            </span>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['recovery' => __('Recovery scheduler'), 'purge' => __('Evidence purge')] as $healthKey => $healthLabel)
                @php
                    $healthItem = $health[$healthKey];
                @endphp
                <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                    <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ $healthLabel }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ (string) str($healthItem['freshness'])->title() }}</dd>
                    <dd class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        {{ $healthItem['last_success_at'] ? __('Last success: :time', ['time' => \Illuminate\Support\Carbon::parse($healthItem['last_success_at'])->format('Y-m-d H:i:s')]) : __('No successful run recorded') }}
                    </dd>
                    @if (($healthItem['last_result'] ?? 'unknown') === 'failed')
                        <dd class="mt-1 text-xs font-medium text-rose-700 dark:text-rose-300">{{ __('Latest run failed: :code', ['code' => $healthItem['error_code'] ?? 'COMMAND_FAILED']) }}</dd>
                    @endif
                </div>
            @endforeach

            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Overdue work') }}</dt>
                <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $health['overdue_count'] }}</dd>
                <dd class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ $health['oldest_overdue_at'] ? __('Oldest: :time', ['time' => \Illuminate\Support\Carbon::parse($health['oldest_overdue_at'])->format('Y-m-d H:i:s')]) : __('Nothing overdue') }}
                </dd>
            </div>

            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <dt class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Configuration') }}</dt>
                <dd class="mt-1 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $health['configuration_state'] === 'ready' ? __('Ready') : __('Not ready') }}</dd>
                <dd class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ $health['configuration_codes'] === [] ? __('No setup issue detected') : implode(', ', $health['configuration_codes']) }}
                </dd>
            </div>
        </dl>

        @if ($health['last_worker_result'])
            <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">
                {{ __('Latest observed worker action: :kind · :state · :time', [
                    'kind' => $health['last_worker_result']['kind'],
                    'state' => $health['last_worker_result']['state'],
                    'time' => \Illuminate\Support\Carbon::parse($health['last_worker_result']['observed_at'])->format('Y-m-d H:i:s'),
                ]) }}
            </p>
        @endif
    </section>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div class="min-w-[230px]">
                <label for="skipcash-search" class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Customer or reference') }}</label>
                <input id="skipcash-search" wire:model.live.debounce.300ms="search" type="search" autocomplete="off" placeholder="{{ __('Name, phone, checkout or payment reference') }}" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
            <div class="min-w-[160px]">
                <label for="skipcash-state" class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('RMS result') }}</label>
                <select id="skipcash-state" wire:model.live="state" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="paid_processing">{{ __('Paid · processing') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="declined">{{ __('Declined') }}</option>
                    <option value="expired">{{ __('Expired') }}</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label for="skipcash-from" class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date from') }}</label>
                <input id="skipcash-from" wire:model.live="date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
            <div class="min-w-[160px]">
                <label for="skipcash-to" class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date to') }}</label>
                <input id="skipcash-to" wire:model.live="date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
        </div>
    </div>

    <div class="app-table-shell overflow-x-auto">
        <table class="w-full min-w-[900px] divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Checkout') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Provider') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('RMS') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Issue') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($checkouts as $checkout)
                    @php
                        $provider = $checkout->providerTransactions->first();
                        $customer = $checkout->customer?->mergedIntoCustomer ?? $checkout->customer;
                        $attentionIssues = collect((array) data_get($checkout->operations_tracking, 'issues', []))->filter(
                            fn ($issue) => is_array($issue) && empty($issue['resolved_at']) && \Illuminate\Support\Carbon::parse($issue['attention_at'])->isPast()
                        );
                    @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                            <div class="font-medium">{{ $checkout->reference }}</div>
                            <div class="text-xs text-neutral-500">{{ $checkout->created_at?->format('Y-m-d H:i') }}</div>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div>{{ $customer?->name ?? __('Unavailable') }}</div>
                            <div class="text-xs text-neutral-500">{{ $customer?->phone ? str($customer->phone)->mask('•', 3, max(0, strlen($customer->phone) - 6)) : '—' }}</div>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $provider?->verified_paid_at ? __('Paid verified') : ($provider?->normalized_status ? (string) str($provider->normalized_status)->title() : __('Not recorded')) }}
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ (string) str($checkout->state)->replace('_', ' ')->title() }}</td>
                        <td class="px-3 py-3 text-sm">
                            @if ($attentionIssues->isNotEmpty())
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($attentionIssues as $issue)
                                        <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-100">
                                            {{ (string) str($issue['reason_code'])->replace('_', ' ')->title() }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-neutral-500">{{ __('None') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($checkout->payable_amount_cents) }}</td>
                        <td class="px-3 py-3 text-right"><flux:button size="xs" :href="route('receivables.payments.skipcash.show', $checkout)" wire:navigate>{{ __('View') }}</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ $view === 'attention' ? __('No SkipCash payments need attention.') : __('No SkipCash checkouts found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $checkouts->links() }}</div>
</div>
