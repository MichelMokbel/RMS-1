<?php

use App\Models\Branch;
use App\Models\Quotation;
use App\Services\Quotations\QuotationDraftService;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $branch = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
        'branch' => ['except' => ''],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('quotations.access'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'branch'], true)) {
            $this->resetPage();
        }
    }

    public function duplicate(int $quotationId, QuotationDraftService $drafts, BranchAccessService $access): void
    {
        abort_unless(auth()->user()?->can('quotations.manage'), 403);
        $quotation = $this->findAllowed($quotationId, $access);
        $copy = $drafts->duplicate(auth()->user(), $quotation);
        session()->flash('status', __('Quotation duplicated as a new draft.'));
        $this->redirect(route('quotations.edit', $copy), navigate: true);
    }

    public function with(BranchAccessService $access): array
    {
        $user = auth()->user();
        $query = Quotation::query()->with(['branch', 'company', 'customer']);
        $access->applyBranchScope($query, $user);

        $quotations = $query
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->branch !== '', fn (Builder $q) => $q->where('branch_id', (int) $this->branch))
            ->when(trim($this->search) !== '', function (Builder $q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('quotation_number', 'like', $term)
                        ->orWhere('recipient_name', 'like', $term)
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(25);

        $branches = Branch::query()
            ->whereIn('id', $access->allowedBranchIds($user))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return compact('quotations', 'branches');
    }

    private function findAllowed(int $id, BranchAccessService $access): Quotation
    {
        $query = Quotation::query();
        $access->applyBranchScope($query, auth()->user());

        return $query->findOrFail($id);
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-white">{{ __('Quotations') }}</h1>
            <p class="mt-1 text-sm text-neutral-500">{{ __('Create, revise and convert customer quotations.') }}</p>
        </div>
        @can('quotations.manage')
            <flux:button :href="route('quotations.create')" wire:navigate variant="primary" icon="plus">{{ __('New quotation') }}</flux:button>
        @endcan
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</div>
    @endif

    <div class="grid gap-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.350ms="search" :label="__('Search')" placeholder="{{ __('Number, customer or prospect') }}" icon="magnifying-glass" />
        <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
            <select wire:model.live="status" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['draft', 'sent', 'accepted', 'rejected', 'expired'] as $option)
                    <option value="{{ $option }}">{{ __(ucfirst($option)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
            <select wire:model.live="branch" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                <option value="">{{ __('All permitted branches') }}</option>
                @foreach ($branches as $branchOption)
                    <option value="{{ $branchOption->id }}">{{ $branchOption->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                    <tr><th class="px-4 py-3">{{ __('Quotation') }}</th><th class="px-4 py-3">{{ __('Recipient') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($quotations as $quotation)
                        @php
                            $statusClass = match ($quotation->status) {
                                'accepted' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
                                'sent' => 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
                                'rejected', 'expired' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
                                default => 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
                            };
                        @endphp
                        <tr wire:key="quotation-{{ $quotation->id }}" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/60">
                            <td class="px-4 py-3"><a href="{{ route('quotations.show', $quotation) }}" wire:navigate class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ $quotation->number ?: __('Draft #:id', ['id' => $quotation->id]) }}</a><div class="text-xs text-neutral-500">{{ optional($quotation->issue_date)->format('d M Y') }} · {{ __('Revision :revision', ['revision' => max(1, (int) $quotation->current_revision)]) }}</div></td>
                            <td class="px-4 py-3">{{ $quotation->customer?->name ?: $quotation->recipient_name ?: __('Prospect') }}</td>
                            <td class="px-4 py-3">{{ $quotation->branch?->name }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClass }}">{{ __(ucfirst($quotation->status)) }}</span></td>
                            <td class="px-4 py-3 text-right font-medium">{{ number_format(((int) $quotation->total_minor) / 100, 2) }} QAR</td>
                            <td class="px-4 py-3"><div class="flex justify-end gap-2"><flux:button :href="route('quotations.show', $quotation)" wire:navigate size="xs" variant="ghost">{{ __('View') }}</flux:button>@can('quotations.manage')<flux:button wire:click="duplicate({{ $quotation->id }})" wire:confirm="{{ __('Create a new draft copy?') }}" size="xs" variant="ghost">{{ __('Duplicate') }}</flux:button>@endcan</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-neutral-500">{{ __('No quotations match these filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($quotations->hasPages()) <div class="border-t border-neutral-200 p-4 dark:border-neutral-700">{{ $quotations->links() }}</div> @endif
    </div>
</div>
