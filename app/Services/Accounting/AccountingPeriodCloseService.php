<?php

namespace App\Services\Accounting;

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\PeriodLock;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingPeriodCloseService
{
    public function __construct(
        protected AccountingPeriodChecklistService $checklists,
        protected FinanceSettingsService $financeSettings,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function syncStatuses(?int $companyId = null): void
    {
        if (! Schema::hasTable('accounting_periods')) {
            return;
        }

        AccountingPeriod::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('start_date')
            ->get()
            ->each(fn (AccountingPeriod $period) => $this->syncSingleStatus($period));
    }

    public function syncSingleStatus(AccountingPeriod $period): AccountingPeriod
    {
        if (in_array($period->status, ['closed', 'reopened'], true)) {
            return $period;
        }

        $today = now()->toDateString();
        $nextStatus = $period->end_date && $period->end_date->toDateString() < $today ? 'ended_open' : 'open';

        if ($period->status !== $nextStatus) {
            $period->forceFill(['status' => $nextStatus])->save();
        }

        return $period->fresh();
    }

    /**
     * @return Collection<int, AccountingPeriod>
     */
    public function companyPeriods(?int $companyId = null): Collection
    {
        $this->syncStatuses($companyId);
        $this->checklists->ensureCompanyTasks($companyId);

        return AccountingPeriod::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(AccountingPeriod $period): array
    {
        $period = $this->syncSingleStatus($period->fresh());
        $evaluation = $this->checklists->evaluate($period);

        return [
            'period' => $period->fresh(['company', 'fiscalYear']),
            'summary' => $evaluation['summary'],
            'items' => $evaluation['items'],
            'exceptions' => $evaluation['exceptions'],
            'lock' => $this->latestLock($period),
            'lock_date' => $this->financeSettings->getLockDate(),
            'period_state' => $this->periodState($period),
        ];
    }

    public function close(AccountingPeriod $period, int $actorId, string $note): AccountingPeriod
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'close_note' => __('A close note is required.'),
            ]);
        }

        $ready = $this->readiness($period);
        if (! $ready['summary']['is_ready']) {
            throw ValidationException::withMessages([
                'period' => __('The period cannot be closed until all required checklist items are complete.'),
            ]);
        }

        /** @var AccountingPeriod $closed */
        $closed = DB::transaction(function () use ($period, $actorId, $note) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status === 'closed') {
                throw ValidationException::withMessages([
                    'period' => __('This period is already closed.'),
                ]);
            }

            $period->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actorId,
            ])->save();

            PeriodLock::query()->create([
                'company_id' => $period->company_id,
                'period_id' => $period->id,
                'lock_type' => 'close',
                'module' => 'all',
                'reason' => $note,
                'locked_at' => now(),
                'locked_by' => $actorId,
            ]);

            $this->financeSettings->setLockDate($period->end_date?->toDateString(), $actorId);

            return $period->fresh();
        });

        $this->auditLog->log('period.closed', $actorId, $closed, [
            'note' => $note,
        ], (int) $closed->company_id);

        return $closed;
    }

    public function reopen(AccountingPeriod $period, int $actorId, string $reason, bool $moveLockDateBack = false): AccountingPeriod
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reopen_reason' => __('A reopen reason is required.'),
            ]);
        }

        /** @var AccountingPeriod $reopened */
        $reopened = DB::transaction(function () use ($period, $actorId, $reason, $moveLockDateBack) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->status !== 'closed') {
                throw ValidationException::withMessages([
                    'period' => __('Only closed periods can be reopened.'),
                ]);
            }

            $period->forceFill([
                'status' => 'reopened',
            ])->save();

            PeriodLock::query()->create([
                'company_id' => $period->company_id,
                'period_id' => $period->id,
                'lock_type' => 'reopen',
                'module' => 'all',
                'reason' => $reason,
                'locked_at' => now(),
                'locked_by' => $actorId,
            ]);

            if ($moveLockDateBack) {
                $previousClosed = AccountingPeriod::query()
                    ->where('company_id', $period->company_id)
                    ->where('status', 'closed')
                    ->whereDate('end_date', '<', $period->start_date->toDateString())
                    ->orderByDesc('end_date')
                    ->first();

                $this->financeSettings->setLockDate($previousClosed?->end_date?->toDateString(), $actorId, true);
            }

            return $period->fresh();
        });

        $this->auditLog->log('period.reopened', $actorId, $reopened, [
            'reason' => $reason,
            'move_lock_date_back' => $moveLockDateBack,
        ], (int) $reopened->company_id);

        return $reopened;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestLock(AccountingPeriod $period): ?array
    {
        if (! Schema::hasTable('period_locks')) {
            return null;
        }

        $lock = PeriodLock::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->latest('id')
            ->first();

        if (! $lock) {
            return null;
        }

        return [
            'lock_type' => $lock->lock_type,
            'module' => $lock->module,
            'reason' => $lock->reason,
            'locked_at' => $lock->locked_at,
            'locked_by' => $lock->locked_by,
        ];
    }

    public function periodState(AccountingPeriod $period): string
    {
        $today = Carbon::today();

        if ($period->status === 'closed') {
            return 'closed';
        }

        if ($period->status === 'reopened') {
            return 'reopened';
        }

        if ($period->start_date && $period->start_date->isFuture()) {
            return 'future';
        }

        if ($period->end_date && $period->end_date->lt($today)) {
            return 'ended';
        }

        return 'current';
    }

    public function defaultCompanyId(): ?int
    {
        return AccountingCompany::query()->where('is_default', true)->value('id')
            ?: AccountingCompany::query()->orderBy('id')->value('id');
    }
}
