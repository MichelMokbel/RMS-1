<?php

namespace App\Jobs\Marketing;

use App\Models\MarketingPlatformAccount;
use App\Models\MarketingSyncLog;
use App\Services\Marketing\MarketingActivityLogService;
use App\Services\Marketing\MarketingCampaignSyncService;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMarketingCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $platformAccountId,
    ) {}

    public function handle(
        MarketingCampaignSyncService $syncService,
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
            'sync_type' => 'campaigns',
            'status' => 'pending',
        ]);

        $syncLog->markRunning();

        try {
            $synced = match ($account->platform) {
                'meta' => $syncService->syncMeta($account),
                'google' => $syncService->syncGoogle($account),
                default => 0,
            };

            $syncLog->markCompleted($synced);

            $activityLog->log('sync.campaigns.completed', null, $account, [
                'sync_log_id' => $syncLog->id,
                'records_synced' => $synced,
            ]);
        } catch (\Throwable $e) {
            $account->update(['sync_error' => $e->getMessage()]);
            $syncLog->markFailed($e->getMessage());

            $activityLog->log('sync.campaigns.failed', null, $account, [
                'sync_log_id' => $syncLog->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
