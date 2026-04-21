<?php

namespace App\Services\Marketing;

use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Crypt;

class MarketingSettingsService
{
    private const ENCRYPTED_COLUMNS = [
        'meta_app_secret',
        'meta_system_user_token',
        'google_developer_token',
        'google_client_secret',
        'google_refresh_token',
    ];

    public function get(): MarketingSetting
    {
        return MarketingSetting::first() ?? new MarketingSetting;
    }

    /**
     * Save settings. Sensitive fields are encrypted before persistence.
     * Pass null for a sensitive field to leave its current value unchanged.
     * Pass empty string to clear a sensitive field.
     */
    public function save(array $data, int $actorId): MarketingSetting
    {
        $settings = MarketingSetting::first() ?? new MarketingSetting;

        $plainFields = [
            'meta_app_id',
            'meta_business_id',
            'google_login_customer_id',
            'google_client_id',
            's3_asset_bucket',
            'meta_sync_enabled',
            'google_sync_enabled',
        ];

        foreach ($plainFields as $field) {
            if (array_key_exists($field, $data)) {
                $settings->$field = $data[$field];
            }
        }

        foreach (self::ENCRYPTED_COLUMNS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value === null) {
                // null means "do not change"
                continue;
            }

            $settings->$field = $value === '' ? null : Crypt::encryptString($value);
        }

        $settings->updated_by = $actorId;
        $settings->save();

        return $settings;
    }

    /**
     * Decrypt a single sensitive field. Returns null if not set or decryption fails.
     */
    public function decrypt(MarketingSetting $settings, string $field): ?string
    {
        $value = $settings->$field;
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMetaSystemUserToken(): ?string
    {
        return $this->decrypt($this->get(), 'meta_system_user_token');
    }

    public function getGoogleDeveloperToken(): ?string
    {
        return $this->decrypt($this->get(), 'google_developer_token');
    }

    public function getGoogleRefreshToken(): ?string
    {
        return $this->decrypt($this->get(), 'google_refresh_token');
    }

    public function getGoogleClientSecret(): ?string
    {
        return $this->decrypt($this->get(), 'google_client_secret');
    }

    public function isMetaConfigured(): bool
    {
        $settings = $this->get();

        return $settings->exists
            && ! empty($settings->meta_app_id)
            && ! empty($settings->meta_system_user_token);
    }

    public function isGoogleConfigured(): bool
    {
        $settings = $this->get();

        return $settings->exists
            && ! empty($settings->google_developer_token)
            && ! empty($settings->google_refresh_token)
            && ! empty($settings->google_client_id)
            && ! empty($settings->google_client_secret);
    }

    public function isS3Configured(): bool
    {
        $settings = $this->get();

        return $settings->exists && ! empty($settings->s3_asset_bucket);
    }

    public function isSyncEnabledFor(string $platform): bool
    {
        $settings = $this->get();

        return match ($platform) {
            'meta' => $settings->exists
                && (bool) $settings->meta_sync_enabled
                && $this->isMetaConfigured(),
            'google' => $settings->exists
                && (bool) $settings->google_sync_enabled
                && $this->isGoogleConfigured(),
            default => false,
        };
    }
}
