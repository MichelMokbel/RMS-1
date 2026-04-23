<?php

namespace App\Services\Marketing;

use App\Models\MarketingActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MarketingActivityLogService
{
    public function log(
        string $action,
        ?int $actorId = null,
        Model|string|null $subject = null,
        array $payload = [],
    ): void {
        if (! Schema::hasTable('marketing_activity_logs')) {
            return;
        }

        $subjectType = null;
        $subjectId = null;

        if ($subject instanceof Model) {
            $subjectType = $subject->getMorphClass();
            $subjectId = $subject->getKey();
        } elseif (is_string($subject)) {
            $subjectType = $subject;
        }

        MarketingActivityLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
