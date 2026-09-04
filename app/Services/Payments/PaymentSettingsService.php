<?php

namespace App\Services\Payments;

use App\Models\AccountingCompany;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentSettingsService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    public function forCompany(int $companyId): ?PaymentSetting
    {
        return PaymentSetting::query()->where('company_id', $companyId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(int $companyId, array $data, int $actorId): PaymentSetting
    {
        $company = AccountingCompany::query()->find($companyId);
        $actor = User::query()->find($actorId);

        if (! $company || ! $company->is_active) {
            throw ValidationException::withMessages([
                'company_id' => __('An active accounting company is required.'),
            ]);
        }

        if (! $actor || $actor->status !== 'active') {
            throw ValidationException::withMessages([
                'actor' => __('An active actor is required to change payment settings.'),
            ]);
        }

        $duration = filter_var($data['checkout_duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 5 || $duration > 60) {
            throw ValidationException::withMessages([
                'checkout_duration_minutes' => __('The checkout duration must be between 5 and 60 minutes.'),
            ]);
        }

        $cutoff = trim((string) ($data['booking_cutoff_time'] ?? ''));
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
            throw ValidationException::withMessages([
                'booking_cutoff_time' => __('The booking cutoff must use HH:MM in Qatar time.'),
            ]);
        }

        $timezone = (string) ($data['timezone'] ?? PaymentSetting::TIMEZONE);
        if ($timezone !== PaymentSetting::TIMEZONE) {
            throw ValidationException::withMessages([
                'timezone' => __('The payment timezone must remain Asia/Qatar.'),
            ]);
        }

        $supportPhone = trim((string) ($data['order_support_phone'] ?? ''));
        if ($supportPhone === '' || mb_strlen($supportPhone) > 50) {
            throw ValidationException::withMessages([
                'order_support_phone' => __('A valid order support phone is required.'),
            ]);
        }

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $duration,
            $cutoff,
            $timezone,
            $supportPhone
        ): PaymentSetting {
            $settings = PaymentSetting::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            $before = $settings?->only([
                'checkout_duration_minutes',
                'booking_cutoff_time',
                'timezone',
                'order_support_phone',
            ]);

            $settings ??= new PaymentSetting([
                'company_id' => $companyId,
                'created_by' => $actorId,
            ]);

            $settings->fill([
                'checkout_duration_minutes' => $duration,
                'booking_cutoff_time' => $cutoff.':00',
                'timezone' => $timezone,
                'order_support_phone' => $supportPhone,
                'updated_by' => $actorId,
            ])->save();

            $this->auditLog->log(
                'payment.settings.updated',
                $actorId,
                $settings,
                [
                    'before' => $before,
                    'after' => $settings->only([
                        'checkout_duration_minutes',
                        'booking_cutoff_time',
                        'timezone',
                        'order_support_phone',
                    ]),
                ],
                $companyId,
            );

            return $settings->refresh();
        });
    }
}
