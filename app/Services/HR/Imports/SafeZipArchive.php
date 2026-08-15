<?php

namespace App\Services\HR\Imports;

use RuntimeException;
use ZipArchive;

class SafeZipArchive
{
    /** @return array<int, string> */
    public function validate(string $path): array
    {
        $zip = $this->open($path);
        try {
            $names = [];
            $seen = [];
            $total = 0;
            $maxFiles = (int) config('hr.imports.max_archive_files', 2000);
            $maxBytes = (int) config('hr.imports.max_uncompressed_bytes', 500 * 1024 * 1024);
            if ($zip->numFiles > $maxFiles) {
                throw new RuntimeException('The archive contains too many files.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $attributes = $zip->getExternalAttributesIndex($i, $opsys, $attr) ? ($attr >> 16) & 0170000 : 0;
                if (! $this->safeName($name) || $attributes === 0120000) {
                    throw new RuntimeException('The archive contains an unsafe path or symbolic link.');
                }
                if (isset($seen[$name])) {
                    throw new RuntimeException('The archive contains duplicate entry names.');
                }
                $seen[$name] = true;
                $total += (int) ($stat['size'] ?? 0);
                if ($total > $maxBytes) {
                    throw new RuntimeException('The archive is too large when expanded.');
                }
                if (! str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }

            return $names;
        } finally {
            $zip->close();
        }
    }

    public function extractFile(string $path, string $entryName, string $destination): void
    {
        if (! $this->safeName($entryName)) {
            throw new RuntimeException('Unsafe archive manifest path.');
        }
        $zip = $this->open($path);
        try {
            $stat = $zip->statName($entryName);
            if (! $stat) {
                throw new RuntimeException("Archive file not found: {$entryName}");
            }
            $expected = (int) ($stat['size'] ?? 0);
            $max = max((int) config('hr.documents.max_size_kb', 10_240), 1) * 1024;
            if ($expected <= 0 || $expected > $max) {
                throw new RuntimeException('The archived document exceeds the allowed size.');
            }
            $stream = $zip->getStream($entryName);
            if (! $stream) {
                throw new RuntimeException("Archive file not found: {$entryName}");
            }
            $target = fopen($destination, 'wb');
            if (! $target) {
                throw new RuntimeException('Temporary extraction file cannot be opened.');
            }
            $copied = stream_copy_to_stream($stream, $target, $max + 1);
            fclose($target);
            fclose($stream);
            if ($copied !== $expected) {
                throw new RuntimeException('The archived document could not be extracted completely.');
            }
        } finally {
            $zip->close();
        }
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The ZIP archive cannot be opened.');
        }

        return $zip;
    }

    private function safeName(string $name): bool
    {
        return $name !== '' && ! str_starts_with($name, '/') && ! str_contains($name, '..') && ! str_contains($name, '\\') && ! preg_match('/^[A-Za-z]:/', $name);
    }
}
