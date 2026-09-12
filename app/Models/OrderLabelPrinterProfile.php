<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLabelPrinterProfile extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'terminal_id', 'code', 'name', 'department',
        'model_code', 'os_queue_name', 'connection_description', 'resolution_dpi',
        'media_mode', 'width_tenths_mm', 'height_tenths_mm',
        'min_height_tenths_mm', 'max_height_tenths_mm', 'default_copies',
        'is_verified', 'is_active', 'revision', 'verified_by', 'verified_at',
        'last_tested_by', 'last_tested_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'terminal_id' => 'integer',
        'resolution_dpi' => 'integer',
        'width_tenths_mm' => 'integer',
        'height_tenths_mm' => 'integer',
        'min_height_tenths_mm' => 'integer',
        'max_height_tenths_mm' => 'integer',
        'default_copies' => 'integer',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'revision' => 'integer',
        'verified_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function prints()
    {
        return $this->hasMany(OrderLabelPrint::class, 'printer_profile_id');
    }
}
