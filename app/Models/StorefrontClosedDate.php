<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontClosedDate extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'service_date',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'service_date' => 'date',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
