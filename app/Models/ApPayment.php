<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Supplier;
use App\Models\User;

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
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
