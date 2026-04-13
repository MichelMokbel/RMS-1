<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastryOrderImage extends Model
{
    public $timestamps = false;

    protected $table = 'pastry_order_images';

    protected $fillable = [
        'pastry_order_id',
        'image_path',
        'image_disk',
        'sort_order',
        'created_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PastryOrder::class, 'pastry_order_id');
    }
}
