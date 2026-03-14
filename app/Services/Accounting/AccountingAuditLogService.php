<?php

namespace App\Services\Accounting;

use App\Models\AccountingAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AccountingAuditLogService
{
    public function log(string $action, ?int $actorId = null, Model|string|null $subject = null, array $payload = [], ?int $companyId = null): void
    {
        if (! Schema::hasTable('accounting_audit_logs')) {
            return;
        }

        $subjectType = null;
        $subjectId = null;

        if ($subject instanceof Model) {
            $subjectType = $subject::class;
            $subjectId = (int) $subject->getKey();
            $companyId = $companyId ?: (int) ($subject->company_id ?? 0) ?: null;
        } elseif (is_string($subject) && $subject !== '') {
            $subjectType = $subject;
        }

        AccountingAuditLog::query()->create([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload !== [] ? $payload : null,
            'created_at' => now(),
        ]);
    }
}
