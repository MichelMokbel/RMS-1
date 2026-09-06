<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipBookingFunding extends Model
{
    protected $table = 'membership_booking_funding';

    protected $fillable = [
        'purchase_block_id',
        'subscription_order_id',
        'main_quantity',
        'position_ranges',
        'invoice_id',
        'intended_invoice_issue_date',
        'invoice_gross_cents',
        'invoice_discount_cents',
        'invoice_net_cents',
        'payment_allocation_id',
        'state',
        'reserved_at',
        'invoiced_at',
        'released_at',
        'booking_cutoff_time',
        'booking_timezone',
        'change_deadline_at',
        'created_by',
        'released_by',
    ];

    protected $casts = [
        'purchase_block_id' => 'integer',
        'subscription_order_id' => 'integer',
        'main_quantity' => 'integer',
        'position_ranges' => 'array',
        'invoice_id' => 'integer',
        'intended_invoice_issue_date' => 'date',
        'invoice_gross_cents' => 'integer',
        'invoice_discount_cents' => 'integer',
        'invoice_net_cents' => 'integer',
        'payment_allocation_id' => 'integer',
        'reserved_at' => 'datetime',
        'invoiced_at' => 'datetime',
        'released_at' => 'datetime',
        'change_deadline_at' => 'datetime',
        'created_by' => 'integer',
        'released_by' => 'integer',
    ];

    public function purchaseBlock(): BelongsTo
    {
        return $this->belongsTo(MembershipPurchaseBlock::class, 'purchase_block_id');
    }

    public function subscriptionOrder(): BelongsTo
    {
        return $this->belongsTo(MealSubscriptionOrder::class, 'subscription_order_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'invoice_id');
    }

    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }
}
