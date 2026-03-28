<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class MealPlanRequest extends Model
{
    use HasFactory;

    protected $table = 'meal_plan_requests';

    protected $fillable = [
        'customer_id',
        'user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'notes',
        'plan_meals',
        'status',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'user_id' => 'integer',
        'plan_meals' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'meal_plan_request_orders', 'meal_plan_request_id', 'order_id');
    }

    public function linkedOrderIds(): array
    {
        if (! Schema::hasTable('meal_plan_request_orders')) {
            return [];
        }

        return $this->orders()->pluck('orders.id')->all();
    }
}
