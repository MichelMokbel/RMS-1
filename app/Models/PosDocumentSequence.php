<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosDocumentSequence extends Model
{
    use HasFactory;

    protected $table = 'pos_document_sequences';

    protected $fillable = [
        'terminal_id',
        'business_date',
        'last_number',
    ];

    protected $casts = [
        'terminal_id' => 'integer',
        'business_date' => 'date',
        'last_number' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

