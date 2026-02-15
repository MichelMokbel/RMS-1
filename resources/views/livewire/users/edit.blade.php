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
    public User $user;
    public string $username = '';
    public string $email = '';
    public string $status = 'active';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $pos_enabled = false;
    /** @var array<int, string> */
    public array $roles = [];
    /** @var array<int, string> */
    public array $permissions = [];
    /** @var array<int, int> */
    public array $branch_ids = [];

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->status = $user->status;
        $this->pos_enabled = (bool) $user->pos_enabled;
        $this->roles = $user->getRoleNames()->all();
        $this->permissions = $user->getDirectPermissions()->pluck('name')->map(fn ($name) => (string) $name)->all();
        $this->branch_ids = $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
    }

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
     * Persist the user changes.
     */
    public function updateUser(): void
    {
        $this->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:4', 'confirmed'],
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
        app(IamUserService::class)->update($this->user, [
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
            'pos_enabled' => $this->pos_enabled,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'branch_ids' => $this->branch_ids,
        ], $actor);

        session()->flash('status', __('IAM user updated successfully.'));

        $this->redirectRoute('users.index');
    }

    /**
     * Quickly toggle the status for this user.
     */
    public function toggleStatus(): void
    {
        $this->status = $this->status === 'active' ? 'inactive' : 'active';
        /** @var User $actor */
        $actor = auth()->user();

        app(IamUserService::class)->update($this->user, [
            'status' => $this->status,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'branch_ids' => $this->branch_ids,
            'pos_enabled' => $this->pos_enabled,
        ], $actor);
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit User') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Update account details or change the password.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Back to users') }}
            </flux:button>
            <flux:button variant="{{ $status === 'active' ? 'danger' : 'primary' }}" wire:click="toggleStatus">
                {{ $status === 'active' ? __('Deactivate') : __('Activate') }}
            </flux:button>
        </div>
    </div>

    <form wire:submit="updateUser" class="grid gap-4 md:max-w-2xl">
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
                :label="__('New Password')"
                type="password"
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
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
                {{ __('Save changes') }}
            </flux:button>
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
