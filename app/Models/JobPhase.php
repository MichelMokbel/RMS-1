<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPhase extends Model
{
    use HasFactory;

    protected $table = 'accounting_job_phases';

    protected $fillable = [
        'job_id',
        'name',
        'code',
        'status',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(JobTransaction::class, 'job_phase_id');
    }
}
