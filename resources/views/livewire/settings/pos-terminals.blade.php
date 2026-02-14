<?php

use App\Models\PosTerminal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $editing_id = null;
    public int $branch_id = 1;
    public string $code = 'T01';
    public string $name = '';
    public ?string $device_id = null;
    public bool $active = true;

    public function mount(): void
    {
        $this->authorizeManager();
        $this->startCreate();
    }

    public function with(): array
    {
        $this->authorizeManager();

        $branches = DB::table('branches')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->values();

        $terminals = PosTerminal::query()
            ->orderBy('branch_id')
            ->orderBy('code')
            ->get()
            ->map(function (PosTerminal $t) {
                return [
                    'id' => (int) $t->id,
                    'branch_id' => (int) $t->branch_id,
                    'code' => (string) $t->code,
                    'name' => (string) $t->name,
                    'device_id' => $t->device_id ? (string) $t->device_id : null,
                    'active' => (bool) $t->active,
                    'last_seen_at' => optional($t->last_seen_at)?->toDateTimeString(),
                ];
            })
            ->values();

        $branchNames = $branches->keyBy('id')->map(fn ($b) => $b['name']);

        return [
            'branches' => $branches,
            'terminals' => $terminals,
            'branchNames' => $branchNames,
        ];
    }

    public function startCreate(): void
    {
        $this->editing_id = null;
        $this->branch_id = (int) (DB::table('branches')->orderBy('id')->value('id') ?? 1);
        $this->code = 'T01';
        $this->name = '';
        $this->device_id = null;
        $this->active = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManager();

        $terminal = PosTerminal::find($id);
        if (! $terminal) {
            return;
        }

        $this->editing_id = (int) $terminal->id;
        $this->branch_id = (int) $terminal->branch_id;
        $this->code = (string) $terminal->code;
        $this->name = (string) $terminal->name;
        $this->device_id = $terminal->device_id ? (string) $terminal->device_id : null;
        $this->active = (bool) $terminal->active;
    }

    public function generateDeviceId(): void
    {
        $this->authorizeManager();
        $this->device_id = (string) Str::uuid();
    }

    public function clearDeviceId(): void
    {
        $this->authorizeManager();
        $this->device_id = null;
    }

    public function save(): void
    {
        $this->authorizeManager();

        $data = $this->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:20', 'regex:/^T\\d{2}$/'],
            'name' => ['required', 'string', 'max:80'],
            'device_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'active' => ['required', 'boolean'],
        ]);

        $data['device_id'] = $data['device_id'] !== null && trim((string) $data['device_id']) !== ''
            ? trim((string) $data['device_id'])
            : null;

        try {
            if ($this->editing_id) {
                $terminal = PosTerminal::find($this->editing_id);
                if (! $terminal) {
                    throw ValidationException::withMessages(['terminal' => __('POS terminal not found.')]);
                }

                $terminal->update($data);
                session()->flash('status', __('POS terminal updated.'));
            } else {
                PosTerminal::create($data);
                session()->flash('status', __('POS terminal created.'));
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = (string) $e->getMessage();
            if (str_contains($msg, 'pos_terminals_branch_code_unique')) {
                throw ValidationException::withMessages(['code' => __('This terminal code already exists for the selected branch.')]);
            }
            if (str_contains($msg, 'pos_terminals_device_id_unique')) {
                throw ValidationException::withMessages(['device_id' => __('This device_id is already assigned to another terminal.')]);
            }
            throw $e;
        }

        $this->startCreate();
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('settings.pos_terminals.manage'))) {
            abort(403);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('POS Devices')" :subheading="__('Create terminals and bind each Windows device to a terminal using device_id')">
        @if(session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-4 rounded-md border border-neutral-200 bg-white p-4 text-sm text-neutral-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
            <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ __('How device registration works') }}</div>
            <ul class="mt-2 list-disc space-y-1 ps-5">
                <li>{{ __('Flutter POS must send a stable device_id in every POS request (login/sync).') }}</li>
                <li>{{ __('To register a device, paste that exact device_id here into a terminal row.') }}</li>
                <li>{{ __('device_id must be unique: one device ↔ one terminal.') }}</li>
            </ul>
        </div>

        <div class="mt-6 space-y-4">
            <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $editing_id ? __('Edit Terminal') : __('New Terminal') }}
                </div>

                <div class="space-y-2">
                    <label class="block text-xs text-neutral-500 dark:text-neutral-400">{{ __('Branch') }}</label>
                    <select wire:model="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950">
                        @foreach($branches as $b)
                            <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                </div>

                <flux:input wire:model="code" :label="__('Terminal Code')" placeholder="T01" />
                @error('code') <div class="text-xs text-red-600">{{ $message }}</div> @enderror

                <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('Front Counter') }}" />
                @error('name') <div class="text-xs text-red-600">{{ $message }}</div> @enderror

                <div class="space-y-2">
                    <flux:input wire:model="device_id" :label="__('Device ID (binds Windows device)')" placeholder="WINPOS01" />
                    @error('device_id') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
                    <div class="flex items-center gap-2">
                        <flux:button type="button" size="xs" variant="ghost" wire:click="generateDeviceId">{{ __('Generate') }}</flux:button>
                        <flux:button type="button" size="xs" variant="ghost" wire:click="clearDeviceId">{{ __('Clear') }}</flux:button>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="active" />
                    <span>{{ __('Active') }}</span>
                </label>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="startCreate">{{ __('Clear') }}</flux:button>
                    <flux:button type="button" variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
                </div>
            </div>

            <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($terminals as $t)
                        <div class="px-4 py-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ $branchNames[$t['branch_id']] ?? ('Branch #'.$t['branch_id']) }} • {{ $t['code'] }} — {{ $t['name'] }}
                                    </div>
                                    <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ __('Device ID:') }}
                                        <span class="font-mono">{{ $t['device_id'] ?: '—' }}</span>
                                        • {{ __('Active:') }} {{ $t['active'] ? __('Yes') : __('No') }}
                                        • {{ __('Last seen:') }} {{ $t['last_seen_at'] ?: '—' }}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    <flux:button size="xs" variant="ghost" wire:click="edit({{ $t['id'] }})">{{ __('Edit') }}</flux:button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No POS terminals yet.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
