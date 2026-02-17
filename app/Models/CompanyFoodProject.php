<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyFoodProject extends Model
{
    protected static function booted(): void
    {
        static::created(function (CompanyFoodProject $project): void {
            if ($project->employeeLists()->exists()) {
                return;
            }
            $list = CompanyFoodEmployeeList::create([
                'project_id' => $project->id,
                'name' => 'List 1',
                'sort_order' => 0,
            ]);
            foreach (['salad', 'appetizer', 'main', 'sweet', 'location'] as $i => $category) {
                CompanyFoodListCategory::create([
                    'employee_list_id' => $list->id,
                    'category' => $category,
                    'sort_order' => $i,
                ]);
            }
        });
    }
    protected $table = 'company_food_projects';

    protected $fillable = [
        'name',
        'company_name',
        'start_date',
        'end_date',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(CompanyFoodOption::class, 'project_id')->orderBy('category')->orderBy('sort_order');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CompanyFoodOrder::class, 'project_id');
    }

    public function activeOptions(): HasMany
    {
        return $this->hasMany(CompanyFoodOption::class, 'project_id')->where('is_active', true)->orderBy('category')->orderBy('sort_order');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(CompanyFoodEmployee::class, 'project_id')->orderBy('sort_order')->orderBy('employee_name');
    }

    public function employeeLists(): HasMany
    {
        return $this->hasMany(CompanyFoodEmployeeList::class, 'project_id')->orderBy('sort_order');
    }
}
