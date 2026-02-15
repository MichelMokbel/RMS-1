<?php

use App\Models\Branch;
use App\Models\User;
use App\Services\Security\IamUserService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\Volt\Layout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new #[Layout('components.layouts.app')] class extends Component {
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $status = 'active';
    public bool $pos_enabled = false;
    /** @var array<int, string> */
    public array $roles = [];
    /** @var array<int, string> */
    public array $permissions = [];
    /** @var array<int, int> */
    public array $branch_ids = [];

    public function with(): array
    {
        return [
            'roleOptions' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'permissionOptions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'branchOptions' => Branch::query()
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('branches', 'is_active'), fn ($q) => $q->where('is_active', 1))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * Create a new user.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique(User::class)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'pos_enabled' => ['required', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
        ]);

        /** @var User $actor */
        $actor = auth()->user();
        app(IamUserService::class)->create($validated, $actor);

        session()->flash('status', __('IAM user created successfully.'));

        $this->redirectRoute('users.index');
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create User') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Add a new user to the system.') }}</p>
        </div>

        <flux:button :href="route('users.index')" wire:navigate variant="ghost">
            {{ __('Back to users') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="grid gap-4 md:max-w-2xl">
        <flux:input
            wire:model="username"
            :label="__('Username')"
            type="text"
            required
            autocomplete="username"
        />

        <flux:input
            wire:model="email"
            :label="__('Email')"
            type="email"
            required
            autocomplete="email"
        />

        <div class="grid gap-4 md:grid-cols-2">
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
            <div class="flex gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="radio" value="active" wire:model="status" class="h-4 w-4 border-neutral-300 text-emerald-600 focus:ring-emerald-500">
                    {{ __('Active') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="radio" value="inactive" wire:model="status" class="h-4 w-4 border-neutral-300 text-amber-600 focus:ring-amber-500">
                    {{ __('Inactive') }}
                </label>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
            <input type="checkbox" wire:model="pos_enabled" class="h-4 w-4 border-neutral-300 text-indigo-600 focus:ring-indigo-500">
            {{ __('Enable POS login for this user') }}
        </label>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Roles') }}</label>
            <select wire:model="roles" multiple class="min-h-[120px] rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                @foreach($roleOptions as $roleName)
                    <option value="{{ $roleName }}">{{ $roleName }}</option>
                @endforeach
            </select>
            @error('roles.*') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Direct Permissions') }}</label>
            <select wire:model="permissions" multiple class="min-h-[160px] rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                @foreach($permissionOptions as $permissionName)
                    <option value="{{ $permissionName }}">{{ $permissionName }}</option>
                @endforeach
            </select>
            @error('permissions.*') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Allowed Branches') }}</label>
            <select wire:model="branch_ids" multiple class="min-h-[140px] rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                @foreach($branchOptions as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            @error('branch_ids.*') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
        </div>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
