<?php

namespace App\Services\Payments;

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentSettingsService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly PaymentOperationsAccessService $access,
    ) {}

    public function forCompany(int $companyId): ?PaymentSetting
    {
        return PaymentSetting::query()->where('company_id', $companyId)->first();
    }

    /** @return array<string, mixed> */
    public function payload(PaymentSetting $settings): array
    {
        return [
            'company_id' => (int) $settings->company_id,
            'checkout_duration_minutes' => (int) $settings->checkout_duration_minutes,
            'booking_cutoff_time' => substr((string) $settings->booking_cutoff_time, 0, 5),
            'timezone' => (string) $settings->timezone,
            'order_support_phone' => (string) $settings->order_support_phone,
            'updated_at' => $settings->updated_at?->toISOString(),
            'version' => $this->version($settings),
        ];
    }

    public function version(PaymentSetting $settings): string
    {
        return hash('sha256', json_encode([
            'company_id' => (int) $settings->company_id,
            'checkout_duration_minutes' => (int) $settings->checkout_duration_minutes,
            'booking_cutoff_time' => substr((string) $settings->booking_cutoff_time, 0, 8),
            'timezone' => (string) $settings->timezone,
            'order_support_phone' => (string) $settings->order_support_phone,
            'updated_at' => $settings->updated_at?->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(int $companyId, array $data, int $actorId): PaymentSetting
    {
        return $this->persist($companyId, $data, $actorId)['settings'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{settings: PaymentSetting, audit_id: int}
     */
    public function saveVersioned(int $companyId, array $data, User $actor, string $expectedVersion): array
    {
        $this->access->assertCanManageSettings($actor, $companyId);

        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages([
                'settings' => __('Payment settings cannot be changed while audit storage is unavailable.'),
            ]);
        }

        return $this->persist($companyId, $data, (int) $actor->id, $expectedVersion);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{settings: PaymentSetting, audit_id: int}
     */
    private function persist(int $companyId, array $data, int $actorId, ?string $expectedVersion = null): array
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
            $supportPhone,
            $expectedVersion,
        ): array {
            $settings = PaymentSetting::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if ($expectedVersion !== null && (! $settings || ! hash_equals($this->version($settings), $expectedVersion))) {
                throw new PaymentSettingsConflictException($settings ? $this->payload($settings) : []);
            }

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

            $auditId = (int) AccountingAuditLog::query()
                ->where('action', 'payment.settings.updated')
                ->where('actor_id', $actorId)
                ->where('subject_type', PaymentSetting::class)
                ->where('subject_id', $settings->id)
                ->latest('id')
                ->value('id');

            return [
                'settings' => $settings->refresh(),
                'audit_id' => $auditId,
            ];
        });
    }
}
