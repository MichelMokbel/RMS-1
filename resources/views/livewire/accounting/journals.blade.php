<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Job;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $selected_journal_id = null;
    public ?int $company_id = null;
    public string $entry_date = '';
    public string $entry_type = 'manual';
    public ?string $memo = null;
    public array $lines = [];

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id')
            ?: AccountingCompany::query()->value('id');
        $this->entry_date = now()->toDateString();
        $this->resetForm();
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->lines = [$this->emptyLine(), $this->emptyLine()];
        }
    }

    public function resetForm(): void
    {
        $this->selected_journal_id = null;
        $this->entry_date = now()->toDateString();
        $this->entry_type = 'manual';
        $this->memo = null;
        $this->lines = [$this->emptyLine(), $this->emptyLine()];
        $this->resetErrorBag();
    }

    public function editJournal(int $journalId): void
    {
        $journal = JournalEntry::query()->with('lines')->findOrFail($journalId);
        if ($journal->status !== 'draft') {
            session()->flash('status', __('Only draft journal entries can be edited.'));
            return;
        }

        $this->selected_journal_id = $journal->id;
        $this->company_id = (int) $journal->company_id;
        $this->entry_date = optional($journal->entry_date)->toDateString() ?? now()->toDateString();
        $this->entry_type = (string) $journal->entry_type;
        $this->memo = $journal->memo;
        $this->lines = $journal->lines->map(fn ($line) => [
            'account_id' => (int) $line->account_id,
            'branch_id' => $line->branch_id ? (int) $line->branch_id : null,
            'department_id' => $line->department_id ? (int) $line->department_id : null,
            'job_id' => $line->job_id ? (int) $line->job_id : null,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
            'memo' => $line->memo,
        ])->all();
    }

    public function saveDraft(JournalEntryService $service): void
    {
        $payload = $this->validate($this->rules());
        $journal = $service->saveDraft(
            $payload,
            (int) Auth::id(),
            $this->selected_journal_id ? JournalEntry::query()->findOrFail($this->selected_journal_id) : null
        );

        $this->selected_journal_id = (int) $journal->id;
        session()->flash('status', __('Journal draft saved.'));
    }

    public function saveAndPost(JournalEntryService $service): void
    {
        $payload = $this->validate($this->rules());
        $journal = $service->saveDraft(
            $payload,
            (int) Auth::id(),
            $this->selected_journal_id ? JournalEntry::query()->findOrFail($this->selected_journal_id) : null
        );
        $service->post($journal, (int) Auth::id());

        session()->flash('status', __('Journal entry posted.'));
        $this->resetForm();
    }

    public function postJournal(int $journalId, JournalEntryService $service): void
    {
        $service->post(JournalEntry::query()->findOrFail($journalId), (int) Auth::id());
        session()->flash('status', __('Journal entry posted.'));

        if ($this->selected_journal_id === $journalId) {
            $this->resetForm();
        }
    }

    public function reverseJournal(int $journalId, JournalEntryService $service): void
    {
        $service->reverse(JournalEntry::query()->findOrFail($journalId), (int) Auth::id(), now()->toDateString());
        session()->flash('status', __('Reversal journal created and posted.'));
    }

    public function with(): array
    {
        return [
            'journals' => Schema::hasTable('journal_entries')
                ? JournalEntry::query()
                    ->with(['lines.account', 'company', 'period'])
                    ->withCount('lines')
                    ->latest('entry_date')
                    ->limit(100)
                    ->get()
                : collect(),
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'accounts' => Schema::hasTable('ledger_accounts')
                ? LedgerAccount::query()->where('is_active', true)->orderBy('code')->get()
                : collect(),
            'branches' => Schema::hasTable('branches') ? Branch::query()->orderBy('name')->get() : collect(),
            'departments' => Schema::hasTable('departments') ? Department::query()->orderBy('name')->get() : collect(),
            'jobs' => Schema::hasTable('accounting_jobs') ? Job::query()->orderBy('name')->get() : collect(),
        ];
    }

    private function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'entry_date' => ['required', 'date'],
            'entry_type' => ['required', 'in:manual,adjustment,reversal,recurring'],
            'memo' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
            'lines.*.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lines.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'lines.*.job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function emptyLine(): array
    {
        return [
            'account_id' => null,
            'branch_id' => null,
            'department_id' => null,
            'job_id' => null,
            'debit' => 0,
            'credit' => 0,
            'memo' => null,
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Journals') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Create balanced manual journals, post them into the ledger, and reverse posted entries when needed.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button type="button" wire:click="resetForm" variant="ghost">{{ __('New Journal') }}</flux:button>
            <flux:button :href="route('accounting.dashboard')" wire:navigate variant="ghost">{{ __('Back to Accounting') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="saveDraft" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
                    <select wire:model="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
                <flux:input wire:model="entry_date" type="date" :label="__('Entry Date')" />
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Type') }}</label>
                    <select wire:model="entry_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="manual">{{ __('Manual') }}</option>
                        <option value="adjustment">{{ __('Adjustment') }}</option>
                        <option value="recurring">{{ __('Recurring') }}</option>
                    </select>
                </div>
                <flux:input :value="$selected_journal_id ? __('Editing draft # :id', ['id' => $selected_journal_id]) : __('New draft')" :label="__('Context')" disabled />
            </div>

            <flux:textarea wire:model="memo" :label="__('Memo')" rows="2" />
            @error('lines') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Journal Lines') }}</h2>
                <flux:button type="button" wire:click="addLine">{{ __('Add Line') }}</flux:button>
            </div>

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="grid gap-3 rounded-lg border border-neutral-200 p-3 md:grid-cols-12 dark:border-neutral-700">
                        <div class="md:col-span-3">
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Account') }}</label>
                            <select wire:model="lines.{{ $index }}.account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Select account') }}</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model="lines.{{ $index }}.debit" type="number" step="0.01" min="0" :label="__('Debit')" />
                        </div>
                        <div class="md:col-span-2">
                            <flux:input wire:model="lines.{{ $index }}.credit" type="number" step="0.01" min="0" :label="__('Credit')" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                            <select wire:model="lines.{{ $index }}.branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('None') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Department') }}</label>
                            <select wire:model="lines.{{ $index }}.department_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('None') }}</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-1 flex items-end justify-end">
                            <flux:button type="button" wire:click="removeLine({{ $index }})" variant="ghost">{{ __('Remove') }}</flux:button>
                        </div>
                        <div class="md:col-span-3">
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Job') }}</label>
                            <select wire:model="lines.{{ $index }}.job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('None') }}</option>
                                @foreach($jobs as $job)
                                    <option value="{{ $job->id }}">{{ $job->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-8">
                            <flux:input wire:model="lines.{{ $index }}.memo" :label="__('Line Memo')" />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-8 border-t border-neutral-200 pt-4 text-sm dark:border-neutral-700">
                <div class="text-right">
                    <p class="text-neutral-500">{{ __('Total Debits') }}</p>
                    <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) collect($lines)->sum(fn ($line) => (float) ($line['debit'] ?? 0)), 2) }}</p>
                </div>
                <div class="text-right">
                    <p class="text-neutral-500">{{ __('Total Credits') }}</p>
                    <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) collect($lines)->sum(fn ($line) => (float) ($line['credit'] ?? 0)), 2) }}</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button type="button" wire:click="resetForm" variant="ghost">{{ __('Reset') }}</flux:button>
            <flux:button type="submit" variant="ghost">{{ __('Save Draft') }}</flux:button>
            <flux:button type="button" wire:click="saveAndPost">{{ __('Save and Post') }}</flux:button>
        </div>
    </form>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Entry #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Lines') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Debit') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Credit') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse($journals as $journal)
                        <tr>
                            <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $journal->entry_number }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $journal->entry_date?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ \Illuminate\Support\Str::headline((string) $journal->entry_type) }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ \Illuminate\Support\Str::headline((string) $journal->status) }}</td>
                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $journal->lines_count }}</td>
                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $journal->lines->sum('debit'), 2) }}</td>
                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $journal->lines->sum('credit'), 2) }}</td>
                            <td class="px-3 py-2 text-right text-sm">
                                <div class="flex justify-end gap-2">
                                    @if($journal->status === 'draft')
                                        <flux:button type="button" wire:click="editJournal({{ $journal->id }})" variant="ghost" size="sm">{{ __('Edit') }}</flux:button>
                                        <flux:button type="button" wire:click="postJournal({{ $journal->id }})" size="sm">{{ __('Post') }}</flux:button>
                                    @elseif($journal->status === 'posted')
                                        <flux:button type="button" wire:click="reverseJournal({{ $journal->id }})" variant="ghost" size="sm">{{ __('Reverse') }}</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No journal entries found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
