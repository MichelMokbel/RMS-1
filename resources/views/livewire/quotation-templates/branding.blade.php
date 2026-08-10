<?php

use App\Models\Branch;
use App\Models\CompanyDocumentProfile;
use App\Services\Quotations\CompanyDocumentProfileService;
use App\Services\Quotations\Storage\QuotationAssetService;
use App\Services\Security\BranchAccessService;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public int $company_id = 0;
    public string $legal_name_en = '';
    public string $legal_name_ar = '';
    public string $address_en = '';
    public string $address_ar = '';
    public string $phone = '';
    public string $email = '';
    public string $website = '';
    public string $commercial_registration = '';
    public string $tax_registration = '';
    public string $brand_color = '#1F2937';
    public string $default_terms_en = '';
    public string $default_terms_ar = '';
    public ?int $logo_asset_id = null;
    public ?TemporaryUploadedFile $logo = null;
    public ?TemporaryUploadedFile $asset_upload = null;

    public function mount(BranchAccessService $access, ?int $company = null): void
    {
        abort_unless(auth()->user()?->can('quotation-templates.manage'), 403);
        $allowed = $this->allowedCompanyIds($access);
        $this->company_id = $company && $allowed->contains($company) ? $company : (int) $allowed->first();
        abort_if($this->company_id === 0, 403);
        $this->loadProfile();
    }

    public function updatedCompanyId(BranchAccessService $access): void
    {
        abort_unless($this->allowedCompanyIds($access)->contains($this->company_id), 403);
        $this->loadProfile();
    }

    public function save(BranchAccessService $access, CompanyDocumentProfileService $profiles, QuotationAssetService $assets): void
    {
        abort_unless($this->allowedCompanyIds($access)->contains($this->company_id), 403);
        $this->validate([
            'legal_name_en' => ['required', 'string', 'max:255'], 'legal_name_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:2000'], 'address_ar' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'website' => ['nullable', 'url', 'max:255'],
            'commercial_registration' => ['nullable', 'string', 'max:100'], 'tax_registration' => ['nullable', 'string', 'max:100'],
            'brand_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'default_terms_en' => ['nullable', 'string', 'max:10000'], 'default_terms_ar' => ['nullable', 'string', 'max:10000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.config('quotations.max_asset_kb', 5120)],
        ]);

        if ($this->logo) {
            $this->logo_asset_id = $assets->storeImage($this->company_id, $this->logo, auth()->id())->id;
        }
        $profiles->update($this->company_id, collect(get_object_vars($this))->only([
            'legal_name_en', 'legal_name_ar', 'address_en', 'address_ar', 'phone', 'email', 'website',
            'commercial_registration', 'tax_registration', 'brand_color', 'default_terms_en', 'default_terms_ar', 'logo_asset_id',
        ])->map(fn ($value) => $value === '' ? null : $value)->all());
        $this->logo = null;
        session()->flash('status', __('Company document branding saved.'));
    }

    public function uploadAsset(BranchAccessService $access, QuotationAssetService $assets): void
    {
        abort_unless($this->allowedCompanyIds($access)->contains($this->company_id), 403);
        $this->validate([
            'asset_upload' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.config('quotations.max_asset_kb', 5120)],
        ]);
        $assets->storeImage($this->company_id, $this->asset_upload, auth()->id());
        $this->asset_upload = null;
        session()->flash('status', __('Reusable document image uploaded.'));
    }

    public function with(BranchAccessService $access, QuotationAssetService $assets): array
    {
        $profile = CompanyDocumentProfile::query()->with('logoAsset')->where('company_id', $this->company_id)->first();
        return [
            'companies' => \App\Models\AccountingCompany::query()->whereIn('id', $this->allowedCompanyIds($access))->orderBy('name')->get(),
            'logoUrl' => $profile?->logoAsset ? rescue(fn () => $assets->temporaryUrl($profile->logoAsset), null, false) : null,
            'reusableAssets' => \App\Models\DocumentAsset::query()->where('company_id', $this->company_id)->where('kind', 'image')->latest()->limit(100)->get(),
        ];
    }

    private function loadProfile(): void
    {
        $profile = app(CompanyDocumentProfileService::class)->getOrCreate($this->company_id);
        foreach (['legal_name_en', 'legal_name_ar', 'address_en', 'address_ar', 'phone', 'email', 'website', 'commercial_registration', 'tax_registration', 'default_terms_en', 'default_terms_ar'] as $field) $this->{$field} = (string) $profile->{$field};
        $this->brand_color = $profile->brand_color ?: '#1F2937';
        $this->logo_asset_id = $profile->logo_asset_id;
    }

    private function allowedCompanyIds(BranchAccessService $access)
    {
        return Branch::query()->whereIn('id', $access->allowedBranchIds(auth()->user()))->pluck('company_id')->filter()->unique();
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center gap-3"><flux:button :href="route('quotation-templates.index')" wire:navigate variant="ghost" icon="arrow-left" size="sm" /><div><h1 class="text-xl font-semibold">{{ __('Company document branding') }}</h1><p class="text-sm text-neutral-500">{{ __('Shared legal details and branding inherited by every branch quotation.') }}</p></div></div>
    @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <form wire:submit="save" class="max-w-4xl space-y-6">
        <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><div class="mb-4"><label class="mb-1 block text-sm font-medium">{{ __('Company') }}</label><select wire:model.live="company_id" class="w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></div><div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="legal_name_en" :label="__('Legal name (English)')" required /><div dir="rtl"><flux:input wire:model="legal_name_ar" :label="__('Legal name (Arabic)')" /></div><flux:textarea wire:model="address_en" :label="__('Address (English)')" rows="3" /><div dir="rtl"><flux:textarea wire:model="address_ar" :label="__('Address (Arabic)')" rows="3" /></div><flux:input wire:model="phone" :label="__('Phone')" /><flux:input wire:model="email" type="email" :label="__('Email')" /><flux:input wire:model="website" type="url" :label="__('Website')" /><flux:input wire:model="commercial_registration" :label="__('Commercial registration')" /><flux:input wire:model="tax_registration" :label="__('Tax registration')" /><flux:input wire:model="brand_color" type="color" :label="__('Brand color')" /></div></section>
        <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Logo') }}</h2>@if($logoUrl)<img src="{{ $logoUrl }}" alt="{{ __('Current company logo') }}" class="mb-4 max-h-24 max-w-64 rounded border border-neutral-200 object-contain p-2" />@endif<input wire:model="logo" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full rounded-md border border-neutral-300 p-2 text-sm" /><p class="mt-2 text-xs text-neutral-500">{{ __('PNG, JPEG or WebP, up to :size MB. Stored privately.', ['size' => round(config('quotations.max_asset_kb', 5120)/1024)]) }}</p>@error('logo')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</section>
        <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="font-semibold">{{ __('Reusable document images') }}</h2><p class="mt-1 text-sm text-neutral-500">{{ __('Upload images that can be selected by image blocks in templates and quotations.') }}</p><div class="mt-4 flex flex-col gap-3 sm:flex-row"><input wire:model="asset_upload" type="file" accept="image/png,image/jpeg,image/webp" class="block grow rounded-md border border-neutral-300 p-2 text-sm" /><flux:button type="button" wire:click="uploadAsset" wire:loading.attr="disabled">{{ __('Upload image') }}</flux:button></div>@error('asset_upload')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror@if($reusableAssets->isNotEmpty())<ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2">@foreach($reusableAssets as $asset)<li class="rounded border border-neutral-200 px-3 py-2 dark:border-neutral-700"><span class="font-medium">{{ $asset->original_name ?: __('Document image') }}</span><span class="ml-2 text-xs text-neutral-500">{{ number_format($asset->size_bytes / 1024, 1) }} KB</span></li>@endforeach</ul>@endif</section>
        <section class="rounded-lg border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"><h2 class="mb-4 font-semibold">{{ __('Default terms') }}</h2><div class="grid gap-4 sm:grid-cols-2"><flux:textarea wire:model="default_terms_en" :label="__('English terms')" rows="7" /><div dir="rtl"><flux:textarea wire:model="default_terms_ar" :label="__('Arabic terms')" rows="7" /></div></div></section>
        <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save branding') }}</flux:button></div>
    </form>
</div>
