<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosSyncEvent extends Model
{
    use HasFactory;

    protected $table = 'pos_sync_events';

    protected $fillable = [
        'terminal_id',
        'event_id',
        'client_uuid',
        'type',
        'server_entity_type',
        'server_entity_id',
        'status',
        'applied_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'terminal_id' => 'integer',
        'server_entity_id' => 'integer',
        'applied_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

