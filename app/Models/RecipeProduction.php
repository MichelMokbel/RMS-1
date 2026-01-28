<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeProduction extends Model
{
    use HasFactory;

    protected $table = 'recipe_productions';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'recipe_id',
        'produced_quantity',
        'production_date',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'produced_quantity' => 'decimal:3',
        'production_date' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
