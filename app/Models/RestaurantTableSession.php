<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantTableSession extends Model
{
    use HasFactory;

    protected $table = 'restaurant_table_sessions';

    protected $fillable = [
        'branch_id',
        'table_id',
        'status',
        'active',
        'opened_by',
        'device_id',
        'terminal_id',
        'pos_shift_id',
        'opened_at',
        'closed_at',
        'guests',
        'notes',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'table_id' => 'integer',
        'active' => 'boolean',
        'opened_by' => 'integer',
        'terminal_id' => 'integer',
        'pos_shift_id' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'guests' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

