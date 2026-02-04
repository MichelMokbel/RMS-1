<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantArea extends Model
{
    use HasFactory;

    protected $table = 'restaurant_areas';

    protected $fillable = [
        'branch_id',
        'name',
        'display_order',
        'active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'display_order' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

