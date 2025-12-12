<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ApInvoice;

class ApInvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'ap_invoice_items';
    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }
}
