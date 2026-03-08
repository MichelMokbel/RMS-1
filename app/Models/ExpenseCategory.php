<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $table = 'expense_categories';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = ['name', 'description', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(ApInvoice::class, 'category_id')
            ->where('is_expense', true);
    }

    public function isInUse(): bool
    {
        return Schema::hasTable('ap_invoices') && $this->expenses()->exists();
    }
}
