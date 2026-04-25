<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'all';
    public string $verification = 'all';
    public ?int $linkingUserId = null;
    public string $linkCustomerSearch = '';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => 'all'],
        'verification' => ['except' => 'all'],
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingVerification(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'accounts' => $this->query()->paginate(15),
            'linkCandidates' => $this->linkCandidates(),
        ];
    }

    public function toggleStatus(int $userId): void
    {
        $user = User::query()
            ->whereKey($userId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        $user->update([
            'status' => $user->status === 'active' ? 'inactive' : 'active',
        ]);

        session()->flash('status', __('Customer account status updated.'));
    }

    public function startLinking(int $userId): void
    {
        $this->linkingUserId = $userId;
        $this->linkCustomerSearch = '';
    }

    public function cancelLinking(): void
    {
        $this->linkingUserId = null;
        $this->linkCustomerSearch = '';
    }

    public function linkCustomer(int $customerId): void
    {
        $user = User::query()
            ->whereKey($this->linkingUserId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        $customer = Customer::query()->whereKey($customerId)->firstOrFail();

        $existing = User::query()
            ->where('customer_id', $customer->id)
            ->whereKeyNot($user->id)
            ->exists();

        if ($existing) {
            $this->addError('linkCustomerSearch', __('This customer is already linked to another portal account.'));
            return;
        }

        $user->update(['customer_id' => $customer->id]);

        $this->cancelLinking();
        session()->flash('status', __('Customer account linked successfully.'));
    }

    public function unlinkCustomer(int $userId): void
    {
        $user = User::query()
            ->whereKey($userId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        $user->update(['customer_id' => null]);

        session()->flash('status', __('Customer account unlinked.'));
    }

    private function query()
    {
        return User::query()
            ->with('customer')
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->when($this->search, function ($query): void {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('portal_name', 'like', $term)
                        ->orWhere('portal_phone', 'like', $term)
                        ->orWhere('portal_phone_e164', 'like', $term)
                        ->orWhereHas('customer', function ($customerQuery) use ($term): void {
                            $customerQuery->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('phone', 'like', $term)
                                ->orWhere('phone_e164', 'like', $term);
                        });
                });
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->when($this->verification !== 'all', function ($query): void {
                if ($this->verification === 'verified') {
                    $query->where(function ($inner): void {
                        $inner->whereHas('customer', fn ($customerQuery) => $customerQuery->whereNotNull('phone_verified_at'))
                            ->orWhereNotNull('portal_phone_verified_at');
                    });
                } elseif ($this->verification === 'unverified') {
                    $query->where(function ($inner): void {
                        $inner->where(function ($unlinked): void {
                            $unlinked->whereNull('portal_phone_verified_at')
                                ->whereDoesntHave('customer');
                        })->orWhereHas('customer', fn ($customerQuery) => $customerQuery->whereNull('phone_verified_at'));
                    });
                }
            })
            ->orderByRaw('CASE WHEN customer_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->orderBy('email');
    }

    private function linkCandidates()
    {
        if (! $this->linkingUserId || trim($this->linkCustomerSearch) === '') {
            return collect();
        }

        $term = '%'.trim($this->linkCustomerSearch).'%';

        return Customer::query()
            ->with('user')
            ->whereDoesntHave('user')
            ->where(function ($query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('phone_e164', 'like', $term)
                    ->orWhere('customer_code', 'like', $term);
            })
            ->orderBy('name')
            ->limit(10)
            ->get();
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Accounts') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manage customer portal logins separately from backoffice IAM users.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('iam.users.index')" wire:navigate variant="ghost">{{ __('Back to IAM') }}</flux:button>
            <flux:button :href="route('customers.index')" wire:navigate variant="ghost">{{ __('Customers') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center md:gap-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search customer, email, phone, or username') }}"
                class="w-full md:max-w-sm"
            />

            <div class="flex items-center gap-2">
                <label for="account_status" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                <select
                    id="account_status"
                    wire:model.live="status"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="verification" class="text-sm text-neutral-800 dark:text-neutral-200">{{ __('Phone') }}</label>
                <select
                    id="verification"
                    wire:model.live="verification"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="all">{{ __('All') }}</option>
                    <option value="verified">{{ __('Verified') }}</option>
                    <option value="unverified">{{ __('Unverified') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                    <th class="w-64 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Login') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phone') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Verification') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Account Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Updated') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                @forelse ($accounts as $account)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-3 text-sm">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $account->customer?->name ?? $account->portal_name ?? __('Unlinked customer') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $account->customer?->customer_code ?: '—' }}</div>
                        </td>
                        <td class="w-64 px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div class="truncate" title="{{ $account->email }}">{{ $account->email ?: '—' }}</div>
                            <div class="truncate text-xs text-neutral-500 dark:text-neutral-400" title="{{ $account->username }}">{{ $account->username }}</div>
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div>{{ $account->customer?->phone ?: ($account->portal_phone ?: '—') }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $account->customer?->phone_e164 ?: ($account->portal_phone_e164 ?: '—') }}</div>
                        </td>
                        <td class="px-3 py-3 text-sm">
                            @php($verificationAt = $account->customer?->phone_verified_at ?? $account->portal_phone_verified_at)
                            @if ($verificationAt)
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                    {{ __('Verified') }}
                                </span>
                                <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $verificationAt->format('Y-m-d H:i') }}</div>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">
                                    {{ __('Pending') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                {{ ucfirst($account->status) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-sm">
                            @if ($account->customer)
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $account->customer->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                                    {{ $account->customer->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-sky-100 px-2 py-1 text-xs font-semibold text-sky-800 dark:bg-sky-900 dark:text-sky-100">
                                    {{ __('Unlinked') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ optional($account->updated_at)->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm">
                            <div class="flex flex-wrap gap-2">
                                @if ($account->customer)
                                    <flux:button size="xs" :href="route('customers.edit', $account->customer)" wire:navigate>
                                        {{ __('Edit Customer') }}
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        wire:click="unlinkCustomer({{ $account->id }})"
                                    >
                                        {{ __('Unlink Customer') }}
                                    </flux:button>
                                @else
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        wire:click="startLinking({{ $account->id }})"
                                    >
                                        {{ __('Link Customer') }}
                                    </flux:button>
                                @endif
                                <flux:button
                                    size="xs"
                                    variant="{{ $account->status === 'active' ? 'danger' : 'primary' }}"
                                    wire:click="toggleStatus({{ $account->id }})"
                                >
                                    {{ $account->status === 'active' ? __('Deactivate Login') : __('Activate Login') }}
                                </flux:button>
                            </div>
                            @if ($linkingUserId === $account->id)
                                <div class="mt-3 space-y-2 rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                    <flux:input
                                        wire:model.live.debounce.300ms="linkCustomerSearch"
                                        placeholder="{{ __('Search customer by name, email, phone, or code') }}"
                                    />
                                    @error('linkCustomerSearch')
                                        <div class="text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div>
                                    @enderror
                                    <div class="space-y-2">
                                        @forelse ($linkCandidates as $candidate)
                                            <button
                                                type="button"
                                                class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-700"
                                                wire:click="linkCustomer({{ $candidate->id }})"
                                            >
                                                <div class="font-medium">{{ $candidate->name }}</div>
                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                    {{ $candidate->customer_code ?: '—' }} · {{ $candidate->email ?: '—' }} · {{ $candidate->phone ?: '—' }}
                                                </div>
                                            </button>
                                        @empty
                                            <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Search to find an unlinked customer record.') }}</div>
                                        @endforelse
                                    </div>
                                    <flux:button size="xs" variant="ghost" wire:click="cancelLinking">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No customer accounts found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $accounts->links() }}
    </div>
</div>
