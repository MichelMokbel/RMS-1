<?php

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Customers\CustomerMatchReviewService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = 'all';
    public string $verification = 'all';
    public string $reviewStatus = 'pending';
    public array $reviewNotes = [];
    public ?int $linkingUserId = null;
    public string $linkCustomerSearch = '';

    protected $paginationTheme = 'tailwind';
    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => 'all'],
        'verification' => ['except' => 'all'],
        'reviewStatus' => ['except' => 'pending'],
    ];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

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

    public function updatingReviewStatus(): void
    {
        $this->resetPage('reviewsPage');
    }

    public function with(): array
    {
        return [
            'accounts' => $this->query()->paginate(15),
            'linkCandidates' => $this->linkCandidates(),
            'reviews' => $this->reviewQuery()->paginate(10, ['*'], 'reviewsPage'),
        ];
    }

    public function toggleStatus(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::query()
            ->whereKey($userId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        if ($user->status !== 'active' && Schema::hasTable('accounting_audit_logs')) {
            $disabledByMerge = DB::table('accounting_audit_logs')
                ->where('action', 'customer.merged')
                ->where('payload->disabled_portal_user_id', $user->id)
                ->exists();
            if ($disabledByMerge) {
                $this->addError('account', __('A login disabled by a customer merge cannot be reactivated.'));
                return;
            }
        }

        $user->update([
            'status' => $user->status === 'active' ? 'inactive' : 'active',
        ]);

        session()->flash('status', __('Customer account status updated.'));
    }

    public function startLinking(int $userId): void
    {
        $this->authorizeAdmin();
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
        $this->authorizeAdmin();
        $user = User::query()
            ->whereKey($this->linkingUserId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        if ($user->customer_id !== null) {
            $this->addError('linkCustomerSearch', __('Use the customer merge flow to change an existing owner.'));
            return;
        }

        $customer = Customer::query()->whereKey($customerId)->firstOrFail();

        $existing = User::query()
            ->where('customer_id', $customer->id)
            ->whereKeyNot($user->id)
            ->exists();

        if ($existing) {
            $this->addError('linkCustomerSearch', __('This customer is already linked to another portal account.'));
            return;
        }

        DB::transaction(function () use ($user, $customer): void {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($lockedUser->customer_id !== null
                || User::query()->where('customer_id', $lockedCustomer->id)->whereKeyNot($lockedUser->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'linkCustomerSearch' => __('This customer account changed. Refresh and try again.'),
                ]);
            }
            $lockedUser->forceFill(['customer_id' => $lockedCustomer->id])->save();
            app(AccountingAuditLogService::class)->log('customer.account.linked', Auth::id(), $lockedCustomer, [
                'user_id' => (int) $lockedUser->id,
                'customer_id' => (int) $lockedCustomer->id,
            ]);
        }, 3);

        $this->cancelLinking();
        session()->flash('status', __('Customer account linked successfully.'));
    }

    public function unlinkCustomer(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::query()
            ->whereKey($userId)
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer')->where('guard_name', 'web'))
            ->firstOrFail();

        if ($user->customer_id !== null && $this->hasOwnedActivity($user)) {
            $this->addError('account', __('This account has customer activity. Use the customer merge flow instead of unlinking it.'));
            return;
        }

        DB::transaction(function () use ($user): void {
            $customer = $user->customer()->first();
            $user->forceFill(['customer_id' => null])->save();
            app(AccountingAuditLogService::class)->log('customer.account.unlinked', Auth::id(), $customer, [
                'user_id' => (int) $user->id,
                'customer_id' => $customer?->id,
            ]);
        });

        session()->flash('status', __('Customer account unlinked.'));
    }

    public function markReviewDifferent(int $reviewId): void
    {
        $this->authorizeAdmin();
        app(CustomerMatchReviewService::class)->markDifferent(
            $reviewId,
            Auth::user(),
            $this->reviewNotes[$reviewId] ?? null,
        );
        unset($this->reviewNotes[$reviewId]);
        session()->flash('status', __('Possible duplicate marked as a different customer.'));
    }

    public function mergeReview(int $reviewId): void
    {
        $this->authorizeAdmin();
        app(CustomerMatchReviewService::class)->merge(
            $reviewId,
            Auth::user(),
            $this->reviewNotes[$reviewId] ?? null,
        );
        unset($this->reviewNotes[$reviewId]);
        session()->flash('status', __('Customer records merged. The destination customer and its login were preserved.'));
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

    private function reviewQuery()
    {
        return CustomerMatchReview::query()
            ->with(['user', 'customer', 'candidateCustomer', 'reviewer'])
            ->when($this->reviewStatus !== 'all', fn ($query) => $query->where('status', $this->reviewStatus))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('created_at');
    }

    private function hasOwnedActivity(User $user): bool
    {
        foreach (['orders', 'meal_plan_requests'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')
                && DB::table($table)->where('user_id', $user->id)->exists()) {
                return true;
            }
        }
        foreach (['orders', 'meal_plan_requests', 'ar_invoices', 'payments', 'meal_subscriptions', 'payment_checkout_attempts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'customer_id')
                && DB::table($table)->where('customer_id', $user->customer_id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function authorizeAdmin(): void
    {
        $actor = Auth::user();
        abort_unless($actor && $actor->isActive() && $actor->isAdmin(), 403);
    }
}; ?>

<div class="app-page space-y-6">
    <style>
        .customer-accounts-mobile {
            display: block;
        }

        .customer-accounts-desktop {
            display: none;
        }

        @media (min-width: 768px) {
            .customer-accounts-mobile {
                display: none;
            }

            .customer-accounts-desktop {
                display: block;
            }
        }
    </style>

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

    @error('account')
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100" role="alert">
            {{ $message }}
        </div>
    @enderror

    <section class="space-y-4" aria-labelledby="possible-duplicates-title">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <h2 id="possible-duplicates-title" class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Possible Duplicates') }}</h2>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('These suggestions never block the customer. Merge only after you confirm both records belong to the same person.') }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-neutral-800 dark:text-neutral-200">
                <span>{{ __('Review status') }}</span>
                <select
                    wire:model.live="reviewStatus"
                    class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                >
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="merged">{{ __('Merged') }}</option>
                    <option value="different">{{ __('Different customer') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </label>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            @forelse ($reviews as $review)
                <article class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900" wire:key="customer-match-review-{{ $review->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Portal customer') }}</div>
                            <h3 class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $review->customer?->name ?: __('Unavailable customer') }}</h3>
                            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $review->customer?->customer_code ?: '—' }} · {{ $review->customer?->phone ?: '—' }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $review->status === 'pending' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' : ($review->status === 'merged' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200') }}">
                            {{ $review->status === 'different' ? __('Different customer') : ucfirst($review->status) }}
                        </span>
                    </div>

                    <div class="my-4 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950/50">
                        <div class="text-xs font-medium uppercase tracking-wide text-sky-700 dark:text-sky-200">{{ __('Suggested existing customer') }}</div>
                        <div class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $review->candidateCustomer?->name ?: __('Unavailable customer') }}</div>
                        <div class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                            {{ $review->candidateCustomer?->customer_code ?: '—' }} · {{ $review->candidateCustomer?->phone ?: '—' }}
                        </div>
                    </div>

                    <div class="space-y-2 text-sm text-neutral-600 dark:text-neutral-300">
                        <p><span class="font-medium text-neutral-800 dark:text-neutral-100">{{ __('Reasons') }}:</span> {{ collect($review->reason_codes)->map(fn ($reason) => __(str_replace('_', ' ', ucfirst($reason))))->implode(', ') }}</p>
                        @if (is_array($review->ai_suggestion))
                            <p><span class="font-medium text-neutral-800 dark:text-neutral-100">{{ __('AI name check') }}:</span> {{ __(str_replace('_', ' ', ucfirst($review->ai_suggestion['reason'] ?? 'uncertain'))) }} · {{ number_format(((float) ($review->ai_suggestion['similarity'] ?? 0)) * 100, 0) }}%</p>
                        @endif
                        @if ($review->reviewed_at)
                            <p><span class="font-medium text-neutral-800 dark:text-neutral-100">{{ __('Resolved') }}:</span> {{ $review->reviewed_at->format('Y-m-d H:i') }} · {{ $review->reviewer?->name ?: __('Administrator') }}</p>
                        @endif
                        @if ($review->decision_note)
                            <p><span class="font-medium text-neutral-800 dark:text-neutral-100">{{ __('Note') }}:</span> {{ $review->decision_note }}</p>
                        @endif
                    </div>

                    @if ($review->status === 'pending')
                        <div class="mt-4 space-y-3">
                            <flux:input
                                wire:model="reviewNotes.{{ $review->id }}"
                                label="{{ __('Decision note (optional)') }}"
                                placeholder="{{ __('Why these records are the same or different') }}"
                            />
                            <div class="grid gap-2 sm:grid-cols-2">
                                <flux:button
                                    class="w-full justify-center"
                                    variant="primary"
                                    wire:click="mergeReview({{ $review->id }})"
                                    wire:confirm="{{ __('Merge the portal customer into this destination? The destination customer and its login will survive.') }}"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('Merge Customers') }}
                                </flux:button>
                                <flux:button
                                    class="w-full justify-center"
                                    variant="ghost"
                                    wire:click="markReviewDifferent({{ $review->id }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('Mark as Different') }}
                                </flux:button>
                            </div>
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-neutral-200 bg-white px-4 py-8 text-center text-sm text-neutral-600 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 xl:col-span-2">
                    {{ $reviewStatus === 'pending' ? __('No possible duplicates need review.') : __('No customer reviews match this status.') }}
                </div>
            @endforelse
        </div>

        <div>{{ $reviews->links() }}</div>
    </section>

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

    <div class="customer-accounts-mobile space-y-4">
        @forelse ($accounts as $account)
            @php($verificationAt = $account->customer?->phone_verified_at ?? $account->portal_phone_verified_at)
            <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-base font-semibold text-neutral-900 dark:text-neutral-100">
                            {{ $account->customer?->name ?? $account->portal_name ?? __('Unlinked customer') }}
                        </div>
                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $account->customer?->customer_code ?: __('No customer linked yet') }}
                        </div>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full px-2 py-1 text-xs font-semibold {{ $account->customer ? ($account->customer->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100') : 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-100' }}">
                        {{ $account->customer ? ($account->customer->is_active ? __('Customer Active') : __('Customer Inactive')) : __('Unlinked') }}
                    </span>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/70">
                        <div class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Login') }}</div>
                        <div class="mt-1 break-all text-neutral-900 dark:text-neutral-100">{{ $account->email ?: '—' }}</div>
                        <div class="mt-1 break-all text-xs text-neutral-500 dark:text-neutral-400">{{ $account->username ?: '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/70">
                        <div class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Phone') }}</div>
                        <div class="mt-1 text-neutral-900 dark:text-neutral-100">{{ $account->customer?->phone ?: ($account->portal_phone ?: '—') }}</div>
                        <div class="mt-1 break-all text-xs text-neutral-500 dark:text-neutral-400">{{ $account->customer?->phone_e164 ?: ($account->portal_phone_e164 ?: '—') }}</div>
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/70">
                        <div class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Verification') }}</div>
                        @if ($verificationAt)
                            <span class="mt-1 inline-flex items-center rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                {{ __('Verified') }}
                            </span>
                            <div class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">{{ $verificationAt->format('Y-m-d H:i') }}</div>
                        @else
                            <span class="mt-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">
                                {{ __('Pending') }}
                            </span>
                        @endif
                    </div>
                    <div class="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/70">
                        <div class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Account Status') }}</div>
                        <span class="mt-1 inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $account->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100' }}">
                            {{ ucfirst($account->status) }}
                        </span>
                        <div class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Updated') }}: {{ optional($account->updated_at)->format('Y-m-d H:i') ?? '—' }}</div>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    @if ($account->customer)
                        <flux:button class="w-full justify-center" size="sm" :href="route('customers.edit', $account->customer)" wire:navigate>
                            {{ __('Edit Customer') }}
                        </flux:button>
                        <flux:button
                            class="w-full justify-center"
                            size="sm"
                            variant="ghost"
                            wire:click="unlinkCustomer({{ $account->id }})"
                        >
                            {{ __('Unlink Customer') }}
                        </flux:button>
                    @else
                        <flux:button
                            class="w-full justify-center"
                            size="sm"
                            variant="ghost"
                            wire:click="startLinking({{ $account->id }})"
                        >
                            {{ __('Link Customer') }}
                        </flux:button>
                    @endif
                    <flux:button
                        class="w-full justify-center"
                        size="sm"
                        variant="{{ $account->status === 'active' ? 'danger' : 'primary' }}"
                        wire:click="toggleStatus({{ $account->id }})"
                    >
                        {{ $account->status === 'active' ? __('Deactivate Login') : __('Activate Login') }}
                    </flux:button>
                </div>

                @if ($linkingUserId === $account->id)
                    <div class="mt-4 space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
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
                                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-3 text-left text-sm text-neutral-800 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-700"
                                    wire:click="linkCustomer({{ $candidate->id }})"
                                >
                                    <div class="font-medium">{{ $candidate->name }}</div>
                                    <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $candidate->customer_code ?: '—' }} · {{ $candidate->email ?: '—' }} · {{ $candidate->phone ?: '—' }}
                                    </div>
                                </button>
                            @empty
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Search to find an unlinked customer record.') }}</div>
                            @endforelse
                        </div>
                        <flux:button class="w-full justify-center" size="sm" variant="ghost" wire:click="cancelLinking">
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-neutral-200 bg-white px-4 py-6 text-center text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300">
                {{ __('No customer accounts found.') }}
            </div>
        @endforelse
    </div>

    <div class="customer-accounts-desktop app-table-shell">
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
