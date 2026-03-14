<?php

namespace App\Models;

use App\Services\SupplierReferenceChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $primaryKey = 'id';

    protected $fillable = [
        'company_id',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'qid_cr',
        'status',
        'payment_term_id',
        'default_expense_account_id',
        'preferred_payment_method',
        'hold_status',
        'requires_1099',
        'approval_threshold',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'payment_term_id' => 'integer',
        'default_expense_account_id' => 'integer',
        'requires_1099' => 'boolean',
        'approval_threshold' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInUse(): bool
    {
        return app(SupplierReferenceChecker::class)->isSupplierReferenced($this->id);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'default_expense_account_id');
    }
}
