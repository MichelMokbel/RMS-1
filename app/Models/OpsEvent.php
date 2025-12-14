<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpsEvent extends Model
{
    use HasFactory;

    protected $table = 'ops_events';

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'branch_id',
        'service_date',
        'order_id',
        'order_item_id',
        'actor_user_id',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'service_date' => 'date',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];
}


