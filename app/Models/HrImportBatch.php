<?php

namespace App\Models;

use App\Enums\HR\ImportStatus;
use App\Enums\HR\ImportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrImportBatch extends Model
{
    protected $attributes = ['status' => 'uploaded'];

    protected $fillable = [
        'company_id', 'type', 'status', 'source_name', 'storage_disk', 'object_key',
        'archive_object_key', 'sha256', 'options', 'stats', 'initiated_by', 'initiated_at',
        'committed_by', 'committed_at', 'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'company_id' => 'integer', 'type' => ImportType::class, 'status' => ImportStatus::class,
        'options' => 'array', 'stats' => 'array', 'initiated_by' => 'integer', 'initiated_at' => 'datetime',
        'committed_by' => 'integer', 'committed_at' => 'datetime', 'failed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HrImportRow::class, 'import_batch_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}
