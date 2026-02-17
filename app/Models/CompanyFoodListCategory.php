<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFoodListCategory extends Model
{
    protected $table = 'company_food_list_categories';

    protected $fillable = [
        'employee_list_id',
        'category',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function employeeList(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodEmployeeList::class, 'employee_list_id');
    }
}
