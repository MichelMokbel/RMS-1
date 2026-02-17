<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CompanyFoodOrder extends Model
{
    protected $table = 'company_food_orders';

    protected $fillable = [
        'project_id',
        'employee_list_id',
        'order_date',
        'employee_name',
        'email',
        'edit_token',
        'salad_option_id',
        'appetizer_option_id_1',
        'appetizer_option_id_2',
        'main_option_id',
        'sweet_option_id',
        'location_option_id',
        'soup_option_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CompanyFoodOrder $order): void {
            if (empty($order->edit_token)) {
                $order->edit_token = (string) Str::uuid();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodProject::class, 'project_id');
    }

    public function employeeList(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodEmployeeList::class, 'employee_list_id');
    }

    public function saladOption(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'salad_option_id');
    }

    public function appetizerOption1(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'appetizer_option_id_1');
    }

    public function appetizerOption2(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'appetizer_option_id_2');
    }

    public function mainOption(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'main_option_id');
    }

    public function sweetOption(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'sweet_option_id');
    }

    public function locationOption(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'location_option_id');
    }

    public function soupOption(): BelongsTo
    {
        return $this->belongsTo(CompanyFoodOption::class, 'soup_option_id');
    }
}
