<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSheetEntry extends Model
{
    protected $fillable = ['order_sheet_id', 'customer_id', 'customer_name', 'location', 'remarks', 'order_id'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(OrderSheet::class, 'order_sheet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quantities(): HasMany
    {
        return $this->hasMany(OrderSheetEntryQuantity::class);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(OrderSheetEntryExtra::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Order::class);
    }
}
