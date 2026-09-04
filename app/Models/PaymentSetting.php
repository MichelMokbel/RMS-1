<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PaymentSetting extends Model
{
    public const TIMEZONE = 'Asia/Qatar';

    protected $fillable = [
        'company_id',
        'checkout_duration_minutes',
        'booking_cutoff_time',
        'timezone',
        'order_support_phone',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'checkout_duration_minutes' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (PaymentSetting $settings): void {
            if ($settings->checkout_duration_minutes < 5 || $settings->checkout_duration_minutes > 60) {
                throw ValidationException::withMessages([
                    'checkout_duration_minutes' => __('The checkout duration must be between 5 and 60 minutes.'),
                ]);
            }

            if ($settings->timezone !== self::TIMEZONE) {
                throw ValidationException::withMessages([
                    'timezone' => __('The payment timezone must remain Asia/Qatar.'),
                ]);
            }

            if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $settings->booking_cutoff_time)) {
                throw ValidationException::withMessages([
                    'booking_cutoff_time' => __('The booking cutoff must be a valid local time.'),
                ]);
            }

            $settings->order_support_phone = ($phone = trim((string) $settings->order_support_phone)) !== ''
                ? $phone
                : null;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
