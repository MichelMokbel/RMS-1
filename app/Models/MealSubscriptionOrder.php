<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealSubscriptionOrder extends Model
{
    use HasFactory;

    protected $table = 'meal_subscription_orders';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'order_id',
        'service_date',
        'branch_id',
    ];

    protected $casts = [
        'service_date' => 'date',
        'created_at' => 'datetime',
    ];
}

