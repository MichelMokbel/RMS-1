<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosTerminal extends Model
{
    use HasFactory;

    protected $table = 'pos_terminals';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'device_id',
        'active',
        'last_seen_at',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'active' => 'boolean',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

