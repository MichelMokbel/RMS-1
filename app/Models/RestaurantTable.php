<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    use HasFactory;

    protected $table = 'restaurant_tables';

    protected $fillable = [
        'branch_id',
        'area_id',
        'code',
        'name',
        'capacity',
        'display_order',
        'active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'area_id' => 'integer',
        'capacity' => 'integer',
        'display_order' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

