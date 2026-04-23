<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ArClearingSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'bank_account_id',
        'settlement_method',
        'settlement_date',
        'amount_cents',
        'client_uuid',
        'reference',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'voided_at'       => 'datetime',
        'amount_cents'    => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (ArClearingSettlement $model) {
            $allowed = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'settlement' => __('AR clearing settlements are immutable after creation.'),
                    ]);
                }
            }
        });

        static::deleting(function (ArClearingSettlement $model) {
            Log::error('[ArClearingSettlement] Hard-delete blocked on settlement #'.$model->id.'. Void instead.');
            throw ValidationException::withMessages([
                'settlement' => __('AR clearing settlements cannot be hard-deleted. Void them instead.'),
            ]);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArClearingSettlementItem::class, 'settlement_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }
}
