<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosPrintStreamEvent extends Model
{
    use HasFactory;

    public const EVENT_JOB_AVAILABLE = 'job_available';

    protected $table = 'pos_print_stream_events';

    public $timestamps = false;

    protected $fillable = [
        'terminal_id',
        'event_type',
        'payload_json',
        'created_at',
    ];

    protected $casts = [
        'terminal_id' => 'integer',
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
