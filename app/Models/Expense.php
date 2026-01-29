<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'expenses';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'supplier_id',
        'category_id',
        'expense_date',
        'description',
        'amount',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Expense $expense) {
            $originalStatus = $expense->getOriginal('payment_status');
            if ($originalStatus === 'unpaid') {
                return;
            }

            $dirty = array_keys($expense->getDirty());
            if ($dirty === []) {
                return;
            }

            $allowed = ['payment_status'];
            foreach ($dirty as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'expense' => __('Paid or partially paid expenses are immutable.'),
                    ]);
                }
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class, 'expense_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class, 'expense_id')
            ->whereNull('voided_at');
    }

    public function allPayments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class, 'expense_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function outstandingAmount(): float
    {
        return max((float) $this->total_amount - $this->paidAmount(), 0);
    }

    public function recalcTotals(): void
    {
        $this->total_amount = round((float) $this->amount + (float) $this->tax_amount, 2);
        $this->save();
    }
}
