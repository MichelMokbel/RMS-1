<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionOrderRun extends Model
{
    use HasFactory;

    protected $table = 'subscription_order_runs';

    protected $fillable = [
        'service_date',
        'branch_id',
        'started_at',
        'finished_at',
        'status',
        'created_count',
        'skipped_existing_count',
        'skipped_no_menu_count',
        'skipped_no_items_count',
        'error_summary',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_count' => 'integer',
        'skipped_existing_count' => 'integer',
        'skipped_no_menu_count' => 'integer',
        'skipped_no_items_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(SubscriptionOrderRunError::class, 'run_id');
    }
}


