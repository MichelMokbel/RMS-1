<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealPlanRequest extends Model
{
    use HasFactory;

    protected $table = 'meal_plan_requests';

    protected $fillable = [
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'notes',
        'plan_meals',
        'status',
        'order_ids',
    ];

    protected $casts = [
        'plan_meals' => 'integer',
        'order_ids' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


