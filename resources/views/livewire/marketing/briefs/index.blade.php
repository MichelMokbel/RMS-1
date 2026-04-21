<?php

use App\Models\MarketingBrief;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }

    public function with(): array
    {
        return [
            'briefs' => MarketingBrief::query()
                ->with(['campaign', 'createdBy'])
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->when($this->status, fn ($q) => $q->withStatus($this->status))
                ->orderByDesc('created_at')
                ->paginate(20),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Briefs') }}</h1>
        <flux:button :href="route('marketing.briefs.create')" variant="primary" icon="plus" wire:navigate>
            {{ __('New Brief') }}
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search briefs…') }}" icon="magnifying-glass" />
            </div>
            <select wire:model.live="status" class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white">
                <option value="">{{ __('All Statuses') }}</option>
                <option value="draft">{{ __('Draft') }}</option>
                <option value="pending_review">{{ __('Pending Review') }}</option>
                <option value="approved">{{ __('Approved') }}</option>
                <option value="rejected">{{ __('Rejected') }}</option>
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        @if($briefs->isEmpty())
            <div class="px-4 py-16 text-center">
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('No briefs yet') }}</p>
                <p class="mt-1 text-sm text-zinc-500">
                    @if($search || $status)
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __('Create a brief to track campaign creative requirements.') }}
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Title') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Campaign') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Due') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Created by') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach($briefs as $brief)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $brief->title }}</td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                    {{ $brief->campaign?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        @if($brief->status === 'approved') bg-emerald-100 text-emerald-700
                                        @elseif($brief->status === 'pending_review') bg-amber-100 text-amber-700
                                        @elseif($brief->status === 'rejected') bg-red-100 text-red-700
                                        @else bg-zinc-100 text-zinc-600 @endif">
                                        {{ ucwords(str_replace('_', ' ', $brief->status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                    {{ $brief->due_date?->format('M d, Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-zinc-500">{{ $brief->createdBy?->username ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button :href="route('marketing.briefs.show', $brief)" variant="ghost" size="sm" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $briefs->links('pagination::tailwind') }}
            </div>
        @endif
    </div>
</div>
