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
        'transaction_type',
        'quantity',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'transaction_date',
    ];

    protected $casts = [
        'quantity' => 'integer',
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

    public function delta(): int
    {
        $qty = (int) $this->quantity;

        return match ($this->transaction_type) {
            'in' => abs($qty),
            'out' => -abs($qty),
            'adjustment' => $qty,
            default => $qty,
        };
    }
}
