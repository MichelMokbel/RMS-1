<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClosingChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'period_id',
        'task_key',
        'task_name',
        'task_type',
        'is_required',
        'status',
        'completed_at',
        'completed_by',
        'notes',
        'result_payload',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'completed_at' => 'datetime',
        'result_payload' => 'array',
    ];
}
