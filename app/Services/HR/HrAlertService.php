<?php

namespace App\Services\HR;

use App\Models\HrAlert;
use App\Models\HrDocument;
use App\Models\HrDocumentType;
use App\Models\HrEmployee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HrAlertService
{
    /** @return array{opened:int,resolved:int} */
    public function refreshCompany(int $companyId): array
    {
        return DB::transaction(function () use ($companyId): array {
            $activeKeys = [];
            $opened = 0;
            $employees = HrEmployee::query()->where('company_id', $companyId)
                ->whereIn('employment_status', ['onboarding', 'active', 'suspended', 'notice'])->get();
            $requiredTypes = HrDocumentType::query()->where('company_id', $companyId)->where('is_active', true)->where('is_required', true)->get();

            foreach ($employees as $employee) {
                foreach ($requiredTypes as $type) {
                    if (! $this->typeApplies($type, $employee)) {
                        continue;
                    }
                    $exists = HrDocument::query()->where('employee_id', $employee->id)->where('document_type_id', $type->id)
                        ->whereNull('archived_at')->exists();
                    if (! $exists) {
                        $key = "missing-document:{$employee->id}:{$type->id}";
                        $activeKeys[] = $key;
                        $opened += $this->upsert($companyId, (int) $employee->id, $key, 'missing_document', 'warning', null,
                            __('Missing required document: :type', ['type' => $type->name]), HrDocumentType::class, (int) $type->id,
                            ['document_type_id' => (int) $type->id]);
                    }
                }
            }

            $documents = HrDocument::query()->with('documentType')->where('company_id', $companyId)
                ->whereNull('archived_at')->whereNotNull('expiry_date')->get();
            foreach ($documents as $document) {
                $thresholds = $document->documentType?->expiry_warning_days ?: config('hr.documents.expiry_warning_days', [90, 60, 30]);
                $max = max(array_map('intval', $thresholds ?: [90, 60, 30]));
                $days = now()->startOfDay()->diffInDays(Carbon::parse($document->expiry_date)->startOfDay(), false);
                if ($days > $max) {
                    continue;
                }
                $expired = $days < 0;
                if ($expired && (is_object($document->status) ? $document->status->value : $document->status) !== 'expired') {
                    $document->forceFill(['status' => 'expired'])->save();
                }
                $key = "document-expiry:{$document->id}";
                $activeKeys[] = $key;
                $severity = $expired || $days <= 30 ? 'critical' : ($days <= 60 ? 'warning' : 'info');
                $message = $expired
                    ? __('Employee document has expired.')
                    : __('Employee document expires in :days days.', ['days' => $days]);
                $opened += $this->upsert($companyId, (int) $document->employee_id, $key,
                    $expired ? 'document_expired' : 'document_expiring', $severity, $document->expiry_date,
                    $message, HrDocument::class, (int) $document->id, ['days_remaining' => $days]);
            }

            $stale = HrAlert::query()->where('company_id', $companyId)
                ->whereIn('type', ['missing_document', 'document_expiring', 'document_expired'])
                ->whereIn('status', ['open', 'acknowledged'])->whereNotIn('dedupe_key', $activeKeys ?: [''])->get();
            foreach ($stale as $alert) {
                $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
            }

            return ['opened' => $opened, 'resolved' => $stale->count()];
        });
    }

    /** @param array<string, mixed> $metadata */
    private function upsert(int $companyId, int $employeeId, string $key, string $type, string $severity, mixed $dueAt, string $message, string $subjectType, int $subjectId, array $metadata): int
    {
        $alert = HrAlert::query()->firstOrNew(['company_id' => $companyId, 'dedupe_key' => $key]);
        $isNew = ! $alert->exists;
        $alert->fill(['employee_id' => $employeeId, 'type' => $type, 'severity' => $severity,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'due_at' => $dueAt,
            'message' => $message, 'metadata' => $metadata]);
        if ($isNew || in_array($this->status($alert), ['resolved', 'dismissed', ''], true)) {
            $alert->status = 'open';
            $alert->resolved_at = null;
            $alert->resolved_by = null;
        }
        $alert->save();

        return $isNew ? 1 : 0;
    }

    private function typeApplies(HrDocumentType $type, HrEmployee $employee): bool
    {
        $requiredFor = $type->required_for ?: [];
        if ($requiredFor === []) {
            return true;
        }

        $employmentType = Str::lower((string) $employee->employment_type);
        $jobTitle = Str::lower((string) $employee->job_title);

        return collect($requiredFor)->contains(function (string $requirement) use ($employmentType, $jobTitle): bool {
            $needle = Str::lower(trim($requirement));

            return $needle !== '' && ($employmentType === $needle || Str::contains($jobTitle, $needle));
        });
    }

    private function status(HrAlert $alert): string
    {
        return is_object($alert->status) ? $alert->status->value : (string) $alert->status;
    }
}
