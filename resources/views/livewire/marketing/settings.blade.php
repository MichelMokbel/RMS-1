<?php

use App\Models\MarketingPlatformAccount;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    // Plain (non-sensitive) fields
    public string $meta_app_id = '';

    public string $meta_business_id = '';

    public string $google_login_customer_id = '';

    public string $google_client_id = '';

    public string $s3_asset_bucket = '';

    public bool $meta_sync_enabled = false;

    public bool $google_sync_enabled = false;

    // Sensitive fields — empty string = "do not change", filled = update value
    public string $meta_app_secret = '';

    public string $meta_system_user_token = '';

    public string $google_developer_token = '';

    public string $google_client_secret = '';

    public string $google_refresh_token = '';

    // Configured status indicators (read-only)
    public bool $metaConfigured = false;

    public bool $googleConfigured = false;

    public bool $s3Configured = false;

    public bool $googleDeveloperTokenSaved = false;

    public bool $googleClientSecretSaved = false;

    public bool $googleRefreshTokenSaved = false;

    public string $meta_ad_account_id = '';

    public string $meta_ad_account_name = '';

    public string $google_customer_id = '';

    public string $google_account_name = '';

    public function mount(MarketingSettingsService $settingsService): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);

        $settings = $settingsService->get();
        $this->meta_app_id = $settings->meta_app_id ?? '';
        $this->meta_business_id = $settings->meta_business_id ?? '';
        $this->google_login_customer_id = $settings->google_login_customer_id ?? '';
        $this->google_client_id = $settings->google_client_id ?? '';
        $this->s3_asset_bucket = $settings->s3_asset_bucket ?? '';
        $this->meta_sync_enabled = $settings->meta_sync_enabled ?? false;
        $this->google_sync_enabled = $settings->google_sync_enabled ?? false;

        $this->metaConfigured = $settingsService->isMetaConfigured();
        $this->googleConfigured = $settingsService->isGoogleConfigured();
        $this->s3Configured = $settingsService->isS3Configured();
        $this->googleDeveloperTokenSaved = ! empty($settings->google_developer_token);
        $this->googleClientSecretSaved = ! empty($settings->google_client_secret);
        $this->googleRefreshTokenSaved = ! empty($settings->google_refresh_token);
    }

    public function save(
        MarketingSettingsService $settingsService,
        MarketingActivityLogService $activityLog,
    ): void {
        abort_unless(auth()->user()->can('marketing.manage'), 403);

        $settingsService->save([
            'meta_app_id' => $this->meta_app_id,
            'meta_business_id' => $this->meta_business_id,
            'google_login_customer_id' => $this->google_login_customer_id,
            'google_client_id' => $this->google_client_id,
            's3_asset_bucket' => $this->s3_asset_bucket,
            'meta_sync_enabled' => $this->meta_sync_enabled,
            'google_sync_enabled' => $this->google_sync_enabled,
            // Sensitive: pass null if blank (meaning "don't change"), actual value if filled
            'meta_app_secret' => $this->meta_app_secret !== '' ? $this->meta_app_secret : null,
            'meta_system_user_token' => $this->meta_system_user_token !== '' ? $this->meta_system_user_token : null,
            'google_developer_token' => $this->google_developer_token !== '' ? $this->google_developer_token : null,
            'google_client_secret' => $this->google_client_secret !== '' ? $this->google_client_secret : null,
            'google_refresh_token' => $this->google_refresh_token !== '' ? $this->google_refresh_token : null,
        ], auth()->id());

        // Clear input fields for sensitive values after save
        $this->meta_app_secret = '';
        $this->meta_system_user_token = '';
        $this->google_developer_token = '';
        $this->google_client_secret = '';
        $this->google_refresh_token = '';

        // Reload configured status
        $this->metaConfigured = $settingsService->isMetaConfigured();
        $this->googleConfigured = $settingsService->isGoogleConfigured();
        $this->s3Configured = $settingsService->isS3Configured();
        $settings = $settingsService->get();
        $this->googleDeveloperTokenSaved = ! empty($settings->google_developer_token);
        $this->googleClientSecretSaved = ! empty($settings->google_client_secret);
        $this->googleRefreshTokenSaved = ! empty($settings->google_refresh_token);

        $activityLog->log('settings.updated', auth()->id(), null, ['section' => 'marketing']);

        session()->flash('status', __('Settings saved.'));
    }

    public function addMetaAccount(MarketingActivityLogService $activityLog): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);

        $validated = $this->validate([
            'meta_ad_account_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('marketing_platform_accounts', 'external_account_id')
                    ->where('platform', 'meta'),
            ],
            'meta_ad_account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $accountId = str_starts_with($validated['meta_ad_account_id'], 'act_')
            ? $validated['meta_ad_account_id']
            : 'act_'.$validated['meta_ad_account_id'];

        $account = MarketingPlatformAccount::query()->create([
            'platform' => 'meta',
            'external_account_id' => $accountId,
            'account_name' => $validated['meta_ad_account_name'] ?: $accountId,
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        $activityLog->log('platform_account.created', auth()->id(), $account, [
            'platform' => 'meta',
            'external_account_id' => $accountId,
        ]);

        $this->meta_ad_account_id = '';
        $this->meta_ad_account_name = '';

        session()->flash('status', __('Meta ad account added. Use Sync Now to pull campaigns.'));
    }

    public function addGoogleAccount(MarketingActivityLogService $activityLog): void
    {
        abort_unless(auth()->user()->can('marketing.manage'), 403);

        $validated = $this->validate([
            'google_customer_id' => ['required', 'string', 'max:255'],
            'google_account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $customerId = str_replace('-', '', trim($validated['google_customer_id']));
        if ($customerId === '' || ! ctype_digit($customerId) || strlen($customerId) !== 10) {
            throw ValidationException::withMessages([
                'google_customer_id' => __('Enter a valid 10-digit Google Ads customer ID.'),
            ]);
        }

        $exists = MarketingPlatformAccount::query()
            ->where('platform', 'google')
            ->where('external_account_id', $customerId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'google_customer_id' => __('This Google Ads customer account is already connected.'),
            ]);
        }

        $account = MarketingPlatformAccount::query()->create([
            'platform' => 'google',
            'external_account_id' => $customerId,
            'account_name' => $validated['google_account_name'] ?: 'Google Ads '.$customerId,
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        $activityLog->log('platform_account.created', auth()->id(), $account, [
            'platform' => 'google',
            'external_account_id' => $customerId,
        ]);

        $this->google_customer_id = '';
        $this->google_account_name = '';

        session()->flash('status', __('Google Ads account added. Use Sync Now to pull campaigns.'));
    }

    public function with(): array
    {
        return [
            'metaAccounts' => MarketingPlatformAccount::query()
                ->where('platform', 'meta')
                ->orderBy('account_name')
                ->get(),
            'googleAccounts' => MarketingPlatformAccount::query()
                ->where('platform', 'google')
                ->orderBy('account_name')
                ->get(),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center gap-3">
        <flux:button :href="route('marketing.dashboard')" variant="ghost" icon="arrow-left" size="sm" wire:navigate />
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Marketing Settings') }}</h1>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">

        {{-- Meta Integration --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Meta Integration') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Facebook & Instagram Ads via Meta Marketing API') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @if($metaConfigured)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Configured') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600">{{ __('Not configured') }}</span>
                    @endif
                    <flux:checkbox wire:model="meta_sync_enabled" label="{{ __('Sync enabled') }}" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('App ID') }}</label>
                    <flux:input wire:model="meta_app_id" placeholder="1234567890" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Business ID') }}</label>
                    <flux:input wire:model="meta_business_id" placeholder="Business Portfolio ID" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('App Secret') }}
                        @if($metaConfigured) <span class="text-emerald-600">{{ __('(saved — enter new value to replace)') }}</span>@endif
                    </label>
                    <flux:input wire:model="meta_app_secret" type="password" placeholder="{{ $metaConfigured ? __('Leave blank to keep current') : '' }}" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('System User Access Token') }}
                        @if($metaConfigured) <span class="text-emerald-600">{{ __('(saved — enter new value to replace)') }}</span>@endif
                    </label>
                    <flux:input wire:model="meta_system_user_token" type="password" placeholder="{{ $metaConfigured ? __('Leave blank to keep current') : '' }}" />
                </div>
            </div>
        </div>

        {{-- Meta Ad Accounts --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Meta Ad Accounts') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Add each ad account you want synced. Credentials alone do not create account connections.') }}</p>
            </div>
            <div class="space-y-4 p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[1fr_1fr_auto]">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Ad Account ID') }}</label>
                        <flux:input wire:model="meta_ad_account_id" placeholder="act_123456789 or 123456789" />
                        @error('meta_ad_account_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Display Name') }}</label>
                        <flux:input wire:model="meta_ad_account_name" placeholder="{{ __('Main Meta Ad Account') }}" />
                        @error('meta_ad_account_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <flux:button type="button" wire:click="addMetaAccount" variant="primary">
                            {{ __('Add Account') }}
                        </flux:button>
                    </div>
                </div>

                @if($metaAccounts->isEmpty())
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                        {{ __('No Meta ad accounts are connected yet. Campaign sync will not run until at least one active ad account is added.') }}
                    </div>
                @else
                    <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Name') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Account ID') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Last Synced') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                @foreach($metaAccounts as $account)
                                    <tr>
                                        <td class="px-3 py-2 text-zinc-900 dark:text-white">{{ $account->account_name }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $account->external_account_id }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ ucfirst($account->status) }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $account->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Google Ads Integration --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Google Ads Integration') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Google Ads API via Manager Account (MCC)') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @if($googleConfigured)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Configured') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600">{{ __('Not configured') }}</span>
                    @endif
                    <flux:checkbox wire:model="google_sync_enabled" label="{{ __('Sync enabled') }}" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('MCC Login Customer ID') }}</label>
                    <flux:input wire:model="google_login_customer_id" placeholder="1234567890" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('OAuth Client ID') }}</label>
                    <flux:input wire:model="google_client_id" placeholder="xxx.apps.googleusercontent.com" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Developer Token') }}
                        @if($googleDeveloperTokenSaved) <span class="text-emerald-600">{{ __('(saved — enter new value to replace)') }}</span>@endif
                    </label>
                    <flux:input wire:model="google_developer_token" type="password" placeholder="{{ $googleDeveloperTokenSaved ? __('Leave blank to keep current') : '' }}" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('OAuth Client Secret') }}
                        @if($googleClientSecretSaved) <span class="text-emerald-600">{{ __('(saved — enter new value to replace)') }}</span>@endif
                    </label>
                    <flux:input wire:model="google_client_secret" type="password" placeholder="{{ $googleClientSecretSaved ? __('Leave blank to keep current') : '' }}" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('OAuth Refresh Token') }}
                        @if($googleRefreshTokenSaved) <span class="text-emerald-600">{{ __('(saved — enter new value to replace)') }}</span>@endif
                    </label>
                    <flux:input wire:model="google_refresh_token" type="password" placeholder="{{ $googleRefreshTokenSaved ? __('Leave blank to keep current') : '' }}" />
                </div>
                <div class="md:col-span-2 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 dark:border-sky-700 dark:bg-sky-900/20">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm font-medium text-sky-900 dark:text-sky-200">{{ __('Generate refresh token automatically') }}</p>
                            <p class="mt-1 text-xs text-sky-800 dark:text-sky-300">
                                {{ __('Save the OAuth client ID and secret first, then connect with the Google user that has access to your MCC or ad account.') }}
                            </p>
                            <p class="mt-2 break-all text-xs text-sky-700 dark:text-sky-300">
                                {{ __('Authorized redirect URI:') }} {{ route('marketing.google.oauth.callback') }}
                            </p>
                        </div>
                        <flux:button :href="route('marketing.google.oauth.redirect')" variant="primary" icon="link">
                            {{ __('Connect Google Ads') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Google Ads Accounts --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Google Ads Accounts') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Add each client customer ID you want synced. The MCC login customer ID is configured above.') }}</p>
            </div>
            <div class="space-y-4 p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[1fr_1fr_auto]">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Client Customer ID') }}</label>
                        <flux:input wire:model="google_customer_id" placeholder="123-456-7890 or 1234567890" />
                        @error('google_customer_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Display Name') }}</label>
                        <flux:input wire:model="google_account_name" placeholder="{{ __('Main Google Ads Account') }}" />
                        @error('google_account_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <flux:button type="button" wire:click="addGoogleAccount" variant="primary">
                            {{ __('Add Account') }}
                        </flux:button>
                    </div>
                </div>

                @if($googleAccounts->isEmpty())
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                        {{ __('No Google Ads accounts are connected yet. Campaign sync will not run until at least one active customer account is added.') }}
                    </div>
                @else
                    <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Name') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Customer ID') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Status') }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Last Synced') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                @foreach($googleAccounts as $account)
                                    <tr>
                                        <td class="px-3 py-2 text-zinc-900 dark:text-white">{{ $account->account_name }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $account->external_account_id }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ ucfirst($account->status) }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ $account->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- S3 / Assets --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Asset Storage (S3)') }}</h2>
                    <p class="text-xs text-zinc-500">{{ __('Dedicated S3 bucket for marketing assets') }}</p>
                </div>
                @if($s3Configured)
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Configured') }}</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600">{{ __('Not configured') }}</span>
                @endif
            </div>
            <div class="p-4">
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('S3 Bucket Name') }}</label>
                <flux:input wire:model="s3_asset_bucket" placeholder="my-marketing-assets" class="max-w-sm" />
                <p class="mt-1 text-xs text-zinc-400">{{ __('AWS credentials are shared with the main S3 configuration. The bucket must already exist with CORS configured for browser uploads.') }}</p>
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save Settings') }}</flux:button>
        </div>
    </form>
</div>
