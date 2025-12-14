<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealSubscriptionDay extends Model
{
    use HasFactory;

    protected $table = 'meal_subscription_days';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'weekday',
    ];

    protected $casts = [
        'weekday' => 'integer',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'subscription_id');
    }
}

