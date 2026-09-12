<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLabelPrint extends Model
{
    public const STATUS_PREPARING = 'preparing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'company_id', 'branch_id', 'printer_profile_id', 'source_type',
        'source_id', 'service_date', 'snapshot', 'snapshot_hash', 'copy_count',
        'sequence', 'reprint_of_id', 'reprint_reason', 'requested_by',
        'pos_print_job_id', 'status', 'queued_at', 'printed_at', 'failed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'printer_profile_id' => 'integer',
        'source_id' => 'integer',
        'service_date' => 'date',
        'snapshot' => 'array',
        'copy_count' => 'integer',
        'sequence' => 'integer',
        'reprint_of_id' => 'integer',
        'requested_by' => 'integer',
        'pos_print_job_id' => 'integer',
        'queued_at' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->belongsTo(OrderLabelPrinterProfile::class, 'printer_profile_id');
    }

    public function printJob()
    {
        return $this->belongsTo(PosPrintJob::class, 'pos_print_job_id');
    }
}
