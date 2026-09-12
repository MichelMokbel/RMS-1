<?php

use App\Models\OrderLabelPrinterProfile;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Orders\OrderLabelPrinterProfileService;
use App\Services\Orders\OrderLabelPrinterTestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $editingId = null;
    public int $expectedRevision = 0;
    public int $branchId = 1;
    public int $terminalId = 0;
    public string $code = '';
    public string $name = '';
    public string $department = 'packing';
    public string $modelCode = '';
    public string $osQueueName = '';
    public string $connectionDescription = '';
    public int $resolutionDpi = 300;
    public string $mediaMode = 'fixed';
    public string $widthMm = '58';
    public string $heightMm = '62';
    public string $minHeightMm = '50';
    public string $maxHeightMm = '120';
    public int $defaultCopies = 1;

    public function mount(): void
    {
        $this->assertCanManage();
        $this->startCreate();
    }

    public function with(): array
    {
        $this->assertCanManage();

        return [
            'branches' => DB::table('branches')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'terminals' => PosTerminal::query()->where('active', true)->orderBy('branch_id')->orderBy('code')->get(),
            'profiles' => OrderLabelPrinterProfile::query()
                ->with('terminal')
                ->withCount(['prints as queued_count' => fn ($query) => $query->whereIn('status', ['queued', 'claimed'])])
                ->withCount(['prints as failed_count' => fn ($query) => $query->where('status', 'failed')])
                ->orderBy('branch_id')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->expectedRevision = 0;
        $this->branchId = (int) (DB::table('branches')->where('is_active', 1)->orderBy('id')->value('id') ?? 1);
        $this->terminalId = (int) (PosTerminal::query()->where('branch_id', $this->branchId)->where('active', true)->value('id') ?? 0);
        $this->code = '';
        $this->name = '';
        $this->department = 'packing';
        $this->modelCode = '';
        $this->osQueueName = '';
        $this->connectionDescription = '';
        $this->resolutionDpi = 300;
        $this->mediaMode = 'fixed';
        $this->widthMm = '58';
        $this->heightMm = '62';
        $this->minHeightMm = '50';
        $this->maxHeightMm = '120';
        $this->defaultCopies = 1;
        $this->resetValidation();
    }

    public function updatedBranchId(): void
    {
        $this->terminalId = (int) (PosTerminal::query()->where('branch_id', $this->branchId)->where('active', true)->value('id') ?? 0);
    }

    public function edit(int $id): void
    {
        $this->assertCanManage();
        $profile = OrderLabelPrinterProfile::query()->findOrFail($id);
        $this->editingId = (int) $profile->id;
        $this->expectedRevision = (int) $profile->revision;
        $this->branchId = (int) $profile->branch_id;
        $this->terminalId = (int) $profile->terminal_id;
        $this->code = (string) $profile->code;
        $this->name = (string) $profile->name;
        $this->department = (string) $profile->department;
        $this->modelCode = (string) $profile->model_code;
        $this->osQueueName = (string) $profile->os_queue_name;
        $this->connectionDescription = (string) ($profile->connection_description ?? '');
        $this->resolutionDpi = (int) $profile->resolution_dpi;
        $this->mediaMode = (string) $profile->media_mode;
        $this->widthMm = $this->fromTenths($profile->width_tenths_mm);
        $this->heightMm = $this->fromTenths($profile->height_tenths_mm);
        $this->minHeightMm = $this->fromTenths($profile->min_height_tenths_mm);
        $this->maxHeightMm = $this->fromTenths($profile->max_height_tenths_mm);
        $this->defaultCopies = (int) $profile->default_copies;
        $this->resetValidation();
    }

    public function save(OrderLabelPrinterProfileService $service): void
    {
        $profile = $this->editingId ? OrderLabelPrinterProfile::query()->findOrFail($this->editingId) : null;
        $saved = $service->save($profile, [
            'branch_id' => $this->branchId,
            'terminal_id' => $this->terminalId,
            'code' => strtoupper(trim($this->code)),
            'name' => trim($this->name),
            'department' => $this->department,
            'model_code' => trim($this->modelCode),
            'os_queue_name' => trim($this->osQueueName),
            'connection_description' => trim($this->connectionDescription) ?: null,
            'resolution_dpi' => $this->resolutionDpi,
            'media_mode' => $this->mediaMode,
            'width_tenths_mm' => $this->toTenths($this->widthMm),
            'height_tenths_mm' => $this->mediaMode === 'fixed' ? $this->toTenths($this->heightMm) : null,
            'min_height_tenths_mm' => $this->mediaMode === 'continuous' ? $this->toTenths($this->minHeightMm) : null,
            'max_height_tenths_mm' => $this->mediaMode === 'continuous' ? $this->toTenths($this->maxHeightMm) : null,
            'default_copies' => $this->defaultCopies,
        ], $this->expectedRevision, $this->actor());

        $this->edit((int) $saved->id);
        session()->flash('status', __('Printer profile saved. Hardware changes require a new successful test.'));
    }

    public function sendTest(int $id, OrderLabelPrinterTestService $service): void
    {
        $profile = OrderLabelPrinterProfile::query()->findOrFail($id);
        $service->send($profile, $this->actor());
        session()->flash('status', __('Test label queued. Wait for the print agent to acknowledge it before verification.'));
    }

    public function verify(int $id, OrderLabelPrinterProfileService $service): void
    {
        $profile = OrderLabelPrinterProfile::query()->findOrFail($id);
        $service->verify($profile, (int) $profile->revision, $this->actor());
        session()->flash('status', __('Printer profile verified. You can now activate it.'));
    }

    public function toggleActive(int $id, OrderLabelPrinterProfileService $service): void
    {
        $profile = OrderLabelPrinterProfile::query()->findOrFail($id);
        $service->setActive($profile, ! $profile->is_active, (int) $profile->revision, $this->actor());
        session()->flash('status', $profile->is_active ? __('Printer disabled.') : __('Printer activated.'));
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function assertCanManage(): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole('admin') && $actor->can('order-label-printers.manage'), 403);
    }

    private function toTenths(string $value): int
    {
        return (int) round(((float) $value) * 10);
    }

    private function fromTenths(?int $value): string
    {
        return $value ? rtrim(rtrim(number_format($value / 10, 1, '.', ''), '0'), '.') : '';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Order Label Printers')" :subheading="__('Configure exact media and route labels through a registered local print terminal.')" content-class="mt-5 w-full max-w-6xl">
        @if (session('status'))
            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="space-y-3">
                @forelse ($profiles as $profile)
                    <article class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-bold text-neutral-950 dark:text-white">{{ $profile->name }}</h3>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $profile->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-neutral-100 text-neutral-600' }}">{{ $profile->is_active ? __('Active') : __('Inactive') }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $profile->is_verified ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ $profile->is_verified ? __('Verified') : __('Test required') }}</span>
                                </div>
                                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ $profile->model_code }} · {{ $profile->os_queue_name }}</p>
                                <p class="mt-1 text-xs text-neutral-500">{{ $profile->width_tenths_mm / 10 }} mm × {{ $profile->media_mode === 'fixed' ? ($profile->height_tenths_mm / 10).' mm' : __('continuous') }} · {{ $profile->resolution_dpi }} dpi · {{ $profile->terminal?->code }}</p>
                                <p class="mt-1 text-xs text-neutral-500">{{ trans_choice(':count queued job|:count queued jobs', $profile->queued_count, ['count' => $profile->queued_count]) }} · {{ trans_choice(':count failure|:count failures', $profile->failed_count, ['count' => $profile->failed_count]) }}</p>
                                <p class="mt-1 text-xs text-neutral-500">{{ __('Agent last seen: :time', ['time' => $profile->terminal?->print_agent_seen_at?->diffForHumans() ?? __('never')]) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="edit({{ $profile->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" :href="route('settings.order-label-printers.preview', $profile)" target="_blank">{{ __('Preview') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="sendTest({{ $profile->id }})" wire:loading.attr="disabled">{{ __('Send test') }}</flux:button>
                                @if (! $profile->is_verified)
                                    <flux:button size="sm" variant="ghost" wire:click="verify({{ $profile->id }})">{{ __('Verify printed test') }}</flux:button>
                                @endif
                                <flux:button size="sm" :variant="$profile->is_active ? 'danger' : 'primary'" wire:click="toggleActive({{ $profile->id }})">{{ $profile->is_active ? __('Disable') : __('Activate') }}</flux:button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">{{ __('No label printers configured.') }}</div>
                @endforelse
            </div>

            <form wire:submit="save" class="space-y-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-bold text-neutral-950 dark:text-white">{{ $editingId ? __('Edit printer') : __('New printer') }}</h3>
                    <flux:button type="button" size="sm" variant="ghost" wire:click="startCreate">{{ __('New') }}</flux:button>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Branch') }}</label>
                    <select wire:model.live="branchId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950">
                        @foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Print terminal') }}</label>
                    <select wire:model="terminalId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950">
                        <option value="0">{{ __('Choose terminal') }}</option>
                        @foreach ($terminals->where('branch_id', $branchId) as $terminal)<option value="{{ $terminal->id }}">{{ $terminal->code }} · {{ $terminal->name }}</option>@endforeach
                    </select>
                </div>
                <flux:input wire:model="code" :label="__('Profile code')" placeholder="BROTHER_PACKING" />
                <flux:input wire:model="name" :label="__('Display name')" placeholder="Packing labels" />
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('Department') }}</label>
                    <select wire:model="department" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950">
                        <option value="kitchen">{{ __('Kitchen') }}</option><option value="pastry">{{ __('Pastry') }}</option><option value="packing">{{ __('Packing') }}</option>
                    </select>
                </div>
                <flux:input wire:model="modelCode" :label="__('Exact model code')" placeholder="QL-820NWB" />
                <flux:input wire:model="osQueueName" :label="__('Operating system queue')" placeholder="Brother_QL_820NWB" />
                <flux:input wire:model="connectionDescription" :label="__('Connection')" placeholder="Ethernet · packing Mac" />
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('Resolution') }}</label><select wire:model="resolutionDpi" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="203">203 dpi</option><option value="300">300 dpi</option><option value="600">600 dpi</option></select></div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('Media') }}</label><select wire:model.live="mediaMode" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="fixed">{{ __('Fixed') }}</option><option value="continuous">{{ __('Continuous') }}</option></select></div>
                </div>
                <flux:input wire:model="widthMm" type="number" step="0.1" min="10" max="120" :label="__('Label width (mm)')" />
                @if ($mediaMode === 'fixed')
                    <flux:input wire:model="heightMm" type="number" step="0.1" min="10" max="300" :label="__('Label height (mm)')" />
                @else
                    <div class="grid grid-cols-2 gap-3"><flux:input wire:model="minHeightMm" type="number" step="0.1" :label="__('Min height (mm)')" /><flux:input wire:model="maxHeightMm" type="number" step="0.1" :label="__('Max height (mm)')" /></div>
                @endif
                <flux:input wire:model="defaultCopies" type="number" min="1" max="10" :label="__('Default copies')" />
                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">{{ __('Save printer profile') }}</flux:button>
                <p class="text-xs leading-relaxed text-neutral-500">{{ __('Profiles remain inactive until a test label is acknowledged, verified, and activated.') }}</p>
            </form>
        </div>
    </x-settings.layout>
</section>
