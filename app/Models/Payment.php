<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'branch_id',
        'customer_id',
        'company_id',
        'bank_account_id',
        'period_id',
        'client_uuid',
        'terminal_id',
        'pos_shift_id',
        'source',
        'method',
        'amount_cents',
        'currency',
        'received_at',
        'reference',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'bank_account_id' => 'integer',
        'period_id' => 'integer',
        'terminal_id' => 'integer',
        'pos_shift_id' => 'integer',
        'amount_cents' => 'integer',
        'received_at' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Payment $payment) {
            if ($payment->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];
            foreach (array_keys($payment->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'payment' => __('Payments are immutable after creation.'),
                    ]);
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id')
            ->whereNull('voided_at');
    }

    public function mealSubscriptions(): HasMany
    {
        return $this->hasMany(MealSubscription::class, 'source_payment_id');
    }

    public function allAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function allocatedCents(): int
    {
        return (int) $this->allocations()->sum('amount_cents');
    }

    public function unallocatedCents(): int
    {
        return (int) $this->amount_cents - $this->allocatedCents();
    }
}
