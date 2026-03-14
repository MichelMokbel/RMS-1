<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ClosingChecklist;
use App\Models\PeriodLock;
use App\Services\Accounting\AccountingPeriodChecklistService;
use App\Services\Accounting\AccountingPeriodCloseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public ?int $period_id = null;
    public string $tab = 'checklist';
    public string $close_note = '';
    public string $reopen_reason = '';
    public bool $move_lock_date_back = false;

    public function mount(AccountingPeriodCloseService $service): void
    {
        $this->authorizeFinance();

        $requestedTab = (string) request()->query('tab', 'checklist');
        $this->tab = in_array($requestedTab, ['checklist', 'exceptions', 'history'], true) ? $requestedTab : 'checklist';
        $this->company_id = $service->defaultCompanyId();
        $this->period_id = $this->defaultPeriodId($service, $this->company_id);
    }

    public function updatedCompanyId(AccountingPeriodCloseService $service): void
    {
        $this->period_id = $this->defaultPeriodId($service, $this->company_id);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['checklist', 'exceptions', 'history'], true) ? $tab : 'checklist';
    }

    public function refreshChecks(AccountingPeriodCloseService $service): void
    {
        $this->authorizeFinance();
        if ($period = $this->selectedPeriod()) {
            $service->readiness($period);
            session()->flash('status', __('Period checks refreshed.'));
        }
    }

    public function completeTask(int $checklistId, AccountingPeriodChecklistService $service): void
    {
        $this->authorizeFinance();

        $item = ClosingChecklist::query()->findOrFail($checklistId);
        $service->completeManualTask($item, (int) auth()->id(), $item->notes);
        session()->flash('status', __('Checklist task completed.'));
    }

    public function resetTask(int $checklistId, AccountingPeriodChecklistService $service): void
    {
        $this->authorizeFinance();

        $item = ClosingChecklist::query()->findOrFail($checklistId);
        $service->resetManualTask($item, (int) auth()->id(), $item->notes);
        session()->flash('status', __('Checklist task reset.'));
    }

    public function closePeriod(AccountingPeriodCloseService $service): void
    {
        $this->authorizeFinance();
        $period = $this->selectedPeriod();
        abort_unless($period, 404);

        $service->close($period, (int) auth()->id(), $this->close_note);
        $this->close_note = '';
        session()->flash('status', __('Accounting period closed.'));
    }

    public function reopenPeriod(AccountingPeriodCloseService $service): void
    {
        $this->authorizeAdmin();
        $period = $this->selectedPeriod();
        abort_unless($period, 404);

        $service->reopen($period, (int) auth()->id(), $this->reopen_reason, $this->move_lock_date_back);
        $this->reopen_reason = '';
        $this->move_lock_date_back = false;
        session()->flash('status', __('Accounting period reopened.'));
    }

    public function with(AccountingPeriodCloseService $service): array
    {
        $this->authorizeFinance();

        $companies = AccountingCompany::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $periods = $this->company_id ? $service->companyPeriods($this->company_id) : collect();
        if (! $this->period_id && $periods->isNotEmpty()) {
            $this->period_id = (int) $periods->first()->id;
        }

        $period = $this->selectedPeriod($periods);
        $readiness = $period ? $service->readiness($period) : null;
        $history = $period ? $this->historyForPeriod($period) : collect();

        return [
            'companies' => $companies,
            'periods' => $periods,
            'readiness' => $readiness,
            'history' => $history,
        ];
    }

    private function defaultPeriodId(AccountingPeriodCloseService $service, ?int $companyId): ?int
    {
        if (! $companyId) {
            return null;
        }

        $periods = $service->companyPeriods($companyId);
        $current = $periods->first(fn (AccountingPeriod $period) => in_array($period->status, ['open', 'ended_open', 'reopened'], true));

        return $current ? (int) $current->id : ($periods->first()?->id ? (int) $periods->first()->id : null);
    }

    private function selectedPeriod(?Collection $periods = null): ?AccountingPeriod
    {
        $periods ??= $this->company_id
            ? AccountingPeriod::query()->where('company_id', $this->company_id)->orderByDesc('start_date')->get()
            : collect();

        return $periods->firstWhere('id', $this->period_id);
    }

    private function historyForPeriod(AccountingPeriod $period): Collection
    {
        $itemIds = ClosingChecklist::query()
            ->where('period_id', $period->id)
            ->pluck('id');

        $auditLogs = AccountingAuditLog::query()
            ->where(function ($query) use ($period, $itemIds) {
                $query->where(function ($periodLog) use ($period) {
                    $periodLog->where('subject_type', AccountingPeriod::class)
                        ->where('subject_id', $period->id);
                });

                if ($itemIds->isNotEmpty()) {
                    $query->orWhere(function ($taskLog) use ($itemIds) {
                        $taskLog->where('subject_type', ClosingChecklist::class)
                            ->whereIn('subject_id', $itemIds);
                    });
                }
            })
            ->latest('created_at')
            ->limit(30)
            ->get();

        $locks = PeriodLock::query()
            ->where('period_id', $period->id)
            ->latest('locked_at')
            ->get()
            ->map(fn (PeriodLock $lock) => (object) [
                'type' => 'lock',
                'created_at' => $lock->locked_at,
                'label' => 'Period '.Str::headline($lock->lock_type),
                'meta' => trim(($lock->module ?: 'all').($lock->reason ? ' • '.$lock->reason : '')),
            ]);

        $logRows = $auditLogs->map(fn (AccountingAuditLog $log) => (object) [
            'type' => 'audit',
            'created_at' => $log->created_at,
            'label' => Str::headline(str_replace('.', ' ', $log->action)),
            'meta' => $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null,
        ]);

        return $locks->concat($logRows)->sortByDesc('created_at')->values();
    }

    private function authorizeFinance(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRole('admin') && ! $user->can('finance.access'))) {
            abort(403);
        }
    }

    private function authorizeAdmin(): void
    {
        $user = auth()->user();

        if (! $user || ! $user->hasRole('admin')) {
            abort(403);
        }
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Period Close') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Close accounting periods with blocking checklist controls, exception review, and full audit history.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="refreshChecks" variant="ghost">{{ __('Refresh Checks') }}</flux:button>
            <flux:button :href="route('accounting.dashboard')" wire:navigate variant="ghost">{{ __('Back to Accounting') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
            <select wire:model.live="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Period') }}</label>
            <select wire:model.live="period_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                @foreach ($periods as $period)
                    <option value="{{ $period->id }}">{{ $period->name }} ({{ $period->start_date?->format('Y-m-d') }} - {{ $period->end_date?->format('Y-m-d') }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($readiness)
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Status') }}</p>
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ Str::headline($readiness['period']->status) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('State') }}</p>
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ Str::headline($readiness['period_state']) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Lock Status') }}</p>
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $readiness['lock'] ? Str::headline($readiness['lock']['lock_type']) : __('Unlocked') }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Lock Date') }}</p>
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $readiness['lock_date'] ?? '—' }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Checklist') }}</p>
                <p class="mt-2 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $readiness['summary']['completed_total'] }}/{{ $readiness['summary']['required_total'] }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Close Ready') }}</p>
                <p class="mt-2 text-lg font-semibold {{ $readiness['summary']['is_ready'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                    {{ $readiness['summary']['is_ready'] ? __('Yes') : __('No') }}
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex flex-wrap gap-3">
                @foreach (['checklist' => __('Checklist'), 'exceptions' => __('Exceptions'), 'history' => __('History')] as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" class="rounded-md px-3 py-2 text-sm font-semibold {{ $tab === $key ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($tab === 'checklist')
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Task') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Notes') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($readiness['items'] as $item)
                                <tr>
                                    <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $item->task_name }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline($item->task_type) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline($item->status) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $item->notes ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right text-sm">
                                        @if ($item->task_type === 'manual')
                                            <div class="flex justify-end gap-2">
                                                @if ($item->status !== 'complete')
                                                    <flux:button size="xs" type="button" wire:click="completeTask({{ $item->id }})">{{ __('Mark Complete') }}</flux:button>
                                                @else
                                                    <flux:button size="xs" type="button" wire:click="resetTask({{ $item->id }})" variant="ghost">{{ __('Reset') }}</flux:button>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($tab === 'exceptions')
                <div class="space-y-3">
                    @forelse ($readiness['exceptions'] as $exception)
                        <div class="rounded-md border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-amber-900 dark:text-amber-100">{{ $exception['task_name'] }}</p>
                                    <p class="text-sm text-amber-800 dark:text-amber-200">{{ $exception['message'] }}</p>
                                </div>
                                <div class="text-sm font-semibold text-amber-900 dark:text-amber-100">{{ $exception['count'] }}</div>
                            </div>
                            @if (! empty($exception['details']))
                                <div class="mt-3 text-sm text-amber-900 dark:text-amber-100">
                                    {{ collect($exception['details'])->pluck('label')->implode(', ') }}
                                </div>
                            @endif
                            @if (! empty($exception['route']))
                                <div class="mt-3">
                                    <flux:button size="xs" :href="$exception['route']" wire:navigate>{{ __('Open Related Area') }}</flux:button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-6 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                            {{ __('No blocking exceptions. The period is clear from system-detected blockers.') }}
                        </div>
                    @endforelse
                </div>
            @endif

            @if ($tab === 'history')
                <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($history as $entry)
                        <div class="flex items-start justify-between gap-4 py-3">
                            <div>
                                <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $entry->label }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $entry->meta ?: '—' }}</p>
                            </div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ optional($entry->created_at)->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <div class="py-4 text-sm text-neutral-600 dark:text-neutral-300">{{ __('No history found for this period.') }}</div>
                    @endforelse
                </div>
            @endif
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Close Period') }}</h2>
                <flux:textarea wire:model="close_note" :label="__('Close Note')" rows="3" />
                <div class="flex justify-end">
                    <flux:button
                        type="button"
                        wire:click="closePeriod"
                        wire:confirm="{{ __('Close this accounting period? This will block postings for the period.') }}"
                        :disabled="! $readiness['summary']['is_ready'] || $readiness['period']->status === 'closed'"
                    >
                        {{ __('Close Period') }}
                    </flux:button>
                </div>
            </div>

            @if (auth()->user()?->hasRole('admin'))
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Reopen Period') }}</h2>
                    <flux:textarea wire:model="reopen_reason" :label="__('Reopen Reason')" rows="3" />
                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" wire:model="move_lock_date_back" class="rounded border-neutral-300 dark:border-neutral-700" />
                        <span>{{ __('Move the finance lock date back to the previous closed period') }}</span>
                    </label>
                    <div class="flex justify-end">
                        <flux:button
                            type="button"
                            wire:click="reopenPeriod"
                            wire:confirm="{{ __('Reopen this accounting period?') }}"
                            variant="ghost"
                            :disabled="$readiness['period']->status !== 'closed'"
                        >
                            {{ __('Reopen Period') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
