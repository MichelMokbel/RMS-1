<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ApChequeClearance extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'bank_account_id',
        'ap_payment_id',
        'clearance_date',
        'amount',
        'client_uuid',
        'reference',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'clearance_date' => 'date',
        'voided_at'      => 'datetime',
        'amount'         => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (ApChequeClearance $model) {
            $allowed = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'clearance' => __('AP cheque clearances are immutable after creation.'),
                    ]);
                }
            }
        });

        static::deleting(function (ApChequeClearance $model) {
            \Illuminate\Support\Facades\Log::error('[ApChequeClearance] Hard-delete blocked on clearance #'.$model->id.'. Void instead.');
            throw ValidationException::withMessages([
                'clearance' => __('AP cheque clearances cannot be hard-deleted. Void them instead.'),
            ]);
        });
    }

    public function apPayment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'ap_payment_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
