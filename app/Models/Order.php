<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'order_number',
        'branch_id',
        'source',
        'is_daily_dish',
        'type',
        'status',
        'customer_id',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'delivery_address_snapshot',
        'scheduled_date',
        'scheduled_time',
        'notes',
        'total_before_tax',
        'tax_amount',
        'total_amount',
        'created_by',
    ];

    protected $casts = [
        'is_daily_dish' => 'boolean',
        'scheduled_date' => 'date',
        'scheduled_time' => 'datetime:H:i:s',
        'total_before_tax' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'total_amount' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function isSubscriptionGenerated(): bool
    {
        return $this->source === 'Subscription';
    }
}

