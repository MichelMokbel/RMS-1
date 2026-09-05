<?php

namespace App\Services\Payments;

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentSetupService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly PaymentTermsService $paymentTerms,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     company_id: int|null,
     *     branch_id: int|null,
     *     payment_source_id: int|null,
     *     errors: array<int, array{code: string, message: string}>
     * }
     */
    public function inspectForNewCheckout(?int $branchId = null): array
    {
        $errors = [];
        $companyId = null;
        $sourceId = null;
        $branchId ??= (int) config('payments.public_order_branch_id', 1);

        $requiredTables = [
            'accounting_companies',
            'branches',
            'customers',
            'users',
            'ledger_accounts',
            'payments',
            'payment_sources',
            'payment_settings',
            'payment_checkout_attempts',
            'payment_checkout_targets',
            'payment_provider_transactions',
            'payment_provider_events',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $this->addError($errors, 'PAYMENT_TABLE_MISSING', __('Required payment storage is unavailable.'));

                return $this->result(false, null, $branchId, null, $errors);
            }
        }

        if (! (bool) config('payments.skipcash.enabled', false)) {
            $this->addError($errors, 'SKIPCASH_DISABLED', __('SkipCash checkout is disabled.'));
        }

        if ((bool) config('payments.customer_direct_order_enabled', true)) {
            $this->addError(
                $errors,
                'LEGACY_DIRECT_ORDER_ENABLED',
                __('The legacy direct customer order route must be disabled before live checkout.')
            );
        }

        if ((string) config('payments.skipcash.currency') !== 'QAR'
            || (string) config('payments.skipcash.timezone') !== PaymentSetting::TIMEZONE
            || (int) config('payments.money_scale') !== 100) {
            $this->addError($errors, 'PAYMENT_MONEY_CONTEXT_INVALID', __('SkipCash requires QAR cents and Qatar time.'));
        }

        $companyId = $this->accountingContext->defaultCompanyId();
        $company = $companyId ? AccountingCompany::query()->find($companyId) : null;
        if (! $company || ! $company->is_active || $company->base_currency !== 'QAR') {
            $this->addError($errors, 'DEFAULT_COMPANY_INVALID', __('An active default QAR company is required.'));
        }

        $branch = Branch::query()->find($branchId);
        if (! $branch || ! $branch->is_active) {
            $this->addError($errors, 'PUBLIC_ORDER_BRANCH_INVALID', __('The public order branch is unavailable.'));
        } elseif ($companyId && (int) $branch->company_id !== $companyId) {
            $this->addError(
                $errors,
                'PUBLIC_ORDER_BRANCH_COMPANY_MISMATCH',
                __('The public order branch must belong to the default accounting company.')
            );
        }

        $source = $companyId
            ? PaymentSource::query()
                ->where('company_id', $companyId)
                ->where('code', PaymentSource::CODE_SKIPCASH)
                ->first()
            : null;

        if (! $source || ! $source->is_active || $source->method !== PaymentSource::METHOD_SKIPCASH) {
            $this->addError($errors, 'SKIPCASH_SOURCE_INVALID', __('An active SkipCash payment source is required.'));
        } else {
            $sourceId = (int) $source->id;
            $account = LedgerAccount::query()->find($source->clearing_account_id);
            if (! $account || ! $account->is_active || (int) $account->company_id !== $companyId) {
                $this->addError(
                    $errors,
                    'SKIPCASH_CLEARING_ACCOUNT_INVALID',
                    __('The SkipCash clearing account is unavailable or belongs to another company.')
                );
            }
        }

        $settings = $companyId
            ? PaymentSetting::query()->where('company_id', $companyId)->first()
            : null;
        if (! $settings
            || $settings->checkout_duration_minutes < 5
            || $settings->checkout_duration_minutes > 60
            || $settings->timezone !== PaymentSetting::TIMEZONE
            || trim((string) $settings->order_support_phone) === '') {
            $this->addError($errors, 'PAYMENT_SETTINGS_INVALID', __('Complete company payment settings are required.'));
        }

        $actorId = (int) config('payments.system_user_id');
        $actor = $actorId > 0 ? User::query()->find($actorId) : null;
        if (! $actor || $actor->status !== 'active') {
            $this->addError($errors, 'PAYMENT_SYSTEM_ACTOR_INVALID', __('An active payment system actor is required.'));
        }

        foreach ($this->paymentTerms->inspect()['errors'] as $termsError) {
            $this->addError($errors, $termsError, __('Published payment terms are unavailable or invalid.'));
        }

        $this->inspectProviderConfiguration($errors);

        if (app()->environment('production') && config('queue.default') === 'sync') {
            $this->addError($errors, 'PAYMENT_QUEUE_INVALID', __('An asynchronous queue is required for live payments.'));
        }

        return $this->result($errors === [], $companyId, $branchId, $sourceId, $errors);
    }

    /**
     * @return array{
     *     ready: bool,
     *     company_id: int|null,
     *     branch_id: int|null,
     *     payment_source_id: int|null,
     *     errors: array<int, array{code: string, message: string}>
     * }
     */
    public function assertReadyForNewCheckout(?int $branchId = null): array
    {
        $result = $this->inspectForNewCheckout($branchId);
        if ($result['ready']) {
            return $result;
        }

        throw ValidationException::withMessages([
            'payment_setup' => __('SkipCash checkout is unavailable. Setup codes: :codes', [
                'codes' => implode(', ', array_column($result['errors'], 'code')),
            ]),
        ]);
    }

    /** @param array<int, array{code: string, message: string}> $errors */
    private function inspectProviderConfiguration(array &$errors): void
    {
        if (! in_array(config('payments.skipcash.environment'), ['sandbox', 'production'], true)) {
            $this->addError($errors, 'SKIPCASH_ENVIRONMENT_INVALID', __('The SkipCash environment is invalid.'));
        }

        foreach (['client_id', 'key_id', 'secret_key', 'webhook_secret'] as $key) {
            if (trim((string) config('payments.skipcash.'.$key)) === '') {
                $this->addError($errors, 'SKIPCASH_CREDENTIALS_MISSING', __('SkipCash credentials are incomplete.'));
                break;
            }
        }

        $baseUrl = (string) config('payments.skipcash.base_url');
        $returnUrl = (string) config('payments.skipcash.return_url');
        $webhookUrl = (string) config('payments.skipcash.webhook_url');

        if (! $this->isHttpsUrl($baseUrl)
            || ! $this->isAllowedCallbackUrl($returnUrl)
            || ! $this->isAllowedCallbackUrl($webhookUrl)) {
            $this->addError(
                $errors,
                'SKIPCASH_URLS_INVALID',
                __('SkipCash requires an HTTPS API URL and HTTPS callbacks, except for loopback callbacks in the local sandbox environment.')
            );
        }

        $hosts = config('payments.skipcash.pay_url_hosts', []);
        if (! is_array($hosts) || $hosts === [] || collect($hosts)->contains(
            fn (mixed $host): bool => ! is_string($host) || ! $this->isHost($host)
        )) {
            $this->addError($errors, 'SKIPCASH_PAY_HOSTS_INVALID', __('Allowed SkipCash payment hosts are required.'));
        }

        if ((int) config('payments.skipcash.raw_event_retention_days') < 90) {
            $this->addError(
                $errors,
                'SKIPCASH_RETENTION_INVALID',
                __('SkipCash raw event retention cannot be shorter than 90 days.')
            );
        }
    }

    /** @param array<int, array{code: string, message: string}> $errors */
    private function addError(array &$errors, string $code, string $message): void
    {
        if (collect($errors)->contains(fn (array $error): bool => $error['code'] === $code)) {
            return;
        }

        $errors[] = ['code' => $code, 'message' => $message];
    }

    /**
     * @param  array<int, array{code: string, message: string}>  $errors
     * @return array{
     *     ready: bool,
     *     company_id: int|null,
     *     branch_id: int|null,
     *     payment_source_id: int|null,
     *     errors: array<int, array{code: string, message: string}>
     * }
     */
    private function result(
        bool $ready,
        ?int $companyId,
        ?int $branchId,
        ?int $sourceId,
        array $errors,
    ): array {
        return [
            'ready' => $ready,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'payment_source_id' => $sourceId,
            'errors' => $errors,
        ];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function isAllowedCallbackUrl(string $url): bool
    {
        if ($this->isHttpsUrl($url)) {
            return true;
        }

        if (! app()->environment('local')
            || config('payments.skipcash.environment') !== 'sandbox'
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'http'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), [
            '127.0.0.1',
            'localhost',
        ], true);
    }

    private function isHost(string $host): bool
    {
        $host = trim($host);

        return $host !== ''
            && ! str_contains($host, '/')
            && ! str_contains($host, ':')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
