<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ApPayment;
use App\Models\ApInvoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApPaymentAllocation extends Model
{
    use HasFactory;

    protected $table = 'ap_payment_allocations';
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'allocated_amount',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ApPaymentAllocation $allocation) {
            if ($allocation->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by'];
            foreach (array_keys($allocation->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'allocation' => __('AP payment allocations are immutable after posting.'),
                    ]);
                }
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
