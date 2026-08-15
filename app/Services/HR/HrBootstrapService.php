<?php

namespace App\Services\HR;

use App\Models\AccountingCompany;
use App\Models\HrDocumentType;
use App\Models\HrLeavePolicy;
use App\Models\HrLeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HrBootstrapService
{
    public function __construct(
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
        protected HrAlertService $alerts,
    ) {}

    /** @return array{document_types:int,leave_types:int,draft_policies:int} */
    public function bootstrap(AccountingCompany $company, User $actor): array
    {
        $this->access->assertCompany($actor, (int) $company->id, 'hr.settings.manage');

        return DB::transaction(function () use ($company, $actor): array {
            $counts = ['document_types' => 0, 'leave_types' => 0, 'draft_policies' => 0];
            foreach (config('hr.document_type_presets', []) as $preset) {
                HrDocumentType::query()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $preset['code']],
                    ['name' => $preset['name'], 'requires_issue_date' => $preset['requires_issue_date'] ?? false,
                        'requires_expiry_date' => $preset['requires_expiry_date'] ?? false,
                        'is_required' => $preset['is_required'] ?? false,
                        'required_for' => $preset['required_for'] ?? null,
                        'expiry_warning_days' => config('hr.documents.expiry_warning_days', [90, 60, 30]),
                        'is_sensitive' => true, 'is_active' => true]
                );
                $counts['document_types']++;
            }

            foreach (config('hr.qatar_leave_presets', []) as $preset) {
                $type = HrLeaveType::query()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $preset['code']],
                    ['name' => $preset['name'], 'is_paid' => $preset['is_paid'], 'is_active' => true]
                );
                $counts['leave_types']++;
                HrLeavePolicy::query()->firstOrCreate(
                    ['company_id' => $company->id, 'leave_type_id' => $type->id, 'name' => 'Qatar baseline — confirmation required'],
                    ['effective_from' => now()->startOfYear()->toDateString(), 'entitlement_days' => 0,
                        'accrual_rate_days' => 0, 'accrual_frequency' => 'annual', 'waiting_period_days' => 0,
                        'allow_negative' => false, 'requires_attachment' => false, 'counts_calendar_days' => true,
                        'is_active' => false, 'rules' => ['requires_legal_confirmation' => true]]
                );
                $counts['draft_policies']++;
            }

            $this->audit->log('hr.settings.presets_bootstrapped', (int) $actor->id, $company, $counts, (int) $company->id);
            $this->alerts->refreshCompany((int) $company->id);

            return $counts;
        });
    }
}
