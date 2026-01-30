<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'allocatable_type',
        'allocatable_id',
        'amount_cents',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PaymentAllocation $allocation) {
            if ($allocation->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];
            foreach (array_keys($allocation->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'allocation' => __('Payment allocations are immutable after creation.'),
                    ]);
                }
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'allocatable_type', 'allocatable_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

