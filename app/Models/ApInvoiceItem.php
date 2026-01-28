<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ApInvoice;
use Illuminate\Validation\ValidationException;

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

    protected static function booted(): void
    {
        $guard = function (ApInvoiceItem $item): void {
            $invoice = $item->invoice()->first();
            if ($invoice && $invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => __('Cannot modify items on a posted invoice.'),
                ]);
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }
}
