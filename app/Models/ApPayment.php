<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApPayment extends Model
{
    use HasFactory;

    protected $table = 'ap_payments';
    public $timestamps = false;

    protected $fillable = [
        'supplier_id',
        'payment_date',
        'amount',
        'payment_method',
        'reference',
        'notes',
        'created_by',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ApPayment $payment) {
            if ($payment->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by'];
            foreach (array_keys($payment->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'payment' => __('AP payments are immutable after posting.'),
                    ]);
                }
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'payment_id')
            ->whereNull('voided_at');
    }

    public function allAllocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function allocatedAmount(): float
    {
        return (float) $this->allocations()->sum('allocated_amount');
    }

    public function unallocatedAmount(): float
    {
        return (float) $this->amount - $this->allocatedAmount();
    }
}
