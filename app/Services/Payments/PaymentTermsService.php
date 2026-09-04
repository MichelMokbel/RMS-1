<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PaymentTermsService
{
    /**
     * @return array{valid: bool, current: array<string, string>|null, errors: array<int, string>}
     */
    public function inspect(?CarbonInterface $at = null): array
    {
        $entries = config('payment_terms.published', []);
        $errors = [];
        $validEntries = [];
        $versions = [];

        if (! is_array($entries) || $entries === []) {
            return [
                'valid' => false,
                'current' => null,
                'errors' => ['PAYMENT_TERMS_MISSING'],
            ];
        }

        foreach (array_values($entries) as $index => $entry) {
            $prefix = 'PAYMENT_TERMS_ENTRY_'.($index + 1);

            if (! is_array($entry)) {
                $errors[] = $prefix.'_INVALID';

                continue;
            }

            $version = trim((string) ($entry['version'] ?? ''));
            $effectiveValue = trim((string) ($entry['effective_at'] ?? ''));
            $url = trim((string) ($entry['url'] ?? ''));
            $path = trim((string) ($entry['content_path'] ?? ''));
            $expectedHash = trim((string) ($entry['content_hash'] ?? ''));

            if ($version === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
                $errors[] = $prefix.'_VERSION_INVALID';
            } elseif (isset($versions[$version])) {
                $errors[] = $prefix.'_VERSION_DUPLICATE';
            } else {
                $versions[$version] = true;
            }

            try {
                $effectiveAt = CarbonImmutable::parse($effectiveValue);
            } catch (\Throwable) {
                $effectiveAt = null;
            }

            if (! $effectiveAt || $effectiveAt->getOffset() !== 0) {
                $errors[] = $prefix.'_EFFECTIVE_AT_INVALID';
            }

            if (! $this->isHttpsUrl($url)) {
                $errors[] = $prefix.'_URL_INVALID';
            }

            $resolvedPath = $this->resolvePath($path);
            if ($resolvedPath === null || ! is_readable($resolvedPath)) {
                $errors[] = $prefix.'_CONTENT_MISSING';
            }

            if (! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                $errors[] = $prefix.'_HASH_INVALID';
            } elseif ($resolvedPath && is_readable($resolvedPath)) {
                $actualHash = hash_file('sha256', $resolvedPath);
                if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                    $errors[] = $prefix.'_HASH_MISMATCH';
                }
            }

            if ($effectiveAt && $version !== '' && $resolvedPath !== null) {
                $validEntries[] = [
                    'version' => $version,
                    'effective_at' => $effectiveAt->utc()->toIso8601String(),
                    'url' => $url,
                    'content_path' => $resolvedPath,
                    'content_hash' => $expectedHash,
                    '_effective_at' => $effectiveAt->utc(),
                ];
            }
        }

        $instant = CarbonImmutable::instance($at ?? now('UTC'))->utc();
        $current = collect($validEntries)
            ->filter(fn (array $entry): bool => $entry['_effective_at']->lessThanOrEqualTo($instant))
            ->sortByDesc(fn (array $entry): int => $entry['_effective_at']->getTimestamp())
            ->first();

        if (! $current) {
            $errors[] = 'PAYMENT_TERMS_NOT_EFFECTIVE';
        } else {
            unset($current['_effective_at']);
        }

        return [
            'valid' => $errors === [],
            'current' => $errors === [] ? $current : null,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function resolvePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
