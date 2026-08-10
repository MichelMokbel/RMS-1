<?php

namespace App\Services\Quotations\Storage;

use App\Models\QuotationArtifact;
use App\Models\QuotationVersion;
use App\Services\Quotations\Rendering\QuotationDocxRenderer;
use App\Services\Quotations\Rendering\QuotationPdfRenderer;
use App\Services\Quotations\Rendering\RenderedDocument;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class QuotationArtifactService
{
    private const FORMATS = ['pdf', 'docx'];

    public function __construct(
        private readonly QuotationPdfRenderer $pdf,
        private readonly QuotationDocxRenderer $docx,
    ) {}

    /** @return Collection<int, QuotationArtifact> */
    public function generateForVersion(QuotationVersion $version): Collection
    {
        return $this->generate($version, self::FORMATS);
    }

    /**
     * Regenerate missing or failed formats. Ready, verified artifacts remain immutable.
     *
     * @param  list<string>|null  $formats
     * @return Collection<int, QuotationArtifact>
     */
    public function retryForVersion(QuotationVersion $version, ?array $formats = null): Collection
    {
        return $this->generate($version, $formats ?? self::FORMATS);
    }

    public function temporaryUrl(QuotationArtifact $artifact, ?DateTimeInterface $expiresAt = null): string
    {
        if (! $artifact->isReady() || ! $this->isVerified($artifact)) {
            throw new ArtifactGenerationException('The quotation artifact is missing or has failed verification.');
        }

        $expiresAt ??= now()->addMinutes((int) config(
            'quotations.storage.temporary_url_minutes',
            config('quotations.download_ttl_minutes', 15),
        ));

        try {
            return Storage::disk($artifact->disk)->temporaryUrl(
                $artifact->storage_key,
                $expiresAt,
                [
                    'ResponseContentType' => $artifact->mime_type,
                    'ResponseContentDisposition' => 'attachment; filename="'.$this->downloadName($artifact).'"',
                ],
            );
        } catch (Throwable $exception) {
            throw new ArtifactGenerationException('Unable to create a temporary artifact URL.', previous: $exception);
        }
    }

    public function isVerified(QuotationArtifact $artifact): bool
    {
        if (! $artifact->storage_key || ! $artifact->disk || ! $artifact->checksum_sha256) {
            return false;
        }

        try {
            $disk = Storage::disk($artifact->disk);
            if (! $disk->exists($artifact->storage_key)) {
                return false;
            }

            $contents = $disk->get($artifact->storage_key);

            return strlen($contents) === (int) $artifact->size_bytes
                && hash_equals((string) $artifact->checksum_sha256, hash('sha256', $contents));
        } catch (Throwable) {
            return false;
        }
    }

    public function downloadName(QuotationArtifact $artifact): string
    {
        $artifact->loadMissing('version');
        $version = $artifact->version;
        $number = Str::slug((string) ($version?->quotation_number ?: 'quotation'), '-');

        return $number.'-R'.((int) ($version?->revision ?? 1)).'.'.$artifact->format;
    }

    public function deleteForVersion(QuotationVersion $version): void
    {
        foreach ($version->artifacts()->get() as $artifact) {
            try {
                if ($artifact->storage_key && Storage::disk($artifact->disk)->exists($artifact->storage_key)) {
                    Storage::disk($artifact->disk)->delete($artifact->storage_key);
                }

                $artifact->delete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  list<string>  $formats
     * @return Collection<int, QuotationArtifact>
     */
    private function generate(QuotationVersion $version, array $formats): Collection
    {
        $formats = array_values(array_unique($formats));
        if ($formats === [] || array_diff($formats, self::FORMATS) !== []) {
            throw new ArtifactGenerationException('Artifact formats must be pdf and/or docx.');
        }

        $version->loadMissing('quotation');
        if (! $version->quotation) {
            throw new ArtifactGenerationException('Quotation version is not attached to a quotation.');
        }

        $completed = collect();
        /** @var list<array{artifact: QuotationArtifact, key: string}> $newWrites */
        $newWrites = [];

        try {
            foreach ($formats as $format) {
                $existing = $version->artifacts()->where('format', $format)->first();
                if ($existing?->isReady() && $this->isVerified($existing)) {
                    $completed->push($existing);

                    continue;
                }

                $artifact = $existing ?? $version->artifacts()->create([
                    'format' => $format,
                    'disk' => $this->diskName(),
                    'generation_status' => QuotationArtifact::STATUS_PENDING,
                ]);
                if ($artifact->storage_key) {
                    $oldDisk = Storage::disk($artifact->disk ?: $this->diskName());
                    if ($oldDisk->exists($artifact->storage_key)) {
                        $oldDisk->delete($artifact->storage_key);
                    }
                }
                $artifact->forceFill([
                    'disk' => $this->diskName(),
                    'storage_key' => null,
                    'mime_type' => null,
                    'size_bytes' => null,
                    'checksum_sha256' => null,
                    'generation_status' => QuotationArtifact::STATUS_PENDING,
                    'generated_at' => null,
                    'error_message' => null,
                ])->save();

                $rendered = $this->render($format, (array) $version->snapshot);
                $key = $this->storageKey($version, $rendered);
                $this->writeAndVerify($key, $rendered);
                $newWrites[] = ['artifact' => $artifact, 'key' => $key];

                $artifact->forceFill([
                    'storage_key' => $key,
                    'mime_type' => $rendered->mimeType,
                    'size_bytes' => $rendered->size(),
                    'checksum_sha256' => $rendered->checksum(),
                    'generation_status' => QuotationArtifact::STATUS_READY,
                    'generated_at' => now(),
                    'error_message' => null,
                ])->save();
                $completed->push($artifact->fresh());
            }
        } catch (Throwable $exception) {
            $this->cleanupNewWrites($newWrites, $exception);

            if (isset($artifact) && ! collect($newWrites)->contains(fn (array $write) => $write['artifact']->is($artifact))) {
                $artifact->forceFill([
                    'generation_status' => QuotationArtifact::STATUS_FAILED,
                    'generated_at' => null,
                    'error_message' => Str::limit($exception->getMessage(), 2000, ''),
                ])->save();
            }

            throw new ArtifactGenerationException('Quotation artifact generation failed.', previous: $exception);
        }

        return $completed;
    }

    private function render(string $format, array $snapshot): RenderedDocument
    {
        return match ($format) {
            'pdf' => $this->pdf->render($snapshot),
            'docx' => $this->docx->render($snapshot),
        };
    }

    private function writeAndVerify(string $key, RenderedDocument $rendered): void
    {
        $disk = Storage::disk($this->diskName());
        $written = $disk->put($key, $rendered->contents, [
            'visibility' => 'private',
            'ContentType' => $rendered->mimeType,
        ]);

        if ($written !== true || ! $disk->exists($key)) {
            throw new ArtifactGenerationException('The artifact could not be written to storage.');
        }

        $stored = $disk->get($key);
        if (strlen($stored) !== $rendered->size() || ! hash_equals($rendered->checksum(), hash('sha256', $stored))) {
            $disk->delete($key);

            throw new ArtifactGenerationException('The stored artifact failed checksum verification.');
        }
    }

    /** @param list<array{artifact: QuotationArtifact, key: string}> $writes */
    private function cleanupNewWrites(array $writes, Throwable $cause): void
    {
        foreach ($writes as $write) {
            try {
                Storage::disk($this->diskName())->delete($write['key']);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            $write['artifact']->forceFill([
                'storage_key' => null,
                'mime_type' => null,
                'size_bytes' => null,
                'checksum_sha256' => null,
                'generation_status' => QuotationArtifact::STATUS_FAILED,
                'generated_at' => null,
                'error_message' => Str::limit($cause->getMessage(), 2000, ''),
            ])->save();
        }
    }

    private function storageKey(QuotationVersion $version, RenderedDocument $rendered): string
    {
        $quotation = $version->quotation;
        $name = Str::slug((string) ($version->quotation_number ?: 'quotation-'.$quotation->getKey()), '-');

        return 'quotations/'.(int) $quotation->company_id.'/'.(int) $quotation->getKey()
            .'/versions/'.(int) $version->revision.'/'.$name.'-R'.(int) $version->revision.'.'.$rendered->extension;
    }

    private function diskName(): string
    {
        return (string) config('quotations.storage.disk', config('quotations.disk', 's3'));
    }
}
