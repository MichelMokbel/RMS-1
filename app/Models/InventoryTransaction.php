<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $table = 'inventory_transactions';

    protected $fillable = [
        'item_id',
        'branch_id',
        'transaction_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'transaction_date',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'transaction_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function user()
    {
        return class_exists(User::class) ? $this->belongsTo(User::class, 'user_id') : null;
    }

    public function delta(): float
    {
        $qty = (float) $this->quantity;

        return match ($this->transaction_type) {
            'in' => abs($qty),
            'out' => -abs($qty),
            'adjustment' => $qty,
            default => $qty,
        };
    }
}
