<?php

namespace App\Services\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingAssetVersion;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingAssetService
{
    public function __construct(
        protected MarketingSettingsService $settingsService,
        protected MarketingActivityLogService $activityLog,
    ) {}

    /**
     * Generate a presigned PUT URL for direct browser upload.
     * Returns ['url', 's3_key', 'bucket', 'expires_at'].
     */
    public function generatePresignedPutUrl(string $filename, string $mimeType, int $actorId): array
    {
        $settings = $this->settingsService->get();
        $bucket = $settings->s3_asset_bucket ?? config('filesystems.disks.s3.bucket');

        if (! $bucket) {
            throw new \RuntimeException('S3 asset bucket is not configured.');
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $uuid = Str::uuid()->toString();
        $year = now()->format('Y');
        $month = now()->format('m');
        $prefix = config('marketing.assets.key_prefix', 'marketing-assets');
        $s3Key = "{$prefix}/{$year}/{$month}/{$uuid}.{$ext}";

        $ttlMinutes = config('marketing.assets.presign_put_ttl_minutes', 15);
        $maxSizeMb = config('marketing.assets.max_file_size_mb', 100);

        $s3 = $this->buildS3Client();

        $cmd = $s3->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $s3Key,
            'ContentType' => $mimeType,
        ]);

        $presignedRequest = $s3->createPresignedRequest($cmd, "+{$ttlMinutes} minutes");
        $url = (string) $presignedRequest->getUri();

        return [
            'url' => $url,
            's3_key' => $s3Key,
            'bucket' => $bucket,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
            'max_size_bytes' => $maxSizeMb * 1024 * 1024,
        ];
    }

    /**
     * Complete an upload after the client confirms the S3 PUT succeeded.
     */
    public function completeUpload(array $data, int $actorId): MarketingAsset
    {
        return DB::transaction(function () use ($data, $actorId) {
            $asset = MarketingAsset::query()->create([
                'name' => $data['name'],
                'type' => $data['type'],
                's3_key' => $data['s3_key'],
                's3_bucket' => $data['bucket'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'status' => 'pending_review',
                'current_version' => 1,
                'uploaded_by' => $actorId,
            ]);

            MarketingAssetVersion::query()->create([
                'asset_id' => $asset->id,
                'version_number' => 1,
                's3_key' => $data['s3_key'],
                'file_size' => $data['file_size'] ?? null,
                'note' => 'Initial upload',
                'created_by' => $actorId,
            ]);

            $this->activityLog->log('asset.uploaded', $actorId, $asset, [
                'name' => $asset->name,
                'type' => $asset->type,
            ]);

            return $asset;
        });
    }

    /**
     * Generate a presigned GET URL for serving an asset securely.
     */
    public function getPresignedReadUrl(MarketingAsset $asset, bool $download = false): string
    {
        $ttlMinutes = config('marketing.assets.presign_get_ttl_minutes', 60);

        $command = [
            'Bucket' => $asset->s3_bucket,
            'Key' => $asset->s3_key,
        ];

        if ($download) {
            $filename = str_replace(['"', '\\'], '', $asset->name);
            $command['ResponseContentDisposition'] = 'attachment; filename="'.$filename.'"';
        }

        $s3 = $this->buildS3Client();
        $cmd = $s3->getCommand('GetObject', $command);

        $presignedRequest = $s3->createPresignedRequest($cmd, "+{$ttlMinutes} minutes");

        return (string) $presignedRequest->getUri();
    }

    /**
     * Update asset status (pending_review → approved/rejected/archived).
     */
    public function updateStatus(MarketingAsset $asset, string $status, int $actorId): MarketingAsset
    {
        $allowed = ['pending_review', 'approved', 'rejected', 'archived'];
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid asset status: {$status}");
        }

        $previous = $asset->status;
        $asset->update(['status' => $status]);

        $this->activityLog->log("asset.status.{$status}", $actorId, $asset, [
            'previous_status' => $previous,
        ]);

        return $asset;
    }

    private function buildS3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region', config('services.ses.region', 'us-east-1')),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
        ]);
    }
}
