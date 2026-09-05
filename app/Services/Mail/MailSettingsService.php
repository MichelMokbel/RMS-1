<?php

namespace App\Services\Mail;

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class MailSettingsService
{
    private const MAX_RECIPIENTS = 20;

    /** @var array<string, mixed> */
    private array $installation;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AccountingContextService $accountingContext,
    ) {
        $this->installation = [
            'default_mailer' => (string) $config->get('mail.default', 'log'),
            'smtp' => (array) $config->get('mail.mailers.smtp', []),
            'smtp_host' => trim((string) $config->get('mail.mailers.smtp.host', '127.0.0.1')),
            'smtp_port' => (int) $config->get('mail.mailers.smtp.port', 2525),
            'security_mode' => $this->installationSecurityMode(),
            'smtp_username' => $this->nullableString($config->get('mail.mailers.smtp.username')),
            'smtp_password' => $this->nullableRawString($config->get('mail.mailers.smtp.password')),
            'from_address' => trim((string) $config->get('mail.from.address', '')),
            'from_name' => trim((string) $config->get('mail.from.name', '')),
            'daily_dish_admin_emails' => $this->normalizeRecipients(
                $config->get('mail.daily_dish_admin_emails', []),
                validate: false,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $setting = $this->recordOrNull();

        if (! $setting) {
            return $this->safeState($this->installation + [
                'source' => 'environment',
                'revision' => 0,
                'updated_at' => null,
            ]);
        }

        try {
            $effective = $this->effectiveFromRecord($setting);

            return $this->safeState($effective + [
                'source' => 'database',
                'revision' => (int) $setting->revision,
                'updated_at' => $setting->updated_at?->toISOString(),
            ]);
        } catch (MailConfigurationUnavailableException) {
            return [
                'source' => 'database',
                'smtp_host' => (string) $setting->smtp_host,
                'smtp_port' => (int) $setting->smtp_port,
                'security_mode' => (string) $setting->security_mode,
                'from_address' => (string) $setting->from_address,
                'from_name' => (string) $setting->from_name,
                'username_configured' => filled($setting->smtp_username),
                'password_configured' => filled($setting->smtp_password),
                'authentication_configured' => filled($setting->smtp_username) && filled($setting->smtp_password),
                'recipient_count' => null,
                'revision' => (int) $setting->revision,
                'updated_at' => $setting->updated_at?->toISOString(),
                'needs_repair' => true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{state: array<string, mixed>, audit_id: int}
     */
    public function save(array $data, User $actor, int $expectedRevision): array
    {
        $companyId = $this->assertAdministrator($actor);
        $this->assertAuditAvailable();
        $public = $this->validatePublicInput($data);

        try {
            return DB::transaction(function () use ($data, $actor, $companyId, $expectedRevision, $public): array {
                $setting = MailSetting::query()->lockForUpdate()->find(MailSetting::SINGLETON_ID);
                $this->assertExpectedRevision($setting, $expectedRevision);

                $effective = $this->effectiveForSave($setting, $public, $data, false);
                $this->validateEffective($effective);
                $revision = $setting ? (int) $setting->revision + 1 : 1;
                $changedFields = $this->changedFields($setting, $public, $data, false);

                $setting ??= new MailSetting(['id' => MailSetting::SINGLETON_ID]);
                $setting->fill([
                    'smtp_host' => $effective['smtp_host'],
                    'smtp_port' => $effective['smtp_port'],
                    'security_mode' => $effective['security_mode'],
                    'smtp_username' => $this->encryptNullable($effective['smtp_username']),
                    'smtp_password' => $this->encryptNullable($effective['smtp_password']),
                    'from_address' => $effective['from_address'],
                    'from_name' => $effective['from_name'],
                    'daily_dish_admin_emails' => Crypt::encryptString(json_encode(
                        $effective['daily_dish_admin_emails'],
                        JSON_THROW_ON_ERROR,
                    )),
                    'revision' => $revision,
                    'updated_by' => (int) $actor->id,
                ])->save();

                $audit = $this->writeAudit(
                    action: 'mail_settings.updated',
                    setting: $setting,
                    actor: $actor,
                    companyId: $companyId,
                    changedFields: $changedFields,
                    effective: $effective,
                );

                return [
                    'state' => $this->stateFromEffective($setting, $effective),
                    'audit_id' => (int) $audit->id,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if ($expectedRevision === 0 && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new MailSettingsConflictException($this->state());
            }

            throw $exception;
        }
    }

    /** @return array{state: array<string, mixed>, audit_id: int} */
    public function clearAuthentication(User $actor, int $expectedRevision, bool $confirmed): array
    {
        if (! $confirmed) {
            throw ValidationException::withMessages([
                'clear_authentication' => __('Confirm that you want to clear SMTP authentication.'),
            ]);
        }

        $companyId = $this->assertAdministrator($actor);
        $this->assertAuditAvailable();

        try {
            return DB::transaction(function () use ($actor, $companyId, $expectedRevision): array {
                $setting = MailSetting::query()->lockForUpdate()->find(MailSetting::SINGLETON_ID);
                $this->assertExpectedRevision($setting, $expectedRevision);

                $public = $setting ? [
                    'smtp_host' => (string) $setting->smtp_host,
                    'smtp_port' => (int) $setting->smtp_port,
                    'security_mode' => (string) $setting->security_mode,
                    'from_address' => (string) $setting->from_address,
                    'from_name' => (string) $setting->from_name,
                ] : $this->installation;
                $effective = $this->effectiveForSave($setting, $public, [], true);
                $this->validateEffective($effective);
                $revision = $setting ? (int) $setting->revision + 1 : 1;

                $setting ??= new MailSetting(['id' => MailSetting::SINGLETON_ID]);
                $setting->fill([
                    'smtp_host' => $effective['smtp_host'],
                    'smtp_port' => $effective['smtp_port'],
                    'security_mode' => $effective['security_mode'],
                    'smtp_username' => null,
                    'smtp_password' => null,
                    'from_address' => $effective['from_address'],
                    'from_name' => $effective['from_name'],
                    'daily_dish_admin_emails' => Crypt::encryptString(json_encode(
                        $effective['daily_dish_admin_emails'],
                        JSON_THROW_ON_ERROR,
                    )),
                    'revision' => $revision,
                    'updated_by' => (int) $actor->id,
                ])->save();

                $audit = $this->writeAudit(
                    action: 'mail_settings.authentication_cleared',
                    setting: $setting,
                    actor: $actor,
                    companyId: $companyId,
                    changedFields: ['smtp_username', 'smtp_password'],
                    effective: $effective,
                );

                return [
                    'state' => $this->stateFromEffective($setting, $effective),
                    'audit_id' => (int) $audit->id,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if ($expectedRevision === 0 && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new MailSettingsConflictException($this->state());
            }

            throw $exception;
        }
    }

    /** @return array<int, string> */
    public function adminRecipientsForCompany(?int $companyId): array
    {
        $defaultCompanyId = $this->accountingContext->defaultCompanyId();
        if (! $companyId || ! $defaultCompanyId || $companyId !== $defaultCompanyId) {
            return [];
        }

        $setting = $this->recordOrNull();
        if (! $setting) {
            return $this->installation['daily_dish_admin_emails'];
        }

        return $this->decryptRecipients($setting->daily_dish_admin_emails);
    }

    /** @return array<string, mixed> */
    public function prepareForDelivery(): array
    {
        $setting = $this->recordOrNull();
        $previousDefault = (string) $this->config->get('mail.default', 'log');

        if (! $setting) {
            $effective = $this->installation;
            $this->config->set('mail.default', $effective['default_mailer']);
            $this->config->set('mail.mailers.smtp', $effective['smtp']);
            $this->config->set('mail.from.address', $effective['from_address']);
            $this->config->set('mail.from.name', $effective['from_name']);
            $this->config->set('mail.daily_dish_admin_emails', $effective['daily_dish_admin_emails']);
            $state = $this->safeState($effective + [
                'source' => 'environment',
                'revision' => 0,
                'updated_at' => null,
            ]);
        } else {
            $effective = $this->effectiveFromRecord($setting);
            $this->config->set('mail.default', 'smtp');
            $this->config->set('mail.mailers.smtp', $this->smtpRuntimeConfig($effective));
            $this->config->set('mail.from.address', $effective['from_address']);
            $this->config->set('mail.from.name', $effective['from_name']);
            $this->config->set('mail.daily_dish_admin_emails', $effective['daily_dish_admin_emails']);
            $state = $this->stateFromEffective($setting, $effective);
        }

        if (Mail::getFacadeRoot() instanceof MailManager) {
            Mail::purge($previousDefault);
            if ($previousDefault !== 'smtp') {
                Mail::purge('smtp');
            }
            Mail::forgetMailers();
        }

        return $state;
    }

    private function assertAdministrator(User $actor): int
    {
        if ($actor->status !== 'active' || ! $actor->hasRole('admin') || $actor->isCustomerPortalUser()) {
            abort(403);
        }

        $companyId = $this->accountingContext->defaultCompanyId();
        $company = $companyId ? AccountingCompany::query()->find($companyId) : null;
        if (! $company || ! $company->is_active) {
            throw ValidationException::withMessages([
                'mail_settings' => __('An active default accounting company is required.'),
            ]);
        }

        return (int) $company->id;
    }

    private function assertAuditAvailable(): void
    {
        try {
            if (Schema::hasTable('accounting_audit_logs')) {
                return;
            }
        } catch (Throwable) {
            // Use the same safe error for a missing table and an unavailable audit store.
        }

        throw ValidationException::withMessages([
            'mail_settings' => __('Mail settings cannot be changed while audit storage is unavailable.'),
        ]);
    }

    private function assertExpectedRevision(?MailSetting $setting, int $expectedRevision): void
    {
        $currentRevision = $setting ? (int) $setting->revision : 0;
        if ($expectedRevision < 0 || $expectedRevision !== $currentRevision) {
            throw new MailSettingsConflictException($this->state());
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validatePublicInput(array $data): array
    {
        return Validator::make($data, [
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'security_mode' => ['required', 'string', 'in:'.implode(',', MailSetting::SECURITY_MODES)],
            'from_address' => ['required', 'string', 'email:rfc', 'max:254'],
            'from_name' => ['required', 'string', 'max:255'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:4096'],
            'daily_dish_admin_emails' => ['nullable', 'string', 'max:8192'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function effectiveForSave(?MailSetting $setting, array $public, array $input, bool $clearAuthentication): array
    {
        $usernameReplacement = trim((string) ($input['smtp_username'] ?? ''));
        $passwordReplacement = (string) ($input['smtp_password'] ?? '');
        $recipientReplacement = trim((string) ($input['daily_dish_admin_emails'] ?? ''));

        if (! $clearAuthentication && $usernameReplacement !== '' && $passwordReplacement === '') {
            throw ValidationException::withMessages([
                'smtp_password' => __('Enter a new password when replacing the SMTP username.'),
            ]);
        }

        $base = $setting ? [
            'smtp_host' => (string) $setting->smtp_host,
            'smtp_port' => (int) $setting->smtp_port,
            'security_mode' => (string) $setting->security_mode,
            'from_address' => (string) $setting->from_address,
            'from_name' => (string) $setting->from_name,
        ] : $this->installation;

        $username = $clearAuthentication
            ? null
            : ($usernameReplacement !== ''
                ? $usernameReplacement
                : ($setting ? $this->decryptNullable($setting->smtp_username) : $this->installation['smtp_username']));
        $password = $clearAuthentication
            ? null
            : ($passwordReplacement !== ''
                ? $passwordReplacement
                : ($setting ? $this->decryptNullable($setting->smtp_password) : $this->installation['smtp_password']));
        $recipients = $recipientReplacement !== ''
            ? $this->normalizeRecipients($recipientReplacement)
            : ($setting
                ? $this->decryptRecipients($setting->daily_dish_admin_emails)
                : $this->installation['daily_dish_admin_emails']);

        return [
            'smtp_host' => trim((string) ($public['smtp_host'] ?? $base['smtp_host'])),
            'smtp_port' => (int) ($public['smtp_port'] ?? $base['smtp_port']),
            'security_mode' => (string) ($public['security_mode'] ?? $base['security_mode']),
            'smtp_username' => $username,
            'smtp_password' => $password,
            'from_address' => mb_strtolower(trim((string) ($public['from_address'] ?? $base['from_address']))),
            'from_name' => trim((string) ($public['from_name'] ?? $base['from_name'])),
            'daily_dish_admin_emails' => $recipients,
        ];
    }

    /** @param array<string, mixed> $effective */
    private function validateEffective(array $effective): void
    {
        $host = (string) $effective['smtp_host'];
        if (! $this->validHost($host)) {
            throw ValidationException::withMessages([
                'smtp_host' => __('Enter a valid SMTP host without a scheme, path, credentials, spaces, or brackets.'),
            ]);
        }

        $usernameConfigured = filled($effective['smtp_username']);
        $passwordConfigured = filled($effective['smtp_password']);
        if ($usernameConfigured !== $passwordConfigured) {
            throw ValidationException::withMessages([
                'smtp_username' => __('SMTP username and password must both be configured or both be cleared.'),
            ]);
        }

        if ($effective['security_mode'] === 'none' && ($usernameConfigured || ! app()->environment(['local', 'testing']))) {
            throw ValidationException::withMessages([
                'security_mode' => __('Unencrypted SMTP is available only without authentication in local or testing environments.'),
            ]);
        }

        $recipients = (array) $effective['daily_dish_admin_emails'];
        if ($recipients === [] || count($recipients) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'daily_dish_admin_emails' => __('Enter between 1 and 20 administrator email addresses.'),
            ]);
        }

        Validator::make(['emails' => $recipients], [
            'emails' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'emails.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
        ])->validate();
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 255 || preg_match('/[\s\x00-\x1F\x7F\/\\@\[\]]/', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
    }

    private function recordOrNull(): ?MailSetting
    {
        try {
            if (! Schema::hasTable('mail_settings')) {
                return null;
            }

            return MailSetting::query()->find(MailSetting::SINGLETON_ID);
        } catch (MailConfigurationUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MailConfigurationUnavailableException;
        }
    }

    /** @return array<string, mixed> */
    private function effectiveFromRecord(MailSetting $setting): array
    {
        $effective = [
            'smtp_host' => (string) $setting->smtp_host,
            'smtp_port' => (int) $setting->smtp_port,
            'security_mode' => (string) $setting->security_mode,
            'smtp_username' => $this->decryptNullable($setting->smtp_username),
            'smtp_password' => $this->decryptNullable($setting->smtp_password),
            'from_address' => (string) $setting->from_address,
            'from_name' => (string) $setting->from_name,
            'daily_dish_admin_emails' => $this->decryptRecipients($setting->daily_dish_admin_emails),
        ];

        try {
            $this->validateEffective($effective);
        } catch (ValidationException) {
            throw new MailConfigurationUnavailableException('MAIL_SETTINGS_INVALID');
        }

        return $effective;
    }

    private function decryptNullable(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            throw new MailConfigurationUnavailableException('MAIL_SETTINGS_DECRYPTION_FAILED');
        }
    }

    /** @return array<int, string> */
    private function decryptRecipients(string $value): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MailConfigurationUnavailableException('MAIL_SETTINGS_DECRYPTION_FAILED');
        }

        if (! is_array($decoded)) {
            throw new MailConfigurationUnavailableException('MAIL_SETTINGS_INVALID');
        }

        return $this->normalizeRecipients($decoded);
    }

    /** @return array<int, string> */
    private function normalizeRecipients(mixed $value, bool $validate = true): array
    {
        $values = is_array($value)
            ? $value
            : (preg_split('/[,;\r\n]+/', (string) $value) ?: []);
        $recipients = collect($values)
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($validate) {
            Validator::make(['emails' => $recipients], [
                'emails' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
                'emails.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            ])->validate();
        }

        return $validate
            ? $recipients
            : array_values(array_filter($recipients, fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @param array<string, mixed> $effective @return array<string, mixed> */
    private function safeState(array $effective): array
    {
        $usernameConfigured = filled($effective['smtp_username'] ?? null);
        $passwordConfigured = filled($effective['smtp_password'] ?? null);

        return [
            'source' => (string) $effective['source'],
            'smtp_host' => (string) $effective['smtp_host'],
            'smtp_port' => (int) $effective['smtp_port'],
            'security_mode' => (string) $effective['security_mode'],
            'from_address' => (string) $effective['from_address'],
            'from_name' => (string) $effective['from_name'],
            'username_configured' => $usernameConfigured,
            'password_configured' => $passwordConfigured,
            'authentication_configured' => $usernameConfigured && $passwordConfigured,
            'recipient_count' => count((array) ($effective['daily_dish_admin_emails'] ?? [])),
            'revision' => (int) $effective['revision'],
            'updated_at' => $effective['updated_at'] ?? null,
            'needs_repair' => false,
        ];
    }

    /** @param array<string, mixed> $effective @return array<string, mixed> */
    private function stateFromEffective(MailSetting $setting, array $effective): array
    {
        return $this->safeState($effective + [
            'source' => 'database',
            'revision' => (int) $setting->revision,
            'updated_at' => $setting->updated_at?->toISOString(),
        ]);
    }

    /** @param array<string, mixed> $effective @return array<string, mixed> */
    private function smtpRuntimeConfig(array $effective): array
    {
        $mode = (string) $effective['security_mode'];
        $baseline = (array) $this->installation['smtp'];

        return array_merge($baseline, [
            'transport' => 'smtp',
            'scheme' => $mode === 'implicit_tls' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => $effective['smtp_host'],
            'port' => $effective['smtp_port'],
            'username' => $effective['smtp_username'],
            'password' => $effective['smtp_password'],
            'auto_tls' => $mode !== 'none',
            'require_tls' => $mode !== 'none',
            'verify_peer' => true,
        ]);
    }

    private function installationSecurityMode(): string
    {
        $scheme = mb_strtolower(trim((string) $this->config->get('mail.mailers.smtp.scheme', '')));
        $port = (int) $this->config->get('mail.mailers.smtp.port', 0);
        $username = $this->config->get('mail.mailers.smtp.username');

        if (in_array($scheme, ['smtps', 'ssl'], true) || $port === 465) {
            return 'implicit_tls';
        }

        if (in_array($scheme, ['smtp', 'tls'], true)
            || (bool) $this->config->get('mail.mailers.smtp.require_tls', false)
            || filled($username)
            || app()->environment('production')) {
            return 'starttls';
        }

        return 'none';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function nullableRawString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');

        return $value !== '' ? $value : null;
    }

    private function encryptNullable(?string $value): ?string
    {
        return filled($value) ? Crypt::encryptString($value) : null;
    }

    /**
     * @param  array<string, mixed>  $public
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    private function changedFields(?MailSetting $setting, array $public, array $input, bool $clearAuthentication): array
    {
        if (! $setting) {
            return [
                'smtp_host',
                'smtp_port',
                'security_mode',
                'smtp_username',
                'smtp_password',
                'from_address',
                'from_name',
                'daily_dish_admin_emails',
            ];
        }

        $changed = [];
        foreach (['smtp_host', 'smtp_port', 'security_mode', 'from_address', 'from_name'] as $field) {
            if ((string) $setting->{$field} !== (string) ($public[$field] ?? '')) {
                $changed[] = $field;
            }
        }
        foreach (['smtp_username', 'smtp_password', 'daily_dish_admin_emails'] as $field) {
            if ($clearAuthentication && in_array($field, ['smtp_username', 'smtp_password'], true)) {
                $changed[] = $field;
            } elseif (filled($input[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * @param  array<int, string>  $changedFields
     * @param  array<string, mixed>  $effective
     */
    private function writeAudit(
        string $action,
        MailSetting $setting,
        User $actor,
        int $companyId,
        array $changedFields,
        array $effective,
    ): AccountingAuditLog {
        try {
            return AccountingAuditLog::query()->create([
                'company_id' => $companyId,
                'actor_id' => (int) $actor->id,
                'action' => $action,
                'subject_type' => MailSetting::class,
                'subject_id' => MailSetting::SINGLETON_ID,
                'payload' => [
                    'revision' => (int) $setting->revision,
                    'changed_fields' => $changedFields,
                    'security_mode' => (string) $effective['security_mode'],
                    'smtp_port' => (int) $effective['smtp_port'],
                    'username_configured' => filled($effective['smtp_username']),
                    'password_configured' => filled($effective['smtp_password']),
                    'recipient_count' => count((array) $effective['daily_dish_admin_emails']),
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'mail_settings' => __('Mail settings cannot be changed while audit storage is unavailable.'),
            ]);
        }
    }
}
