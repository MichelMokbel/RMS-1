<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PettyCashExpense extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_expenses';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'wallet_id',
        'category_id',
        'expense_date',
        'description',
        'amount',
        'tax_amount',
        'total_amount',
        'status',
        'receipt_path',
        'submitted_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PettyCashExpense $expense) {
            $originalStatus = $expense->getOriginal('status');
            if (in_array($originalStatus, ['draft', 'submitted'], true)) {
                return;
            }

            if ($expense->getDirty() === []) {
                return;
            }

            throw ValidationException::withMessages([
                'expense' => __('Approved petty cash expenses are immutable.'),
            ]);
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recalcTotals(): void
    {
        $this->total_amount = round((float) $this->amount + (float) $this->tax_amount, 2);
        $this->save();
    }

    public function isApprovable(): bool
    {
        return in_array($this->status, ['submitted'], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }
}
