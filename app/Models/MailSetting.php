<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSetting extends Model
{
    public const SINGLETON_ID = 1;

    public const SECURITY_MODES = [
        'implicit_tls',
        'starttls',
        'none',
    ];

    protected $fillable = [
        'id',
        'smtp_host',
        'smtp_port',
        'security_mode',
        'smtp_username',
        'smtp_password',
        'from_address',
        'from_name',
        'daily_dish_admin_emails',
        'revision',
        'updated_by',
    ];

    protected $casts = [
        'id' => 'integer',
        'smtp_port' => 'integer',
        'revision' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $incrementing = false;

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
