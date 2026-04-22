<?php

namespace App\Services\Marketing;

use App\Models\MarketingBrief;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingBriefService
{
    public function __construct(
        protected MarketingActivityLogService $activityLog,
    ) {}

    public function create(array $data, int $actorId): MarketingBrief
    {
        $this->validate($data);

        return DB::transaction(function () use ($data, $actorId) {
            $brief = MarketingBrief::query()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'campaign_id' => $data['campaign_id'] ?? null,
                'status' => 'draft',
                'due_date' => $data['due_date'] ?? null,
                'objectives' => $data['objectives'] ?? null,
                'target_audience' => $data['target_audience'] ?? null,
                'budget_notes' => $data['budget_notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->activityLog->log('brief.created', $actorId, $brief, ['title' => $brief->title]);

            return $brief;
        });
    }

    public function update(MarketingBrief $brief, array $data, int $actorId): MarketingBrief
    {
        if ($brief->isApproved()) {
            throw ValidationException::withMessages([
                'status' => ['An approved brief cannot be edited.'],
            ]);
        }

        $this->validate($data);

        return DB::transaction(function () use ($brief, $data, $actorId) {
            $brief->update([
                'title' => $data['title'] ?? $brief->title,
                'description' => $data['description'] ?? $brief->description,
                'campaign_id' => array_key_exists('campaign_id', $data) ? $data['campaign_id'] : $brief->campaign_id,
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $brief->due_date,
                'objectives' => $data['objectives'] ?? $brief->objectives,
                'target_audience' => $data['target_audience'] ?? $brief->target_audience,
                'budget_notes' => $data['budget_notes'] ?? $brief->budget_notes,
                'updated_by' => $actorId,
            ]);

            $this->activityLog->log('brief.updated', $actorId, $brief, ['title' => $brief->title]);

            return $brief->fresh();
        });
    }

    public function submitForReview(MarketingBrief $brief, int $actorId): MarketingBrief
    {
        if (! $brief->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft briefs can be submitted for review.'],
            ]);
        }

        $brief->update(['status' => 'pending_review', 'updated_by' => $actorId]);
        $this->activityLog->log('brief.submitted_for_review', $actorId, $brief);

        return $brief;
    }

    public function approve(MarketingBrief $brief, int $actorId): MarketingBrief
    {
        if (! $brief->isPendingReview()) {
            throw ValidationException::withMessages([
                'status' => ['Only briefs pending review can be approved.'],
            ]);
        }

        $brief->update(['status' => 'approved', 'updated_by' => $actorId]);
        $this->activityLog->log('brief.approved', $actorId, $brief);

        return $brief;
    }

    public function reject(MarketingBrief $brief, int $actorId, ?string $note = null): MarketingBrief
    {
        if (! $brief->isPendingReview()) {
            throw ValidationException::withMessages([
                'status' => ['Only briefs pending review can be rejected.'],
            ]);
        }

        $brief->update(['status' => 'rejected', 'updated_by' => $actorId]);
        $this->activityLog->log('brief.rejected', $actorId, $brief, ['note' => $note]);

        return $brief;
    }

    private function validate(array $data): void
    {
        if (empty($data['title'])) {
            throw ValidationException::withMessages(['title' => ['Title is required.']]);
        }
    }
}
