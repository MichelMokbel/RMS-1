<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontEvent extends Model
{
    protected $fillable = [
        'company_id',
        'event_uuid',
        'journey_hash',
        'event_name',
        'source',
        'received_at',
        'profile_id',
        'category_id',
        'channel_code',
        'path_code',
        'source_section',
        'result_count',
        'query_length',
        'quantity_bucket',
        'line_count',
        'lead_day_count',
        'experiment_variant',
        'plan_code',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'received_at' => 'datetime',
        'profile_id' => 'integer',
        'category_id' => 'integer',
        'result_count' => 'integer',
        'query_length' => 'integer',
        'line_count' => 'integer',
        'lead_day_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
