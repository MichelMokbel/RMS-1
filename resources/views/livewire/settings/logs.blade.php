<?php

use App\Models\EmailLog;
use App\Models\OpsEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $tab = 'ops';
    public string $opsEventType = '';
    public string $opsDateFrom = '';
    public string $opsDateTo = '';

    public string $emailRecipientType = 'all';
    public string $emailStatus = 'all';
    public string $emailCategory = 'all';
    public string $emailDateFrom = '';
    public string $emailDateTo = '';
    public string $emailSubject = '';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'tab' => ['except' => 'ops'],
        'opsEventType' => ['except' => ''],
        'opsDateFrom' => ['except' => ''],
        'opsDateTo' => ['except' => ''],
        'emailRecipientType' => ['except' => 'all'],
        'emailStatus' => ['except' => 'all'],
        'emailCategory' => ['except' => 'all'],
        'emailDateFrom' => ['except' => ''],
        'emailDateTo' => ['except' => ''],
        'emailSubject' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->authorizeAccess();
        if (! in_array($this->tab, ['ops', 'emails'], true)) {
            $this->tab = 'ops';
        }
    }

    public function with(): array
    {
        $this->authorizeAccess();

        return [
            'opsEvents' => OpsEvent::query()
                ->when($this->opsEventType !== '', fn ($query) => $query->where('event_type', $this->opsEventType))
                ->when($this->opsDateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->opsDateFrom))
                ->when($this->opsDateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->opsDateTo))
                ->latest('created_at')
                ->paginate(15, ['*'], 'opsPage'),
            'emailLogs' => EmailLog::query()
                ->when($this->emailRecipientType !== 'all', fn ($query) => $query->where('recipient_type', $this->emailRecipientType))
                ->when($this->emailStatus !== 'all', fn ($query) => $query->where('status', $this->emailStatus))
                ->when($this->emailCategory !== 'all', fn ($query) => $query->where('category', $this->emailCategory))
                ->when($this->emailDateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->emailDateFrom))
                ->when($this->emailDateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->emailDateTo))
                ->when($this->emailSubject !== '', fn ($query) => $query->where('subject', 'like', '%'.$this->emailSubject.'%'))
                ->latest('created_at')
                ->paginate(15, ['*'], 'emailPage'),
            'opsEventTypes' => OpsEvent::query()
                ->select('event_type')
                ->distinct()
                ->orderBy('event_type')
                ->pluck('event_type'),
            'emailCategories' => EmailLog::query()
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
        ];
    }

    public function updatingOpsEventType(): void
    {
        $this->resetPage('opsPage');
    }

    public function updatingOpsDateFrom(): void
    {
        $this->resetPage('opsPage');
    }

    public function updatingOpsDateTo(): void
    {
        $this->resetPage('opsPage');
    }

    public function updatingEmailRecipientType(): void
    {
        $this->resetPage('emailPage');
    }

    public function updatingEmailStatus(): void
    {
        $this->resetPage('emailPage');
    }

    public function updatingEmailCategory(): void
    {
        $this->resetPage('emailPage');
    }

    public function updatingEmailDateFrom(): void
    {
        $this->resetPage('emailPage');
    }

    public function updatingEmailDateTo(): void
    {
        $this->resetPage('emailPage');
    }

    public function updatingEmailSubject(): void
    {
        $this->resetPage('emailPage');
    }

    public function showTab(string $tab): void
    {
        if (! in_array($tab, ['ops', 'emails'], true)) {
            return;
        }

        $this->tab = $tab;
    }

    private function authorizeAccess(): void
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof \App\Models\User
            && ($user->hasAnyRole(['admin', 'manager']) || $user->can('finance.access')),
            403
        );
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Logs')" :subheading="__('Inspect operational events and outbound email activity.')" contentClass="mt-5 w-full">
        <div class="space-y-6">
            <div class="flex flex-wrap gap-2 rounded-xl border border-neutral-200 bg-white p-2 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <button
                    type="button"
                    wire:click="showTab('ops')"
                    class="{{ $tab === 'ops' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-transparent text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800' }} rounded-lg px-4 py-2 text-sm font-medium transition"
                >
                    {{ __('Ops Events') }}
                </button>
                <button
                    type="button"
                    wire:click="showTab('emails')"
                    class="{{ $tab === 'emails' ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-transparent text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800' }} rounded-lg px-4 py-2 text-sm font-medium transition"
                >
                    {{ __('Email Logs') }}
                </button>
            </div>

            @if ($tab === 'ops')
            <section class="space-y-4">
                <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Ops Events') }}</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Review customer portal submissions and operational events written to ops_events.') }}</p>
                    </div>
                </div>

                <div class="grid gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Event Type') }}</label>
                        <select wire:model.live="opsEventType" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('All') }}</option>
                            @foreach ($opsEventTypes as $eventType)
                                <option value="{{ $eventType }}">{{ $eventType }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('From') }}</label>
                        <input wire:model.live="opsDateFrom" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('To') }}</label>
                        <input wire:model.live="opsDateTo" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div class="rounded-lg bg-neutral-50 px-4 py-3 text-sm dark:bg-neutral-800/70">
                        <div class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Visible Rows') }}</div>
                        <div class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $opsEvents->total() }}</div>
                    </div>
                </div>

                <div class="app-table-shell">
                    <table class="w-full min-w-full divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Event') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actor / Links') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Service Date') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Metadata') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                            @forelse ($opsEvents as $event)
                                <tr class="align-top">
                                    <td class="px-3 py-3 text-sm">
                                        <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $event->event_type }}</div>
                                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">#{{ $event->id }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div>{{ __('Actor') }}: {{ $event->actor_user_id ?: '—' }}</div>
                                        <div>{{ __('Branch') }}: {{ $event->branch_id ?: '—' }}</div>
                                        <div>{{ __('Order') }}: {{ $event->order_id ?: '—' }}</div>
                                        <div>{{ __('Item') }}: {{ $event->order_item_id ?: '—' }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ optional($event->service_date)->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ optional($event->created_at)->format('Y-m-d H:i:s') ?? '—' }}</td>
                                    <td class="px-3 py-3 text-sm">
                                        @if (!empty($event->metadata_json))
                                            <details class="group">
                                                <summary class="cursor-pointer text-sm font-medium text-primary-600 dark:text-primary-400">{{ __('View metadata') }}</summary>
                                                <pre class="mt-2 max-w-[28rem] overflow-x-auto rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">{{ json_encode($event->metadata_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @else
                                            <span class="text-neutral-400 dark:text-neutral-500">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No ops events match the current filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $opsEvents->links() }}
            </section>
            @endif

            @if ($tab === 'emails')
            <section class="space-y-4">
                <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Email Logs') }}</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Track sent, failed, and skipped outbound emails for customer portal orders.') }}</p>
                    </div>
                </div>

                <div class="grid gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 md:grid-cols-6">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Recipient') }}</label>
                        <select wire:model.live="emailRecipientType" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="all">{{ __('All') }}</option>
                            <option value="admin">{{ __('Admin') }}</option>
                            <option value="customer">{{ __('Customer') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                        <select wire:model.live="emailStatus" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="all">{{ __('All') }}</option>
                            <option value="sent">{{ __('Sent') }}</option>
                            <option value="failed">{{ __('Failed') }}</option>
                            <option value="skipped">{{ __('Skipped') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Category') }}</label>
                        <select wire:model.live="emailCategory" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="all">{{ __('All') }}</option>
                            @foreach ($emailCategories as $category)
                                <option value="{{ $category }}">{{ $category }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('From') }}</label>
                        <input wire:model.live="emailDateFrom" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('To') }}</label>
                        <input wire:model.live="emailDateTo" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Subject') }}</label>
                        <input wire:model.live.debounce.300ms="emailSubject" type="text" placeholder="{{ __('Search subject') }}" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                    </div>
                </div>

                <div class="app-table-shell">
                    <table class="w-full min-w-full divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Subject / Recipients') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Context') }}</th>
                                <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                            @forelse ($emailLogs as $log)
                                <tr class="align-top">
                                    <td class="px-3 py-3 text-sm">
                                        <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $log->category }}</div>
                                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $log->recipient_type }} · {{ class_basename($log->mailable) }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $log->subject ?: '—' }}</div>
                                        <div class="mt-1 break-all text-xs text-neutral-500 dark:text-neutral-400">{{ implode(', ', $log->to_recipients ?? []) ?: '—' }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-sm">
                                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $log->status === 'sent' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : ($log->status === 'failed' ? 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100') }}">
                                            {{ ucfirst($log->status) }}
                                        </span>
                                        @if ($log->error_message)
                                            <div class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $log->error_message }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div>{{ __('Mailer') }}: {{ $log->mailer ?: '—' }}</div>
                                        <div>{{ __('User') }}: {{ $log->user_id ?: '—' }}</div>
                                        <div>{{ __('Order') }}: {{ $log->order_id ?: '—' }}</div>
                                        <div>{{ __('Request') }}: {{ $log->meal_plan_request_id ?: '—' }}</div>
                                        @if (!empty($log->context))
                                            <details class="group mt-2">
                                                <summary class="cursor-pointer text-sm font-medium text-primary-600 dark:text-primary-400">{{ __('View context') }}</summary>
                                                <pre class="mt-2 max-w-[28rem] overflow-x-auto rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div>{{ optional($log->created_at)->format('Y-m-d H:i:s') ?? '—' }}</div>
                                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $log->sent_at ? __('Sent at: :time', ['time' => $log->sent_at->format('Y-m-d H:i:s')]) : '—' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No email logs match the current filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $emailLogs->links() }}
            </section>
            @endif
        </div>
    </x-settings.layout>
</section>
