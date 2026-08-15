<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCompensationComponent extends Model
{
    protected $fillable = [
        'compensation_package_id', 'code', 'name', 'category', 'amount_minor',
        'is_taxable', 'is_active', 'metadata',
    ];

    protected $casts = [
        'compensation_package_id' => 'integer', 'amount_minor' => 'integer',
        'is_taxable' => 'boolean', 'is_active' => 'boolean', 'metadata' => 'array',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(HrCompensationPackage::class, 'compensation_package_id');
    }
}
