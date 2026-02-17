<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyFoodEmployeeList extends Model
{
    protected $table = 'company_food_employee_lists';

    protected $fillable = [
        'project_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodProject::class, 'project_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(CompanyFoodEmployee::class, 'employee_list_id')->orderBy('sort_order')->orderBy('employee_name');
    }

    public function listCategories(): HasMany
    {
        return $this->hasMany(CompanyFoodListCategory::class, 'employee_list_id')->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CompanyFoodOrder::class, 'employee_list_id');
    }

    /**
     * @return array<string>
     */
    public function getCategorySlugs(): array
    {
        return $this->listCategories()->pluck('category')->values()->all();
    }
}
