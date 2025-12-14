<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function isInUse(): bool
    {
        if (Schema::hasTable('expenses') && $this->expenses()->exists()) {
            return true;
        }

        if (Schema::hasTable('petty_cash_expenses')) {
            $exists = DB::table('petty_cash_expenses')->where('category_id', $this->id)->exists();
            if ($exists) {
                return true;
            }
        }

        if (Schema::hasTable('ap_invoices')) {
            $exists = DB::table('ap_invoices')
                ->where('is_expense', 1)
                ->where('category_id', $this->id)
                ->exists();
            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
