<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\Marketing\SyncMarketingCampaignsJob;
use App\Jobs\Marketing\SyncMarketingSpendJob;
use App\Models\MarketingPlatformAccount;
use App\Services\Marketing\MarketingAssetService;
use App\Services\Marketing\MarketingSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingAssetController extends Controller
{
    public function __construct(
        protected MarketingAssetService $assetService,
        protected MarketingSettingsService $settingsService,
    ) {}

    /**
     * Generate a presigned S3 PUT URL for a browser direct upload.
     * Caller provides: name, mime_type.
     * Returns: url, s3_key, bucket, expires_at, max_size_bytes.
     */
    public function presign(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string'],
        ]);

        if (! $this->settingsService->isS3Configured()) {
            return response()->json(['message' => 'S3 asset storage is not configured.'], 422);
        }

        try {
            $result = $this->assetService->generatePresignedPutUrl(
                filename: $request->input('name'),
                mimeType: $request->input('mime_type'),
                actorId: $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * Finalize a browser upload after the object has been written to S3.
     */
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:image,video,document,copy'],
            's3_key' => ['required', 'string', 'max:1024'],
            'bucket' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $expectedBucket = $this->settingsService->get()->s3_asset_bucket
            ?? config('filesystems.disks.s3.bucket');
        if ($expectedBucket && $validated['bucket'] !== $expectedBucket) {
            return response()->json(['message' => __('Invalid asset bucket.')], 422);
        }

        $keyPrefix = trim(config('marketing.assets.key_prefix', 'marketing-assets'), '/').'/';
        if (! str_starts_with($validated['s3_key'], $keyPrefix)) {
            return response()->json(['message' => __('Invalid asset key.')], 422);
        }

        $maxSizeBytes = (int) config('marketing.assets.max_file_size_mb', 100) * 1024 * 1024;
        if ($validated['file_size'] > $maxSizeBytes) {
            return response()->json(['message' => __('Asset exceeds the maximum allowed size.')], 422);
        }

        try {
            $asset = $this->assetService->completeUpload($validated, $request->user()->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Asset uploaded.'),
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'type' => $asset->type,
                'status' => $asset->status,
                'url' => route('marketing.assets.show', $asset),
            ],
        ], 201);
    }

    /**
     * Dispatch sync jobs for all active platform accounts.
     * Admin-only endpoint — gated by route middleware.
     */
    public function triggerSync(Request $request): JsonResponse
    {
        $lookbackDays = config('marketing.sync.spend_lookback_days', 3);

        $accounts = MarketingPlatformAccount::query()->active()->get();
        $dispatched = 0;

        foreach ($accounts as $account) {
            if (! $this->settingsService->isSyncEnabledFor($account->platform)) {
                continue;
            }

            SyncMarketingCampaignsJob::dispatch($account->id);

            for ($i = 1; $i <= $lookbackDays; $i++) {
                SyncMarketingSpendJob::dispatch(
                    $account->id,
                    now()->subDays($i)->toDateString(),
                );
            }

            $dispatched++;
        }

        return response()->json([
            'message' => "Sync jobs dispatched for {$dispatched} account(s).",
            'accounts' => $dispatched,
        ]);
    }
}
