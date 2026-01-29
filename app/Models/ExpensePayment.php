<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class ExpensePayment extends Model
{
    use HasFactory;

    protected $table = 'expense_payments';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'expense_id',
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
        static::updating(function (ExpensePayment $payment) {
            if ($payment->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by'];
            foreach (array_keys($payment->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'payment' => __('Expense payments are immutable after posting.'),
                    ]);
                }
            }
        });
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function creator(): BelongsTo
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
}
