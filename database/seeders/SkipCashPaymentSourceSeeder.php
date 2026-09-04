<?php

namespace Database\Seeders;

use App\Models\AccountingCompany;
use App\Models\LedgerAccount;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SkipCashPaymentSourceSeeder extends Seeder
{
    public function run(): void
    {
        $clearingAccountId = (int) config('payments.skipcash.clearing_account_id');
        if ($clearingAccountId <= 0) {
            return;
        }

        $companyId = app(AccountingContextService::class)->defaultCompanyId();
        $company = $companyId ? AccountingCompany::query()->find($companyId) : null;
        $actorId = (int) config('payments.system_user_id');
        $actor = $actorId > 0 ? User::query()->find($actorId) : null;
        $account = LedgerAccount::query()->find($clearingAccountId);

        if (! $company || ! $company->is_active || ! $company->is_default) {
            throw new RuntimeException('Cannot seed SkipCash without one active default accounting company.');
        }

        if (! $actor || $actor->status !== 'active') {
            throw new RuntimeException('Cannot seed SkipCash without an active system actor.');
        }

        if (! $account || ! $account->is_active || (int) $account->company_id !== (int) $company->id) {
            throw new RuntimeException('The configured SkipCash clearing account is invalid for the default company.');
        }

        DB::transaction(function () use ($company, $actorId, $account): void {
            $existing = PaymentSource::query()
                ->where('company_id', $company->id)
                ->where('code', PaymentSource::CODE_SKIPCASH)
                ->lockForUpdate()
                ->first();

            if ($existing
                && ($existing->method !== PaymentSource::METHOD_SKIPCASH
                    || (int) $existing->clearing_account_id !== (int) $account->id)) {
                throw new RuntimeException(
                    'The existing SkipCash payment source conflicts with the configured clearing account.'
                );
            }

            if (! $existing) {
                $existing = PaymentSource::query()->create([
                    'company_id' => $company->id,
                    'code' => PaymentSource::CODE_SKIPCASH,
                    'name' => 'SkipCash',
                    'method' => PaymentSource::METHOD_SKIPCASH,
                    'clearing_account_id' => $account->id,
                    'is_active' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                app(AccountingAuditLogService::class)->log(
                    'payment.source.seeded',
                    $actorId,
                    $existing,
                    ['code' => PaymentSource::CODE_SKIPCASH, 'clearing_account_id' => (int) $account->id],
                    (int) $company->id,
                );
            }

            $settings = PaymentSetting::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if (! $settings) {
                $settings = PaymentSetting::query()->create([
                    'company_id' => $company->id,
                    'checkout_duration_minutes' => (int) config(
                        'payments.defaults.checkout_duration_minutes',
                        15
                    ),
                    'booking_cutoff_time' => (string) config(
                        'payments.defaults.booking_cutoff_time',
                        '23:00:00'
                    ),
                    'timezone' => (string) config('payments.defaults.timezone', PaymentSetting::TIMEZONE),
                    'order_support_phone' => config('payments.defaults.order_support_phone'),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                app(AccountingAuditLogService::class)->log(
                    'payment.settings.seeded',
                    $actorId,
                    $settings,
                    [
                        'checkout_duration_minutes' => $settings->checkout_duration_minutes,
                        'booking_cutoff_time' => $settings->booking_cutoff_time,
                        'timezone' => $settings->timezone,
                    ],
                    (int) $company->id,
                );
            }
        });
    }
}
