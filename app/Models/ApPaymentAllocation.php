<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ApPayment;
use App\Models\ApInvoice;

class ApPaymentAllocation extends Model
{
    use HasFactory;

    protected $table = 'ap_payment_allocations';
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'allocated_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ApPayment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }
}
