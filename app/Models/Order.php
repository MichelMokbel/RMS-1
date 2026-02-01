<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'order_number',
        'branch_id',
        'source',
        'is_daily_dish',
        'daily_dish_portion_type',
        'daily_dish_portion_quantity',
        'type',
        'status',
        'customer_id',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'customer_email_snapshot',
        'delivery_address_snapshot',
        'scheduled_date',
        'scheduled_time',
        'notes',
        'order_discount_amount',
        'total_before_tax',
        'tax_amount',
        'total_amount',
        'created_by',
        'invoiced_at',
    ];

    protected $casts = [
        'is_daily_dish' => 'boolean',
        'scheduled_date' => 'date',
        'scheduled_time' => 'datetime:H:i:s',
        'order_discount_amount' => 'decimal:3',
        'total_before_tax' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'total_amount' => 'decimal:3',
        'invoiced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(ArInvoice::class, 'source_order_id');
    }

    public function isSubscriptionGenerated(): bool
    {
        return $this->source === 'Subscription';
    }

    public function isInvoiced(): bool
    {
        return $this->invoiced_at !== null;
    }
}

