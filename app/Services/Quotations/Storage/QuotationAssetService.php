<?php

namespace App\Services\Quotations\Storage;

use App\Models\DocumentAsset;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class QuotationAssetService
{
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function storeImage(int $companyId, UploadedFile $file, ?int $userId = null): DocumentAsset
    {
        if ($companyId <= 0 || ! $file->isValid()) {
            throw ValidationException::withMessages(['image' => __('The uploaded image is invalid.')]);
        }

        $maxBytes = (int) config('quotations.max_asset_kb', 5120) * 1024;
        $size = (int) $file->getSize();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($size <= 0 || $size > $maxBytes || ! isset(self::MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages([
                'image' => __('Only PNG, JPEG, or WebP images up to :size MB are allowed.', ['size' => round($maxBytes / 1024 / 1024, 1)]),
            ]);
        }

        $contents = $file->get();
        $checksum = hash('sha256', $contents);
        $diskName = $this->diskName();
        $key = 'quotations/'.$companyId.'/assets/'.Str::uuid().'.'.self::MIME_EXTENSIONS[$mime];
        $disk = Storage::disk($diskName);

        try {
            $written = $disk->put($key, $contents, [
                'visibility' => 'private',
                'ContentType' => $mime,
            ]);
            if ($written !== true || ! $disk->exists($key)) {
                throw new ArtifactGenerationException('The image could not be written to storage.');
            }

            $stored = $disk->get($key);
            if (strlen($stored) !== $size || ! hash_equals($checksum, hash('sha256', $stored))) {
                throw new ArtifactGenerationException('The stored image failed checksum verification.');
            }

            return DocumentAsset::create([
                'company_id' => $companyId,
                'kind' => 'image',
                'disk' => $diskName,
                'storage_key' => $key,
                'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'uploaded_by' => $userId,
            ]);
        } catch (Throwable $exception) {
            try {
                $disk->delete($key);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            throw ValidationException::withMessages(['image' => __('Image upload failed. Please try again.')]);
        }
    }

    public function temporaryUrl(DocumentAsset $asset, ?DateTimeInterface $expiresAt = null): string
    {
        $expiresAt ??= now()->addMinutes((int) config(
            'quotations.storage.temporary_url_minutes',
            config('quotations.download_ttl_minutes', 15),
        ));

        return Storage::disk($asset->disk)->temporaryUrl($asset->storage_key, $expiresAt);
    }

    /** @return array<string, array<string, int|string|null>> */
    public function snapshotMap(iterable $assets): array
    {
        $map = [];
        foreach ($assets as $asset) {
            if (! $asset instanceof DocumentAsset) {
                continue;
            }

            $map[(string) $asset->getKey()] = [
                'disk' => $asset->disk,
                'storage_key' => $asset->storage_key,
                'mime_type' => $asset->mime_type,
                'size_bytes' => $asset->size_bytes,
                'checksum_sha256' => $asset->checksum_sha256,
            ];
        }

        return $map;
    }

    /** @return array<int, int> */
    public function assetIdsFromBlocks(array $blocks): array
    {
        $assetIds = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'image') {
                $assetIds[] = (int) data_get($block, 'settings.asset_id');
            }
            if (($block['type'] ?? null) === 'menu_body') {
                $this->collectTiptapAssetIds($block['content'] ?? null, $assetIds);
            }
        }

        return array_values(array_unique(array_filter($assetIds)));
    }

    /** @param array<int, int> $assetIds */
    private function collectTiptapAssetIds(mixed $node, array &$assetIds): void
    {
        if (! is_array($node)) {
            return;
        }
        if (($node['type'] ?? null) === 'quotationImage') {
            $assetIds[] = (int) data_get($node, 'attrs.assetId', data_get($node, 'attrs.asset_id'));
        }
        foreach ((array) ($node['content'] ?? []) as $child) {
            $this->collectTiptapAssetIds($child, $assetIds);
        }
    }

    private function diskName(): string
    {
        return (string) config('quotations.storage.disk', config('quotations.disk', 's3'));
    }
}
