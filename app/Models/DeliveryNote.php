<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class DeliveryNote extends Model
{
    protected $fillable = [
        'branch_id', 'company_id', 'customer_id', 'source_invoice_id', 'delivery_note_number',
        'status', 'delivery_date', 'customer_name_snapshot', 'delivery_address_snapshot',
        'reference', 'notes', 'created_by', 'updated_by', 'issued_at', 'issued_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'issued_at' => 'datetime',
        'branch_id' => 'integer',
        'company_id' => 'integer',
        'customer_id' => 'integer',
        'source_invoice_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (DeliveryNote $note): void {
            if ($note->getOriginal('status') === 'draft') {
                return;
            }

            $allowed = ['updated_at'];
            if (array_diff(array_keys($note->getDirty()), $allowed)) {
                throw ValidationException::withMessages(['delivery_note' => __('Issued delivery notes are immutable.')]);
            }
        });

        static::deleting(function (DeliveryNote $note): void {
            if ($note->status !== 'draft') {
                throw ValidationException::withMessages(['delivery_note' => __('Issued delivery notes cannot be deleted.')]);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'source_invoice_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(ArInvoice::class, 'source_delivery_note_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
