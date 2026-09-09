<?php

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\StorefrontCategory;
use App\Models\StorefrontClosedDate;
use App\Models\StorefrontDeliveryChannel;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Storefront\StorefrontAdministrationService;
use App\Services\Storefront\StorefrontFunnelReportService;
use App\Services\Menu\MenuItemUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public string $tab = 'settings';
    public int $revision = 0;
    public int|string $portal_branch_id = '';
    public bool $normal_menu_enabled = false;
    public bool $checkout_upsell_enabled = false;
    public int|string $upsell_category_id = '';
    public string $menu_cutoff_time = '23:00';
    public bool $delivery_apps_enabled = false;

    public ?int $category_id = null;
    public string $category_title = '';
    public string $category_slug = '';
    public string $category_description = '';
    public int|string $category_display_order = 0;
    public bool $category_is_active = true;

    public string $item_search = '';
    public string $cleanup_search = '';
    public string $funnel_from = '';
    public string $funnel_to = '';
    public int|string $menu_item_id = '';
    public int|string $profile_category_id = '';
    public string $customer_title = '';
    public string $short_description = '';
    public bool $direct_order_enabled = false;
    public int|string $advance_days = 1;
    public string $minimum_quantity = '1.000';
    public string $quantity_increment = '1.000';
    public string $maximum_quantity = '';
    public bool $is_chef_pick = false;
    public int|string $profile_display_order = 0;
    public ?int $profile_id = null;
    public $profile_image = null;
    public ?string $current_image_url = null;
    /** @var array<int, bool> */
    public array $item_channel_enabled = [];
    /** @var array<int, string> */
    public array $item_channel_url = [];

    public string $closed_service_date = '';
    public string $closed_reason = '';

    /** @var array<string, bool> */
    public array $channel_enabled = [];
    /** @var array<string, string> */
    public array $channel_url = [];
    /** @var array<string, int> */
    public array $channel_order = [];

    protected $queryString = ['tab' => ['except' => 'settings']];

    public function mount(StorefrontAdministrationService $service): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = $service->companyIdFor($actor);
        $settings = $service->settings($companyId);
        $this->revision = (int) ($settings?->revision ?? 0);
        $this->portal_branch_id = (int) ($settings?->portal_branch_id
            ?? Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('id')->value('id'));
        $this->normal_menu_enabled = (bool) ($settings?->normal_menu_enabled ?? false);
        $this->checkout_upsell_enabled = (bool) ($settings?->checkout_upsell_enabled ?? false);
        $this->upsell_category_id = (int) ($settings?->upsell_category_id ?? 0) ?: '';
        $this->menu_cutoff_time = substr((string) ($settings?->menu_cutoff_time ?? '23:00:00'), 0, 5);
        $this->delivery_apps_enabled = (bool) ($settings?->delivery_apps_enabled ?? false);
        $this->funnel_to = CarbonImmutable::now(StorefrontSetting::TIMEZONE)->subDay()->toDateString();
        $this->funnel_from = CarbonImmutable::parse($this->funnel_to, StorefrontSetting::TIMEZONE)->subDays(29)->toDateString();
        $this->loadChannels($companyId);
    }

    public function saveSettings(StorefrontAdministrationService $service): void
    {
        $actor = $this->actor();
        $settings = $service->saveSettings($actor, [
            'portal_branch_id' => $this->portal_branch_id,
            'normal_menu_enabled' => $this->normal_menu_enabled,
            'checkout_upsell_enabled' => $this->checkout_upsell_enabled,
            'upsell_category_id' => $this->upsell_category_id,
            'menu_cutoff_time' => $this->menu_cutoff_time,
            'delivery_apps_enabled' => $this->delivery_apps_enabled,
        ], $this->revision);
        $this->revision = (int) $settings->revision;
        session()->flash('status', __('Storefront settings saved.'));
    }

    public function saveCategory(StorefrontAdministrationService $service): void
    {
        $category = $service->saveCategory($this->actor(), $this->category_id, [
            'title' => $this->category_title,
            'slug' => $this->category_slug,
            'description' => $this->category_description,
            'display_order' => $this->category_display_order,
            'is_active' => $this->category_is_active,
        ], $this->revision);
        $this->revision++;
        $this->editCategory($category->id, $service);
        session()->flash('status', __('Storefront category saved.'));
    }

    public function editCategory(int $id, StorefrontAdministrationService $service): void
    {
        $companyId = $service->companyIdFor($this->actor());
        $category = StorefrontCategory::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);
        $this->category_id = $category->id;
        $this->category_title = (string) $category->title;
        $this->category_slug = (string) $category->slug;
        $this->category_description = (string) $category->description;
        $this->category_display_order = (int) $category->display_order;
        $this->category_is_active = (bool) $category->is_active;
    }

    public function newCategory(): void
    {
        $this->reset('category_id', 'category_title', 'category_slug', 'category_description');
        $this->category_display_order = 0;
        $this->category_is_active = true;
    }

    public function editItem(int $id, StorefrontAdministrationService $service): void
    {
        $companyId = $service->companyIdFor($this->actor());
        $this->menu_item_id = $id;
        $profile = StorefrontItemProfile::query()
            ->where('company_id', $companyId)
            ->where('branch_id', (int) $this->portal_branch_id)
            ->where('menu_item_id', $id)
            ->first();
        $this->profile_id = $profile?->id;
        $this->profile_image = null;
        $this->current_image_url = $profile?->image_disk && $profile?->image_path
            ? Storage::disk((string) $profile->image_disk)->url((string) $profile->image_path)
            : null;
        $this->profile_category_id = $profile?->category_id ?? '';
        $this->customer_title = (string) ($profile?->customer_title ?? '');
        $this->short_description = (string) ($profile?->short_description ?? '');
        $this->direct_order_enabled = (bool) ($profile?->direct_order_enabled ?? false);
        $this->advance_days = (int) ($profile?->advance_days ?? 1);
        $this->minimum_quantity = (string) ($profile?->minimum_quantity ?? '1.000');
        $this->quantity_increment = (string) ($profile?->quantity_increment ?? '1.000');
        $this->maximum_quantity = (string) ($profile?->maximum_quantity ?? '');
        $this->is_chef_pick = (bool) ($profile?->is_chef_pick ?? false);
        $this->profile_display_order = (int) ($profile?->display_order ?? 0);
        $links = $profile?->channels()->get()->keyBy('channel_id') ?? collect();
        foreach (StorefrontDeliveryChannel::query()->where('company_id', $companyId)->get() as $channel) {
            $link = $links->get($channel->id);
            $this->item_channel_enabled[$channel->id] = (bool) ($link?->is_enabled ?? false);
            $this->item_channel_url[$channel->id] = (string) ($link?->item_url ?? '');
        }
    }

    public function saveItem(StorefrontAdministrationService $service): void
    {
        if ((int) $this->menu_item_id <= 0) {
            $this->addError('menu_item_id', __('Choose a menu item.'));
            return;
        }
        $profile = $service->saveProfile($this->actor(), (int) $this->menu_item_id, [
            'category_id' => $this->profile_category_id,
            'customer_title' => $this->customer_title,
            'short_description' => $this->short_description,
            'direct_order_enabled' => $this->direct_order_enabled,
            'advance_days' => $this->advance_days,
            'minimum_quantity' => $this->minimum_quantity,
            'quantity_increment' => $this->quantity_increment,
            'maximum_quantity' => $this->maximum_quantity,
            'is_chef_pick' => $this->is_chef_pick,
            'display_order' => $this->profile_display_order,
        ], $this->revision);
        $this->revision++;
        $this->profile_id = $profile->id;
        session()->flash('status', __('Storefront item saved.'));
    }

    public function uploadItemImage(StorefrontAdministrationService $service): void
    {
        if (! $this->profile_id) {
            $this->addError('profile_image', __('Save the storefront item before uploading its image.'));
            return;
        }
        $this->validate([
            'profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
        $profile = $service->replaceProfileImage($this->actor(), $this->profile_id, $this->profile_image, $this->revision);
        $this->revision++;
        $this->profile_image = null;
        $this->current_image_url = Storage::disk((string) $profile->image_disk)->url((string) $profile->image_path);
        session()->flash('status', __('Storefront item image saved.'));
    }

    public function removeItemImage(StorefrontAdministrationService $service): void
    {
        if (! $this->profile_id || ! $this->current_image_url) {
            return;
        }
        $service->removeProfileImage($this->actor(), $this->profile_id, $this->revision);
        $this->revision++;
        $this->profile_image = null;
        $this->current_image_url = null;
        session()->flash('status', __('Storefront item image removed.'));
    }

    public function disableCleanupItem(int $menuItemId, StorefrontAdministrationService $service): void
    {
        $service->disableAndHideMenuItem($this->actor(), $menuItemId, $this->revision);
        $this->revision++;
        session()->flash('status', __('Menu item disabled and hidden from every storefront channel.'));
    }

    public function deleteCleanupItem(int $menuItemId, StorefrontAdministrationService $service): void
    {
        $service->permanentlyDeleteUnusedMenuItem($this->actor(), $menuItemId, $this->revision);
        $this->revision++;
        session()->flash('status', __('Unused menu item permanently deleted.'));
    }

    public function saveItemChannel(int $channelId, StorefrontAdministrationService $service): void
    {
        if (! $this->profile_id) {
            $this->addError('menu_item_id', __('Save the storefront item before adding application links.'));
            return;
        }
        $service->saveItemChannel($this->actor(), $this->profile_id, $channelId, [
            'is_enabled' => (bool) ($this->item_channel_enabled[$channelId] ?? false),
            'item_url' => (string) ($this->item_channel_url[$channelId] ?? ''),
        ], $this->revision);
        $this->revision++;
        session()->flash('status', __('Item application link saved.'));
    }

    public function addClosedDate(StorefrontAdministrationService $service): void
    {
        $service->saveClosedDate($this->actor(), null, [
            'service_date' => $this->closed_service_date,
            'reason' => $this->closed_reason,
        ], $this->revision);
        $this->revision++;
        $this->reset('closed_service_date', 'closed_reason');
        session()->flash('status', __('Closed date added.'));
    }

    public function removeClosedDate(int $id, StorefrontAdministrationService $service): void
    {
        $service->deleteClosedDate($this->actor(), $id, $this->revision);
        $this->revision++;
        session()->flash('status', __('Closed date removed.'));
    }

    public function saveChannel(string $code, StorefrontAdministrationService $service): void
    {
        $service->saveChannel($this->actor(), $code, [
            'is_enabled' => (bool) ($this->channel_enabled[$code] ?? false),
            'restaurant_url' => (string) ($this->channel_url[$code] ?? ''),
            'display_order' => (int) ($this->channel_order[$code] ?? 0),
        ], $this->revision);
        $this->revision++;
        session()->flash('status', __(':channel settings saved.', ['channel' => StorefrontDeliveryChannel::OPTIONS[$code]]));
    }

    public function with(StorefrontAdministrationService $service, StorefrontFunnelReportService $funnelReports): array
    {
        $companyId = $service->companyIdFor($this->actor());
        $settings = $service->settings($companyId);
        $branchId = (int) ($settings?->portal_branch_id ?? $this->portal_branch_id);
        $menuItems = MenuItem::query()
            ->when(trim($this->item_search) !== '', fn ($query) => $query->search($this->item_search))
            ->with(['category'])
            ->orderBy('name')
            ->limit(100)
            ->get();
        $profiles = StorefrontItemProfile::query()
            ->with(['menuItem', 'category', 'channels.channel'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('menu_item_id');
        $cleanupRows = collect();
        if ($this->tab === 'cleanup') {
            $usage = app(MenuItemUsageService::class);
            $cleanupRows = MenuItem::query()
                ->when(trim($this->cleanup_search) !== '', fn ($query) => $query->search($this->cleanup_search))
                ->with('recipe:id,name')
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->map(function (MenuItem $item) use ($usage, $companyId, $branchId): array {
                    $profile = StorefrontItemProfile::query()
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->where('menu_item_id', $item->id)
                        ->first();
                    $branchIds = DB::table('menu_item_branches')->where('menu_item_id', $item->id)->orderBy('branch_id')->pluck('branch_id')->all();

                    return [
                        'item' => $item,
                        'profile' => $profile,
                        'branch_ids' => $branchIds,
                        'usage' => $usage->usageReport((int) $item->id),
                    ];
                });
        }
        $funnelReport = $this->tab === 'funnel'
            ? $funnelReports->report($companyId, $this->funnel_from, $this->funnel_to)
            : null;

        return [
            'branches' => Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => StorefrontCategory::query()->where('company_id', $companyId)->orderBy('display_order')->orderBy('title')->get(),
            'menuItems' => $menuItems,
            'profiles' => $profiles,
            'closedDates' => StorefrontClosedDate::query()->where('company_id', $companyId)->where('branch_id', $branchId)->orderBy('service_date')->get(),
            'channelOptions' => StorefrontDeliveryChannel::OPTIONS,
            'channelRecords' => StorefrontDeliveryChannel::query()->where('company_id', $companyId)->orderBy('display_order')->get(),
            'cleanupRows' => $cleanupRows,
            'funnelReport' => $funnelReport,
        ];
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    private function loadChannels(int $companyId): void
    {
        $records = StorefrontDeliveryChannel::query()->where('company_id', $companyId)->get()->keyBy('code');
        foreach (StorefrontDeliveryChannel::OPTIONS as $index => $label) {
            $record = $records->get($index);
            $this->channel_enabled[$index] = (bool) ($record?->is_enabled ?? false);
            $this->channel_url[$index] = (string) ($record?->restaurant_url ?? '');
            $this->channel_order[$index] = (int) ($record?->display_order ?? array_search($index, array_keys(StorefrontDeliveryChannel::OPTIONS), true));
        }
    }
}; ?>

<div class="app-page space-y-6">
    <header>
        <p class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Administration') }}</p>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Customer Storefront') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-300">{{ __('Publish reviewed menu items, control advance ordering, and maintain independent delivery application links.') }}</p>
    </header>

    @if(session('status'))
        <p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>
    @endif
    @error('revision') <p role="alert" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $message }}</p> @enderror

    <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Storefront sections') }}">
        @foreach(['settings' => __('Settings'), 'categories' => __('Categories'), 'items' => __('Items'), 'closed-dates' => __('Closed dates'), 'delivery-apps' => __('Delivery apps'), 'funnel' => __('Funnel'), 'cleanup' => __('Catalog cleanup')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="min-h-11 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium {{ $tab === $key ? 'bg-amber-600 text-white' : 'border border-zinc-200 bg-white text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'settings')
        <form wire:submit="saveSettings" class="max-w-3xl space-y-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="portal_branch_id" :label="__('Portal branch')">
                    @foreach($branches as $branch)<flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>@endforeach
                </flux:select>
                <flux:input wire:model="menu_cutoff_time" type="time" step="60" :label="__('Normal menu cutoff in Qatar')" :description="__('At or after this time, preparation starts from the following date.')" />
            </div>
            <label class="flex min-h-11 items-center gap-3"><input wire:model="normal_menu_enabled" type="checkbox" class="rounded border-zinc-300 text-amber-600"><span>{{ __('Enable direct normal menu ordering') }}</span></label>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex min-h-11 items-center gap-3"><input wire:model="checkout_upsell_enabled" type="checkbox" class="rounded border-zinc-300 text-amber-600"><span>{{ __('Enable checkout add-ons') }}</span></label>
                <flux:select wire:model="upsell_category_id" :label="__('Checkout add-on category')" :description="__('Only published items from this one category appear before final review.')">
                    <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
                    @foreach($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->title }}</flux:select.option>@endforeach
                </flux:select>
            </div>
            @error('upsell_category_id') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <label class="flex min-h-11 items-center gap-3"><input wire:model="delivery_apps_enabled" type="checkbox" class="rounded border-zinc-300 text-amber-600"><span>{{ __('Show the delivery applications section') }}</span></label>
            <p class="text-sm text-zinc-500">{{ __('Revision') }} {{ $revision }} · {{ __('Timezone remains Asia/Qatar. Turning direct ordering off never blocks a payment that already started.') }}</p>
            <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save settings') }}</flux:button></div>
        </form>
    @elseif($tab === 'categories')
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
            <section class="space-y-3">
                @foreach($categories as $category)
                    <button type="button" wire:key="storefront-category-{{ $category->id }}" wire:click="editCategory({{ $category->id }})" class="flex min-h-11 w-full items-center justify-between rounded-xl border border-zinc-200 bg-white p-4 text-left dark:border-zinc-700 dark:bg-zinc-800">
                        <span><strong>{{ $category->title }}</strong><span class="block text-sm text-zinc-500">/{{ $category->slug }}</span></span>
                        <span class="text-sm {{ $category->is_active ? 'text-emerald-700' : 'text-zinc-500' }}">{{ $category->is_active ? __('Active') : __('Hidden') }}</span>
                    </button>
                @endforeach
            </section>
            <form wire:submit="saveCategory" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex justify-between"><h2 class="font-semibold">{{ $category_id ? __('Edit category') : __('New category') }}</h2><button type="button" wire:click="newCategory" class="text-sm text-amber-700">{{ __('Clear') }}</button></div>
                <flux:input wire:model="category_title" maxlength="120" :label="__('Customer title')" />
                <flux:input wire:model="category_slug" maxlength="120" :label="__('URL slug')" />
                <flux:textarea wire:model="category_description" maxlength="500" :label="__('Description')" />
                <flux:input wire:model="category_display_order" type="number" step="1" :label="__('Display order')" />
                <label class="flex min-h-11 items-center gap-3"><input wire:model="category_is_active" type="checkbox" class="rounded"><span>{{ __('Active') }}</span></label>
                <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Save category') }}</flux:button></div>
            </form>
        </div>
    @elseif($tab === 'items')
        <div class="space-y-5">
            <flux:input wire:model.live.debounce.300ms="item_search" type="search" :label="__('Find canonical menu item')" :description="__('Search name, Arabic name, or internal code. Internal fields never appear on the customer website.')" />
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,480px)]">
                <div class="max-h-[680px] space-y-2 overflow-y-auto pr-1">
                    @foreach($menuItems as $item)
                        @php($profile = $profiles->get($item->id))
                        @php($hasApp = $profile?->channels?->contains(fn($link) => $link->is_enabled) ?? false)
                        @php($state = $profile?->direct_order_enabled ? ($hasApp ? __('Both') : __('Direct')) : ($hasApp ? __('Application only') : __('Hidden')))
                        <button type="button" wire:key="storefront-item-{{ $item->id }}" wire:click="editItem({{ $item->id }})" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-left dark:border-zinc-700 dark:bg-zinc-800">
                            <span><strong>{{ $item->name }}</strong><span class="block text-xs text-zinc-500">{{ $item->code }} · {{ $item->unit }} · QAR {{ number_format((float) $item->selling_price_per_unit, 2) }}</span></span>
                            <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-700">{{ $state }}</span>
                        </button>
                    @endforeach
                </div>
                <form wire:submit="saveItem" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="font-semibold">{{ __('Customer presentation and ordering') }}</h2>
                    @if((int) $menu_item_id <= 0)<p class="text-sm text-zinc-500">{{ __('Choose an item from the list.') }}</p>@endif
                    <flux:select wire:model="profile_category_id" :label="__('Storefront category')"><flux:select.option value="">{{ __('None') }}</flux:select.option>@foreach($categories->where('is_active', true) as $category)<flux:select.option value="{{ $category->id }}">{{ $category->title }}</flux:select.option>@endforeach</flux:select>
                    <flux:input wire:model="customer_title" maxlength="120" :label="__('Customer title')" :description="__('Leave blank to use the canonical menu name.')" />
                    <flux:textarea wire:model="short_description" maxlength="280" :label="__('Short description')" />
                    @if($profile_id)
                        <section class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <h3 class="text-sm font-semibold">{{ __('Customer image') }}</h3>
                            @if($current_image_url)<img src="{{ $current_image_url }}" alt="{{ __('Current storefront item image') }}" class="h-32 w-full rounded-lg object-cover">@endif
                            <input wire:model="profile_image" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm" aria-label="{{ __('Choose storefront item image') }}">
                            @error('profile_image')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="text-xs text-zinc-500">{{ __('JPEG, PNG, or WebP up to 5 MB. A standard placeholder is used when no image is saved.') }}</p>
                            <div class="flex flex-wrap justify-end gap-2">
                                @if($current_image_url)<flux:button type="button" size="sm" variant="ghost" wire:click="removeItemImage" wire:loading.attr="disabled">{{ __('Remove image') }}</flux:button>@endif
                                <flux:button type="button" size="sm" wire:click="uploadItemImage" wire:loading.attr="disabled" :disabled="!$profile_image">{{ __('Upload image') }}</flux:button>
                            </div>
                        </section>
                    @endif
                    <div class="grid gap-3 sm:grid-cols-3"><flux:input wire:model="minimum_quantity" inputmode="decimal" :label="__('Minimum')" /><flux:input wire:model="quantity_increment" inputmode="decimal" :label="__('Increment')" /><flux:input wire:model="maximum_quantity" inputmode="decimal" :label="__('Maximum')" /></div>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="advance_days" type="number" min="1" max="365" :label="__('Advance days')" /><flux:input wire:model="profile_display_order" type="number" :label="__('Display order')" /></div>
                    <label class="flex min-h-11 items-center gap-3"><input wire:model="direct_order_enabled" type="checkbox" class="rounded"><span>{{ __('Publish for direct website ordering') }}</span></label>
                    <label class="flex min-h-11 items-center gap-3"><input wire:model="is_chef_pick" type="checkbox" class="rounded"><span>{{ __('Chef pick') }}</span></label>
                    @error('direct_order_enabled')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <p class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">{{ __('Publishing requires an active canonical item, positive canonical price, supported unit, active category, and portal branch availability.') }}</p>
                    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save item') }}</flux:button></div>
                    @if($profile_id && $channelRecords->isNotEmpty())
                        <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <h3 class="text-sm font-semibold">{{ __('Delivery application availability') }}</h3>
                            @foreach($channelRecords as $channel)
                                <div wire:key="profile-channel-{{ $channel->id }}" class="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                                    <label class="flex min-h-11 items-center gap-3"><input wire:model="item_channel_enabled.{{ $channel->id }}" type="checkbox" class="rounded"><span>{{ $channel->label }}</span></label>
                                    <flux:input wire:model="item_channel_url.{{ $channel->id }}" type="url" placeholder="https://" :label="__('Optional item URL')" />
                                    <div class="flex justify-end"><flux:button type="button" size="sm" wire:click="saveItemChannel({{ $channel->id }})">{{ __('Save link') }}</flux:button></div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </form>
            </div>
        </div>
    @elseif($tab === 'closed-dates')
        <div class="grid gap-5 lg:grid-cols-2">
            <form wire:submit="addClosedDate" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800"><h2 class="font-semibold">{{ __('Add unavailable service date') }}</h2><flux:input wire:model="closed_service_date" type="date" :label="__('Date')" /><flux:input wire:model="closed_reason" maxlength="255" :label="__('Internal reason')" /><div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Add closed date') }}</flux:button></div></form>
            <section class="space-y-2">@forelse($closedDates as $closed)<div wire:key="closed-date-{{ $closed->id }}" class="flex items-center justify-between rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span><strong>{{ $closed->service_date->toDateString() }}</strong><span class="block text-sm text-zinc-500">{{ $closed->reason ?: __('No reason entered') }}</span></span><flux:button type="button" wire:click="removeClosedDate({{ $closed->id }})" variant="danger" size="sm">{{ __('Remove') }}</flux:button></div>@empty<p class="rounded-xl border border-dashed p-8 text-center text-sm text-zinc-500">{{ __('No closed dates configured.') }}</p>@endforelse</section>
        </div>
    @elseif($tab === 'delivery-apps')
        <div class="grid gap-4 md:grid-cols-2">
            @foreach($channelOptions as $code => $label)
                <form wire:key="delivery-channel-{{ $code }}" wire:submit="saveChannel('{{ $code }}')" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-lg font-semibold">{{ $label }}</h2>
                    <label class="flex min-h-11 items-center gap-3"><input wire:model="channel_enabled.{{ $code }}" type="checkbox" class="rounded"><span>{{ __('Enable this application') }}</span></label>
                    <flux:input wire:model="channel_url.{{ $code }}" type="url" maxlength="2048" placeholder="https://" :label="__('Restaurant page URL')" :description="__('Used when an item does not have its own application link.')" />
                    <flux:input wire:model="channel_order.{{ $code }}" type="number" :label="__('Display order')" />
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('Save :channel', ['channel' => $label]) }}</flux:button></div>
                </form>
            @endforeach
            <p class="md:col-span-2 rounded-lg bg-sky-50 p-4 text-sm text-sky-900 dark:bg-sky-950 dark:text-sky-100">{{ __('Delivery applications own their availability, payment, and confirmation. Opening a link creates no RMS order or payment.') }}</p>
        </div>
    @elseif($tab === 'funnel')
        <div class="space-y-5">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model.live="funnel_from" type="date" :label="__('From date in Qatar')" />
                    <flux:input wire:model.live="funnel_to" type="date" :label="__('To date in Qatar')" />
                </div>
                @error('funnel_from')<p role="alert" class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-3 text-sm text-zinc-500">{{ __('Browser totals are directional because blockers and abandoned tabs can prevent event delivery. Checkout totals come from the canonical payment workflow. Raw totals are intentionally not combined into conversion percentages.') }}</p>
            </section>
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-lg font-semibold">{{ __('Directional browser stages') }}</h2>
                    <dl class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach(['order_home_views' => __('Order home views'), 'path_choices' => __('Path choices'), 'item_views' => __('Item views'), 'item_adds' => __('Item adds'), 'cart_views' => __('Cart views'), 'upsell_views' => __('Upsell views'), 'upsell_skips' => __('Upsell skips'), 'upsell_item_adds' => __('Upsell item adds'), 'delivery_app_exits' => __('Delivery application exits')] as $key => $label)
                            <div class="flex items-center justify-between py-3"><dt>{{ $label }}</dt><dd class="text-lg font-semibold">{{ number_format($funnelReport['browser_directional'][$key]) }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-lg font-semibold">{{ __('Canonical checkout cohort') }}</h2>
                    <dl class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach(['checkout_starts' => __('Checkout starts'), 'declines' => __('Declines'), 'paid_processing' => __('Paid processing'), 'paid_completions' => __('Paid completions')] as $key => $label)
                            <div class="flex items-center justify-between py-3"><dt>{{ $label }}</dt><dd class="text-lg font-semibold">{{ number_format($funnelReport['checkout_canonical'][$key]) }}</dd></div>
                        @endforeach
                        <div class="flex items-center justify-between py-3"><dt>{{ __('Paid upsell checkouts') }}</dt><dd class="text-lg font-semibold">{{ number_format($funnelReport['checkout_canonical']['paid_upsell_checkouts']) }}</dd></div>
                        <div class="flex items-center justify-between py-3"><dt>{{ __('Paid upsell value') }}</dt><dd class="text-lg font-semibold">QAR {{ number_format($funnelReport['checkout_canonical']['paid_upsell_amount_cents'] / 100, 2) }}</dd></div>
                    </dl>
                </section>
            </div>
        </div>
    @else
        <div class="space-y-5">
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800 md:flex-row md:items-end md:justify-between">
                <flux:input wire:model.live.debounce.300ms="cleanup_search" type="search" :label="__('Review menu items')" :description="__('This report never deletes from names or categories. Review every row before choosing an action.')" />
                <flux:button :href="route('storefront.cleanup.csv', ['search' => $cleanup_search])" target="_blank" variant="ghost">{{ __('Export dry run CSV') }}</flux:button>
            </div>
            @error('cleanup')<p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="min-w-[980px] w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-900"><tr><th class="p-3">{{ __('Item') }}</th><th class="p-3">{{ __('Canonical state') }}</th><th class="p-3">{{ __('Branch and storefront') }}</th><th class="p-3">{{ __('Known references') }}</th><th class="p-3 text-right">{{ __('Reviewed action') }}</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @forelse($cleanupRows as $row)
                            @php($item = $row['item'])
                            @php($referenceLabels = collect($row['usage']['references'])->filter(fn($count) => $count > 0)->map(fn($count, $key) => str_replace('_', ' ', $key).': '.$count)->values())
                            <tr wire:key="cleanup-item-{{ $item->id }}">
                                <td class="p-3"><strong>{{ $item->name }}</strong><span class="block text-xs text-zinc-500">{{ $item->code }} · #{{ $item->id }}</span></td>
                                <td class="p-3"><span class="block">{{ $item->is_active ? __('Active') : __('Disabled') }}</span><span class="text-xs text-zinc-500">{{ $item->recipe ? __('Recipe: :name', ['name' => $item->recipe->name]) : __('No recipe link') }}</span></td>
                                <td class="p-3"><span class="block">{{ __('Branches: :branches', ['branches' => $row['branch_ids'] ? implode(', ', $row['branch_ids']) : __('none')]) }}</span><span class="text-xs text-zinc-500">{{ $row['profile']?->direct_order_enabled ? __('Directly published') : ($row['profile'] ? __('Profile hidden') : __('No storefront profile')) }}</span></td>
                                <td class="p-3">@if($row['usage']['total_references'] > 0)<span class="block">{{ $referenceLabels->join(' · ') }}</span>@if($row['usage']['recipe_link'])<span class="text-xs text-zinc-500">{{ __('recipe link: 1') }}</span>@endif @else<span class="text-emerald-700">{{ __('No known references') }}</span>@endif</td>
                                <td class="p-3"><div class="flex justify-end gap-2"><flux:button type="button" size="sm" wire:click="disableCleanupItem({{ $item->id }})" wire:loading.attr="disabled">{{ __('Disable and hide') }}</flux:button>@if($row['usage']['total_references'] === 0)<flux:button type="button" size="sm" variant="danger" wire:click="deleteCleanupItem({{ $item->id }})" wire:confirm="{{ __('Permanently delete this unused menu item? This cannot be undone.') }}" wire:loading.attr="disabled">{{ __('Delete unused') }}</flux:button>@endif</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-8 text-center text-zinc-500">{{ __('No menu items match this review.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-zinc-500">{{ __('Only rows with no known reference can be deleted. Referenced mistakes stay in history and can be disabled and hidden.') }}</p>
        </div>
    @endif
</div>
