<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseProfile extends Model
{
    use HasFactory;

    protected $table = 'expense_profiles';
    protected $primaryKey = 'invoice_id';
    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'channel',
        'wallet_id',
        'approval_status',
        'requires_finance_approval',
        'exception_flags',
        'submitted_by',
        'submitted_at',
        'manager_approved_by',
        'manager_approved_at',
        'finance_approved_by',
        'finance_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'settled_at',
        'settlement_mode',
    ];

    protected $casts = [
        'requires_finance_approval' => 'boolean',
        'exception_flags' => 'array',
        'submitted_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'settled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
