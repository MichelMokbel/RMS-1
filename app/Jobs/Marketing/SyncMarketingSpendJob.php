<?php

namespace App\Jobs\Marketing;

use App\Models\MarketingPlatformAccount;
use App\Models\MarketingSyncLog;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\MarketingSpendSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMarketingSpendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $platformAccountId,
        public readonly string $date,
    ) {}

    public function handle(
        MarketingSpendSyncService $syncService,
        MarketingActivityLogService $activityLog,
        MarketingSettingsService $settingsService,
    ): void {
        $account = MarketingPlatformAccount::find($this->platformAccountId);

        if (! $account || $account->status !== 'active') {
            return;
        }

        if (! $settingsService->isSyncEnabledFor($account->platform)) {
            return;
        }

        $syncLog = MarketingSyncLog::query()->create([
            'platform_account_id' => $account->id,
            'sync_type' => 'spend',
            'status' => 'pending',
            'context' => ['date' => $this->date],
        ]);

        $syncLog->markRunning();

        try {
            $synced = match ($account->platform) {
                'meta' => $syncService->syncMetaSpend($account, $this->date),
                'google' => $syncService->syncGoogleSpend($account, $this->date),
                default => 0,
            };

            $syncLog->markCompleted($synced);

            $activityLog->log('sync.spend.completed', null, $account, [
                'sync_log_id' => $syncLog->id,
                'date' => $this->date,
                'records_synced' => $synced,
            ]);
        } catch (\Throwable $e) {
            $syncLog->markFailed($e->getMessage());

            $activityLog->log('sync.spend.failed', null, $account, [
                'sync_log_id' => $syncLog->id,
                'date' => $this->date,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
