<?php

use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\PaymentOperationsAccessService;
use App\Services\Payments\PaymentSettingsConflictException;
use App\Services\Payments\PaymentSettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public int $checkout_duration_minutes = 15;
    public string $booking_cutoff_time = '23:00';
    public string $timezone = PaymentSetting::TIMEZONE;
    public string $order_support_phone = '';
    public string $expected_version = '';

    public function mount(
        AccountingContextService $context,
        PaymentOperationsAccessService $access,
        PaymentSettingsService $settings,
    ): void {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = $context->defaultCompanyId();
        abort_unless($companyId, 422, __('An active default accounting company is required.'));
        $access->assertCanManageSettings($actor, $companyId);
        $record = $settings->forCompany($companyId);
        abort_unless($record, 404, __('Payment settings have not been initialized.'));
        $this->fillFromPayload($settings->payload($record));
    }

    public function save(AccountingContextService $context, PaymentSettingsService $settings): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = $context->defaultCompanyId();
        abort_unless($companyId, 422, __('An active default accounting company is required.'));
        $data = $this->validate([
            'checkout_duration_minutes' => ['required', 'integer', 'between:5,60'],
            'booking_cutoff_time' => ['required', 'date_format:H:i'],
            'order_support_phone' => ['required', 'string', 'max:50'],
            'expected_version' => ['required', 'string', 'size:64'],
        ]);

        try {
            $result = $settings->saveVersioned($companyId, [
                'checkout_duration_minutes' => $data['checkout_duration_minutes'],
                'booking_cutoff_time' => $data['booking_cutoff_time'],
                'timezone' => PaymentSetting::TIMEZONE,
                'order_support_phone' => $data['order_support_phone'],
            ], $actor, $data['expected_version']);
        } catch (PaymentSettingsConflictException $exception) {
            if ($exception->current !== []) {
                $this->fillFromPayload($exception->current);
            }
            $this->addError('expected_version', $exception->getMessage());

            return;
        }

        $this->fillFromPayload($settings->payload($result['settings']));
        session()->flash('status', __('Customer payment settings saved.'));
    }

    /** @param array<string, mixed> $payload */
    private function fillFromPayload(array $payload): void
    {
        $this->checkout_duration_minutes = (int) $payload['checkout_duration_minutes'];
        $this->booking_cutoff_time = (string) $payload['booking_cutoff_time'];
        $this->timezone = (string) $payload['timezone'];
        $this->order_support_phone = (string) $payload['order_support_phone'];
        $this->expected_version = (string) $payload['version'];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout
        :heading="__('Customer Payments')"
        :subheading="__('Configure checkout timing and customer support details for the default company.')"
    >
        @if(session('status'))
            <p role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </p>
        @endif

        @error('expected_version')
            <p role="alert" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">{{ $message }}</p>
        @enderror

        <form wire:submit="save" class="mt-6 space-y-5">
            <flux:input
                wire:model="checkout_duration_minutes"
                type="number"
                min="5"
                max="60"
                step="1"
                :label="__('Checkout duration (minutes)')"
                :description="__('New checkout attempts keep this duration. Existing attempts keep their original expiry.')"
            />

            <flux:input
                wire:model="booking_cutoff_time"
                type="time"
                step="60"
                :label="__('Membership booking change cutoff')"
                :description="__('Qatar time on the day before service. Existing bookings keep their recorded cutoff.')"
            />

            <flux:input
                :value="$timezone"
                type="text"
                readonly
                :label="__('Timezone')"
                :description="__('Payment and booking deadlines remain fixed to Qatar time.')"
            />

            <flux:input
                wire:model="order_support_phone"
                type="tel"
                maxlength="50"
                :label="__('Order support contact')"
                :description="__('Shown when a customer needs help with an order or a deadline has passed.')"
            />

            <div class="rounded-md border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                {{ __('SkipCash credentials and signing secrets are managed through deployment configuration and are never editable here.') }}
            </div>

            <div class="flex flex-wrap justify-between gap-3">
                <flux:button :href="route('receivables.payments.skipcash.index')" variant="ghost" wire:navigate>{{ __('Back to SkipCash payments') }}</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Save payment settings') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
