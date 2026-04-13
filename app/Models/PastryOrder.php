<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PastryOrder extends Model
{
    use HasFactory;

    protected $table = 'pastry_orders';

    protected $fillable = [
        'order_number',
        'branch_id',
        'status',
        'type',
        'customer_id',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'delivery_address_snapshot',
        'scheduled_date',
        'scheduled_time',
        'notes',
        'order_discount_amount',
        'total_before_tax',
        'tax_amount',
        'total_amount',
        'invoiced_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date'         => 'date',
        'invoiced_at'            => 'datetime',
        'order_discount_amount'  => 'decimal:3',
        'total_before_tax'       => 'decimal:3',
        'tax_amount'             => 'decimal:3',
        'total_amount'           => 'decimal:3',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PastryOrderItem::class, 'pastry_order_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PastryOrderImage::class, 'pastry_order_id')->orderBy('sort_order');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(ArInvoice::class, 'source_pastry_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInvoiced(): bool
    {
        return $this->invoiced_at !== null;
    }
}
