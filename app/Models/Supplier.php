<?php

namespace App\Models;

use App\Services\SupplierReferenceChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $primaryKey = 'id';

    protected $fillable = [
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'qid_cr',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInUse(): bool
    {
        return app(SupplierReferenceChecker::class)->isSupplierReferenced($this->id);
    }
}
