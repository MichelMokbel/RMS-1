<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionOrderRunError extends Model
{
    use HasFactory;

    protected $table = 'subscription_order_run_errors';

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'subscription_id',
        'message',
        'context_json',
        'created_at',
    ];

    protected $casts = [
        'context_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SubscriptionOrderRun::class, 'run_id');
    }
}


