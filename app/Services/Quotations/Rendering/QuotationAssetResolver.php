<?php

namespace App\Services\Quotations\Rendering;

use Illuminate\Support\Facades\Storage;

final class QuotationAssetResolver
{
    private const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function dataUri(array $normalizedSnapshot, mixed $assetId): ?string
    {
        $contents = $this->contents($normalizedSnapshot, $assetId);
        if ($contents === null) {
            return null;
        }

        return 'data:'.$contents['mime_type'].';base64,'.base64_encode($contents['contents']);
    }

    /** @return array{contents: string, mime_type: string}|null */
    public function contents(array $normalizedSnapshot, mixed $assetId): ?array
    {
        if (! is_scalar($assetId) || (string) $assetId === '') {
            return null;
        }

        $asset = $normalizedSnapshot['assets'][(string) $assetId] ?? null;
        if (! is_array($asset)) {
            return null;
        }

        $diskName = (string) ($asset['disk'] ?? '');
        $key = (string) ($asset['storage_key'] ?? '');
        if ($diskName === '' || ! $this->validKey($key)) {
            return null;
        }

        $disk = Storage::disk($diskName);
        if (! $disk->exists($key)) {
            return null;
        }

        $size = $disk->size($key);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return null;
        }

        $contents = $disk->get($key);
        $mime = $this->detectedMime($contents);
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        $declaredMime = (string) ($asset['mime_type'] ?? '');
        if ($declaredMime !== '' && $declaredMime !== $mime) {
            return null;
        }

        $expectedChecksum = strtolower((string) ($asset['checksum_sha256'] ?? ''));
        if ($expectedChecksum !== '' && ! hash_equals($expectedChecksum, hash('sha256', $contents))) {
            return null;
        }

        return ['contents' => $contents, 'mime_type' => $mime];
    }

    private function validKey(string $key): bool
    {
        return $key !== ''
            && ! str_starts_with($key, '/')
            && ! str_contains($key, "\0")
            && ! preg_match('#(^|/)\.\.(/|$)#', $key);
    }

    private function detectedMime(string $contents): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->buffer($contents);
    }
}
