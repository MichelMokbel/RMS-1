<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealSubscriptionPause extends Model
{
    use HasFactory;

    protected $table = 'meal_subscription_pauses';

    protected $fillable = [
        'subscription_id',
        'pause_start',
        'pause_end',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'pause_start' => 'date',
        'pause_end' => 'date',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'subscription_id');
    }
}

