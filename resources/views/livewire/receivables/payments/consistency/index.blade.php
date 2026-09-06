<?php

use App\Models\PaymentConsistencyFinding;
use App\Services\Payments\PaymentConsistencyManualService;
use App\Services\Payments\PaymentConsistencyQueryService;
use App\Services\Payments\PaymentConsistencyRuleRegistry;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $state = 'open';

    #[Url]
    public string $rule = '';

    #[Url]
    public string $date_from = '';

    #[Url]
    public string $date_to = '';

    public string $operation_uuid = '';

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->operation_uuid = (string) Str::uuid();
    }

    public function updating($field): void
    {
        if (in_array($field, ['state', 'rule', 'date_from', 'date_to'], true)) {
            $this->resetPage();
        }
    }

    public function recheck(int $findingId, PaymentConsistencyQueryService $queries, PaymentConsistencyManualService $manual): void
    {
        $finding = $queries->finding(auth()->user(), $findingId);
        $manual->recheck($finding, auth()->user(), strtolower($this->operation_uuid));
        $this->operation_uuid = (string) Str::uuid();
        session()->flash('status', __('Consistency recheck queued.'));
    }

    public function with(PaymentConsistencyQueryService $queries, PaymentConsistencyRuleRegistry $registry): array
    {
        return [
            'findings' => $queries->findings(auth()->user(), [
                'state' => $this->state,
                'rule' => $this->rule,
                'date_from' => $this->date_from,
                'date_to' => $this->date_to,
            ])->paginate(15),
            'health' => $queries->health(auth()->user()),
            'rules' => $registry->codes(),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payment Consistency') }}</h1>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Read-only checks for checkouts, accounting, memberships, customer ownership, saved credit, settlements and notifications.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('receivables.payments.skipcash.index')" variant="ghost" wire:navigate>{{ __('SkipCash') }}</flux:button>
            <flux:button :href="route('receivables.payments.index')" variant="ghost" wire:navigate>{{ __('Payment receipts') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</div>
    @endif
    @error('consistency')
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">{{ $message }}</div>
    @enderror

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('System state') }}</p>
            <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __(Str::headline($health['status'])) }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('Open findings') }}</p>
            <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $health['open_findings'] }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('Running / stalled') }}</p>
            <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $health['running_runs'] }} / {{ $health['stalled_runs'] }}</p>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('Last success') }}</p>
            <p class="mt-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $health['last_success_at']?->format('Y-m-d H:i') ?? __('Never') }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-filter-grid">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="state" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All') }}</option>
                    <option value="open">{{ __('Open') }}</option>
                    <option value="resolved">{{ __('Resolved') }}</option>
                </select>
            </div>
            <div class="min-w-[250px]">
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Rule') }}</label>
                <select wire:model.live="rule" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('All rules') }}</option>
                    @foreach($rules as $ruleCode)
                        <option value="{{ $ruleCode }}">{{ Str::headline($ruleCode) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('From') }}</label>
                <input wire:model.live="date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('To') }}</label>
                <input wire:model.live="date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
        </div>
    </div>

    <div class="space-y-3 lg:hidden">
        @forelse($findings as $finding)
            <article wire:key="consistency-card-{{ $finding->id }}" class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ Str::headline($finding->rule_code) }}</p>
                        <p class="text-xs text-neutral-500">{{ Str::headline($finding->subject_type) }} #{{ $finding->subject_id }}</p>
                    </div>
                    <span @class(['rounded-full px-2 py-1 text-xs font-semibold', 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $finding->state === 'open', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200' => $finding->state === 'resolved'])>{{ __(Str::headline($finding->state)) }}</span>
                </div>
                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Last seen') }}: {{ $finding->last_seen_at?->format('Y-m-d H:i') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <flux:button size="sm" :href="route('receivables.payments.consistency.show', $finding)" wire:navigate>{{ __('Details') }}</flux:button>
                    @if(config('payment_consistency.enabled') && $finding->state === 'open' && auth()->user()?->isAdmin() && auth()->user()?->can('payments.consistency.run'))
                        <flux:button size="sm" variant="ghost" wire:click="recheck({{ $finding->id }})" wire:loading.attr="disabled">{{ __('Recheck') }}</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-neutral-200 bg-white p-6 text-center text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">{{ __('No consistency findings match these filters.') }}</div>
        @endforelse
    </div>

    <div class="app-table-shell hidden lg:block">
        <table class="w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('Rule') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('Subject') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('First seen') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('Last seen') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse($findings as $finding)
                    <tr wire:key="consistency-row-{{ $finding->id }}">
                        <td class="px-3 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ Str::headline($finding->rule_code) }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300">{{ Str::headline($finding->subject_type) }} #{{ $finding->subject_id }}</td>
                        <td class="px-3 py-3 text-sm">{{ __(Str::headline($finding->state)) }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300">{{ $finding->first_seen_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300">{{ $finding->last_seen_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <flux:button size="xs" :href="route('receivables.payments.consistency.show', $finding)" wire:navigate>{{ __('Details') }}</flux:button>
                                @if(config('payment_consistency.enabled') && $finding->state === 'open' && auth()->user()?->isAdmin() && auth()->user()?->can('payments.consistency.run'))
                                    <flux:button size="xs" variant="ghost" wire:click="recheck({{ $finding->id }})" wire:loading.attr="disabled">{{ __('Recheck') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No consistency findings match these filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $findings->links() }}</div>
</div>
