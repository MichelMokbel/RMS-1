<?php

namespace App\Services\Payments\Consistency;

class ConsistencyEvidence
{
    /** @param array<int, array<string, mixed>> $issues */
    public static function result(
        int $companyId,
        ?int $branchId,
        ?int $checkoutId,
        array $expected,
        array $observed,
        array $evidence,
        array $issues,
        bool $deferred = false,
    ): array {
        usort($issues, fn (array $left, array $right): int => [
            (string) ($left['code'] ?? ''),
            (string) ($left['subject_type'] ?? ''),
            (int) ($left['subject_id'] ?? 0),
        ] <=> [
            (string) ($right['code'] ?? ''),
            (string) ($right['subject_type'] ?? ''),
            (int) ($right['subject_id'] ?? 0),
        ]);
        $observed['issues'] = $issues;

        return [
            'healthy' => ! $deferred && $issues === [],
            'deferred' => $deferred,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'checkout_id' => $checkoutId,
            'expected' => self::normalize($expected),
            'observed' => self::normalize($observed),
            'evidence_fingerprint' => hash('sha256', json_encode(
                self::normalize($evidence),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
        ];
    }

    /** @return array{code:string,subject_type:string,subject_id:int} */
    public static function issue(string $code, string $subjectType, int $subjectId): array
    {
        return [
            'code' => substr(strtoupper((string) preg_replace('/[^A-Z0-9_]/', '_', $code)), 0, 80),
            'subject_type' => substr($subjectType, 0, 80),
            'subject_id' => $subjectId,
        ];
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value);

        return array_map(self::normalize(...), $value);
    }
}
