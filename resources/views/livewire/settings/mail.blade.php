<?php

use App\Models\User;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsConflictException;
use App\Services\Mail\MailSettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $smtp_host = '';
    public string $smtp_port = '465';
    public string $security_mode = 'implicit_tls';
    public string $from_address = '';
    public string $from_name = '';

    public string $smtp_username = '';
    public string $smtp_password = '';
    public string $daily_dish_admin_emails = '';

    public string $source = 'environment';
    public bool $username_configured = false;
    public bool $password_configured = false;
    public bool $authentication_configured = false;
    public ?int $recipient_count = 0;
    public int $expected_revision = 0;
    public ?string $updated_at = null;
    public bool $needs_repair = false;

    public function mount(MailSettingsService $settings): void
    {
        $this->authorizeAdministrator();

        try {
            $this->fillFromState($settings->state());
        } catch (MailConfigurationUnavailableException) {
            abort(503, __('Mail configuration is temporarily unavailable.'));
        }
    }

    public function save(MailSettingsService $settings): void
    {
        $actor = $this->authorizeAdministrator();

        try {
            $data = $this->validate([
                'smtp_host' => ['required', 'string', 'max:255'],
                'smtp_port' => ['required', 'integer', 'between:1,65535'],
                'security_mode' => ['required', 'in:implicit_tls,starttls,none'],
                'from_address' => ['required', 'string', 'email:rfc', 'max:254'],
                'from_name' => ['required', 'string', 'max:255'],
                'smtp_username' => ['nullable', 'string', 'max:255'],
                'smtp_password' => ['nullable', 'string', 'max:4096'],
                'daily_dish_admin_emails' => ['nullable', 'string', 'max:8192'],
                'expected_revision' => ['required', 'integer', 'min:0'],
            ]);

            $result = $settings->save($data, $actor, (int) $data['expected_revision']);
            $this->fillFromState($result['state']);
            session()->flash('status', __('Mail settings saved. New deliveries will use them immediately.'));
        } catch (MailSettingsConflictException $exception) {
            $this->fillFromState($exception->current);
            $this->addError('expected_revision', $exception->getMessage());
        } finally {
            $this->resetReplacementInputs();
        }
    }

    public function clearAuthentication(bool $confirmed, MailSettingsService $settings): void
    {
        $actor = $this->authorizeAdministrator();

        try {
            $result = $settings->clearAuthentication($actor, $this->expected_revision, $confirmed);
            $this->fillFromState($result['state']);
            session()->flash('status', __('SMTP authentication cleared.'));
        } catch (MailSettingsConflictException $exception) {
            $this->fillFromState($exception->current);
            $this->addError('expected_revision', $exception->getMessage());
        }
    }

    private function authorizeAdministrator(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->isActive() && $actor->hasRole('admin') && ! $actor->isCustomerPortalUser(), 403);

        return $actor;
    }

    /** @param array<string, mixed> $state */
    private function fillFromState(array $state): void
    {
        $this->smtp_host = (string) $state['smtp_host'];
        $this->smtp_port = (string) $state['smtp_port'];
        $this->security_mode = (string) $state['security_mode'];
        $this->from_address = (string) $state['from_address'];
        $this->from_name = (string) $state['from_name'];
        $this->source = (string) $state['source'];
        $this->username_configured = (bool) $state['username_configured'];
        $this->password_configured = (bool) $state['password_configured'];
        $this->authentication_configured = (bool) $state['authentication_configured'];
        $this->recipient_count = isset($state['recipient_count']) ? (int) $state['recipient_count'] : null;
        $this->expected_revision = (int) $state['revision'];
        $this->updated_at = $state['updated_at'] ?? null;
        $this->needs_repair = (bool) $state['needs_repair'];
    }

    private function resetReplacementInputs(): void
    {
        $this->smtp_username = '';
        $this->smtp_password = '';
        $this->daily_dish_admin_emails = '';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout
        :heading="__('Mail')"
        :subheading="__('Manage the SMTP connection, sender identity, and daily dish administrator recipients used by RMS.')"
        contentClass="mt-5 w-full max-w-4xl"
    >
        <div class="space-y-6">
            @if(session('status'))
                <p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                    {{ session('status') }}
                </p>
            @endif

            @error('expected_revision')
                <p role="alert" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">{{ $message }}</p>
            @enderror

            @error('mail_settings')
                <p role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">{{ $message }}</p>
            @enderror

            @if($needs_repair)
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Saved mail settings need repair')">
                    {{ __('Email delivery is blocked because protected values cannot be read. Replace the complete recipient list and both SMTP credentials, or clear authentication, before saving again.') }}
                </flux:callout>
            @endif

            <div class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('Mail configuration status') }}">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Configuration source') }}</p>
                    <p class="mt-2 text-base font-semibold text-neutral-900 dark:text-white">
                        {{ $source === 'database' ? __('Saved in RMS') : __('Deployment environment') }}
                    </p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('SMTP authentication') }}</p>
                    <p class="mt-2 text-base font-semibold text-neutral-900 dark:text-white">
                        {{ $authentication_configured ? __('Configured') : __('Not configured') }}
                    </p>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Admin recipients') }}</p>
                    <p class="mt-2 text-base font-semibold text-neutral-900 dark:text-white">
                        {{ $recipient_count === null ? __('Needs replacement') : trans_choice(':count address|:count addresses', $recipient_count, ['count' => $recipient_count]) }}
                    </p>
                </div>
            </div>

            <form wire:submit="save" class="space-y-6">
                <section class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="smtp-settings-heading">
                    <div class="mb-5">
                        <flux:heading id="smtp-settings-heading" size="lg">{{ __('SMTP connection') }}</flux:heading>
                        <flux:subheading>{{ __('RMS uses this secure connection for order confirmations, payment alerts, reports, and password resets.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <flux:input
                            wire:model="smtp_host"
                            type="text"
                            autocomplete="off"
                            class:input="min-h-11"
                            :label="__('SMTP host')"
                            :description="__('Enter the host only, without https, a path, or credentials.')"
                        />

                        <flux:input
                            wire:model="smtp_port"
                            type="number"
                            min="1"
                            max="65535"
                            step="1"
                            inputmode="numeric"
                            class:input="min-h-11"
                            :label="__('SMTP port')"
                        />

                        <flux:select wire:model="security_mode" :label="__('Connection security')" class="min-h-11">
                            <flux:select.option value="implicit_tls">{{ __('TLS from connection start') }}</flux:select.option>
                            <flux:select.option value="starttls">{{ __('STARTTLS required') }}</flux:select.option>
                            @if(app()->environment(['local', 'testing']))
                                <flux:select.option value="none">{{ __('None, local testing only') }}</flux:select.option>
                            @endif
                        </flux:select>

                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ __('Certificate and hostname verification always remain enabled for secure connections.') }}
                        </div>

                        <flux:input
                            wire:model="smtp_username"
                            type="text"
                            autocomplete="off"
                            class:input="min-h-11"
                            :label="__('Replace SMTP username')"
                            :description="$username_configured ? __('A username is saved. Leave this empty to keep it.') : __('Leave empty when the SMTP server does not require authentication.')"
                        />

                        <flux:input
                            wire:model="smtp_password"
                            type="password"
                            autocomplete="new-password"
                            class:input="min-h-11"
                            :label="__('Replace SMTP password')"
                            :description="$password_configured ? __('A password is saved. Leave this empty to keep it.') : __('Enter a password together with a username.')"
                        />
                    </div>
                </section>

                <section class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="sender-settings-heading">
                    <div class="mb-5">
                        <flux:heading id="sender-settings-heading" size="lg">{{ __('Sender identity') }}</flux:heading>
                        <flux:subheading>{{ __('Customers see this name and address as the sender of RMS emails.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <flux:input wire:model="from_name" type="text" class:input="min-h-11" :label="__('Sender name')" />
                        <flux:input wire:model="from_address" type="email" autocomplete="email" class:input="min-h-11" :label="__('Sender email address')" />
                    </div>
                </section>

                <section class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="recipient-settings-heading">
                    <div class="mb-5">
                        <flux:heading id="recipient-settings-heading" size="lg">{{ __('Daily dish administrator emails') }}</flux:heading>
                        <flux:subheading>{{ __('These recipients receive new daily dish order confirmations and payment operation alerts for the default company.') }}</flux:subheading>
                    </div>

                    <flux:textarea
                        wire:model="daily_dish_admin_emails"
                        rows="4"
                        :label="__('Replace complete recipient list')"
                        :description="__('Enter up to 20 addresses separated by commas, semicolons, or new lines. Leave empty to keep the saved list.')"
                    />
                </section>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        {{ $updated_at ? __('Last updated from RMS. Revision :revision.', ['revision' => $expected_revision]) : __('The first save will move the effective configuration into protected RMS storage.') }}
                    </p>
                    <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ __('Save mail settings') }}</span>
                        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                    </flux:button>
                </div>
            </form>

            <section class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950" aria-labelledby="clear-authentication-heading">
                <flux:heading id="clear-authentication-heading" size="lg">{{ __('SMTP authentication') }}</flux:heading>
                <p class="mt-2 max-w-2xl text-sm text-red-800 dark:text-red-100">
                    {{ __('Clear both credentials only when the mail server accepts unauthenticated connections. Secure transport settings and administrator recipients are kept.') }}
                </p>
                @error('clear_authentication')
                    <p role="alert" class="mt-3 text-sm font-medium text-red-800 dark:text-red-100">{{ $message }}</p>
                @enderror
                <div class="mt-4">
                    <flux:button
                        type="button"
                        variant="danger"
                        class="min-h-11"
                        :disabled="! $authentication_configured"
                        wire:click="clearAuthentication(true)"
                        wire:confirm="{{ __('Clear the saved SMTP username and password?') }}"
                        wire:loading.attr="disabled"
                        wire:target="clearAuthentication"
                    >
                        {{ __('Clear authentication') }}
                    </flux:button>
                </div>
            </section>
        </div>
    </x-settings.layout>
</section>
