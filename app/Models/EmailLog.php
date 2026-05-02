<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'recipient_type',
        'mailable',
        'subject',
        'mailer',
        'status',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'user_id',
        'order_id',
        'meal_plan_request_id',
        'context',
        'error_class',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'to_recipients' => 'array',
        'cc_recipients' => 'array',
        'bcc_recipients' => 'array',
        'context' => 'array',
        'sent_at' => 'datetime',
    ];
}
