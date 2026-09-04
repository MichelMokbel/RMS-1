<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderEvent extends Model
{
    protected $fillable = [
        'payment_source_id',
        'provider_transaction_id',
        'provider_payment_id',
        'payload_hash',
        'merchant_transaction_id',
        'amount_cents',
        'raw_status',
        'normalized_status',
        'normalized_snapshot',
        'signature_key_reference',
        'processing_state',
        'error_code',
        'received_at',
        'processing_started_at',
        'processed_at',
        'next_retry_at',
        'raw_body',
        'raw_body_removed_at',
    ];

    protected $casts = [
        'payment_source_id' => 'integer',
        'provider_transaction_id' => 'integer',
        'amount_cents' => 'integer',
        'normalized_snapshot' => 'encrypted:array',
        'received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'raw_body' => 'encrypted',
        'raw_body_removed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function providerTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderTransaction::class, 'provider_transaction_id');
    }
}
