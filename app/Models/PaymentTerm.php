<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    use HasFactory;

    protected $table = 'payment_terms';

    protected $fillable = [
        'name',
        'days',
        'is_credit',
        'is_active',
    ];

    protected $casts = [
        'days' => 'integer',
        'is_credit' => 'boolean',
        'is_active' => 'boolean',
    ];
}
