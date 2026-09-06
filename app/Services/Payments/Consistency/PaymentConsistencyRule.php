<?php

namespace App\Services\Payments\Consistency;

interface PaymentConsistencyRule
{
    /** @return array<int, string> */
    public function ruleCodes(): array;

    /** @return array{company_id:int,branch_id:int|null,checkout_id:int|null} */
    public function scope(string $ruleCode, string $subjectType, int $subjectId): array;

    /**
     * @return array{
     *     healthy:bool,
     *     deferred:bool,
     *     company_id:int,
     *     branch_id:int|null,
     *     checkout_id:int|null,
     *     expected:array<string,mixed>,
     *     observed:array<string,mixed>,
     *     evidence_fingerprint:string
     * }
     */
    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array;
}
