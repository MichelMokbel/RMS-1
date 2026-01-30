<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use HasFactory;

    protected $table = 'document_sequences';

    protected $fillable = [
        'branch_id',
        'type',
        'year',
        'next_number',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'next_number' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

