<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApDailyJournalReport extends Model
{
    protected $fillable = ['company_id', 'report_date', 'document_number', 'snapshot', 'entry_count', 'revision', 'generated_at', 'email_status', 'recipient', 'sent_at', 'emailed_revision'];

    protected $casts = [
        'company_id' => 'integer',
        'report_date' => 'date',
        'snapshot' => 'array',
        'entry_count' => 'integer',
        'revision' => 'integer',
        'generated_at' => 'datetime',
        'sent_at' => 'datetime',
        'emailed_revision' => 'integer',
    ];
}
