<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFoodEmployee extends Model
{
    protected $table = 'company_food_employees';

    protected $fillable = [
        'project_id',
        'employee_list_id',
        'employee_name',
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

    public function employeeList(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodEmployeeList::class, 'employee_list_id');
    }
}
