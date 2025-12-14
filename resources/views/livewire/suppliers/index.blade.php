<?php

use App\Models\Supplier;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'active';
    public string $flashMessage = '';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => 'active'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'suppliers' => $this->query()->paginate(10),
        ];
    }

    public function toggleStatus(int $supplierId): void
    {
        $supplier = Supplier::findOrFail($supplierId);
        $supplier->status = $supplier->status === 'active' ? 'inactive' : 'active';
        $supplier->save();

        session()->flash('status', __('Supplier status updated.'));
    }

    public function archive(int $supplierId): void
    {
        $supplier = Supplier::findOrFail($supplierId);

        if ($supplier->isInUse()) {
            $this->addError('archive', __('Supplier is referenced and cannot be archived. Deactivate instead.'));
            return;
        }

        // No soft delete support by default; fallback to deactivate.
        $supplier->status = 'inactive';
        $supplier->save();
        session()->flash('status', __('Supplier deactivated.'));
    }

    private function query()
    {
        return Supplier::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search, function ($q) {
                $q->where(function ($inner) {
                    $inner->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('contact_person', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->orWhere('qid_cr', 'like', '%'.$this->search.'%');
                });
            })
            ->ordered();
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Suppliers') }}
        </h1>
        <flux:button :href="route('suppliers.create')" wire:navigate variant="primary">
            {{ __('Create Supplier') }}
        </flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @error('archive')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center md:gap-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search suppliers') }}"
                class="w-full md:max-w-sm"
            />

            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select
                    id="status"
                    wire:model.live="status"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Name') }}
                    </th>
                    <th class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Contact Person') }}
                    </th>
                    <th class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Email') }}
                    </th>
                    <th class="w-1/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Phone') }}
                    </th>
                    <th class="w-1/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('QID/CR') }}
                    </th>
                    <th class="w-1/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Status') }}
                    </th>
                    <th class="w-2/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Updated At') }}
                    </th>
                    <th class="w-1/12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                        {{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($suppliers as $supplier)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {{ $supplier->name }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $supplier->contact_person }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $supplier->email }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $supplier->phone }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $supplier->qid_cr }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $supplier->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                {{ ucfirst($supplier->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ optional($supplier->updated_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="xs" :href="route('suppliers.edit', $supplier)" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>

                                @if ($supplier->status === 'active')
                                    <flux:button size="xs" wire:click="toggleStatus({{ $supplier->id }})">
                                        {{ __('Deactivate') }}
                                    </flux:button>
                                @else
                                    <flux:button size="xs" variant="primary" color="emerald" wire:click="toggleStatus({{ $supplier->id }})">
                                        {{ __('Activate') }}
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No suppliers found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $suppliers->links() }}
    </div>
</div>
