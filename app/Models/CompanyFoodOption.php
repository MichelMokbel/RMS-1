<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFoodOption extends Model
{
    protected $table = 'company_food_options';

    protected $fillable = [
        'project_id',
        'employee_list_id',
        'menu_date',
        'category',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'menu_date' => 'date',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const CATEGORIES = ['salad', 'appetizer', 'main', 'sweet', 'location', 'soup'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodProject::class, 'project_id');
    }

    public function employeeList(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodEmployeeList::class, 'employee_list_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
