<?php

namespace App\Services\Customers;

use App\Models\AccountingAuditLog;
use App\Models\User;

class CustomerMatchingRecoveryService
{
    public function __construct(
        private readonly CustomerMatchingService $matching,
        private readonly CustomerMatchingDispatchService $dispatches,
    ) {}

    /** @return array{examined:int,dispatched:int,disabled:bool} */
    public function dispatchMissing(int $limit = 100): array
    {
        if (! (bool) config('customers.matching_enabled', false)) {
            return ['examined' => 0, 'dispatched' => 0, 'disabled' => true];
        }

        $limit = max(1, min(100, $limit));
        $dispatched = 0;
        $latestGenerationIds = AccountingAuditLog::query()
            ->selectRaw('MAX(id) as generation_id')
            ->whereIn('action', ['customer.identity.resolved', 'customer.matching.profile_changed'])
            ->whereNotNull('actor_id')
            ->whereNotNull('subject_id')
            ->groupBy('actor_id');
        $generations = AccountingAuditLog::query()
            ->from('accounting_audit_logs as generation')
            ->joinSub($latestGenerationIds, 'latest_generation', fn ($join) => $join->on('latest_generation.generation_id', '=', 'generation.id'))
            ->join('users as portal_user', function ($join): void {
                $join->on('portal_user.id', '=', 'generation.actor_id')
                    ->on('portal_user.customer_id', '=', 'generation.subject_id');
            })
            ->join('customers as linked_customer', 'linked_customer.id', '=', 'portal_user.customer_id')
            ->where('portal_user.status', 'active')
            ->where('linked_customer.is_active', true)
            ->whereNull('linked_customer.merged_into_customer_id')
            ->whereNotExists(function ($completion): void {
                $completion->selectRaw('1')
                    ->from('accounting_audit_logs as completed_scan')
                    ->where('completed_scan.action', 'customer.matching.scan_completed')
                    ->whereColumn('completed_scan.actor_id', 'generation.actor_id')
                    ->whereColumn('completed_scan.subject_id', 'generation.subject_id')
                    ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(completed_scan.payload, '$.profile_fingerprint')) = BINARY JSON_UNQUOTE(JSON_EXTRACT(generation.payload, '$.profile_fingerprint'))");
            })
            ->orderBy('generation.id')
            ->limit($limit)
            ->get(['generation.actor_id', 'generation.payload']);

        foreach ($generations as $generation) {
            $payload = is_array($generation->payload)
                ? $generation->payload
                : json_decode((string) $generation->payload, true);
            $fingerprint = is_array($payload) ? ($payload['profile_fingerprint'] ?? null) : null;
            if (! is_string($fingerprint) || strlen($fingerprint) !== 64) {
                continue;
            }
            $user = User::query()->with('customer')->find((int) $generation->actor_id);
            if (! $user || ! $user->isActive() || ! $user->isCustomerPortalUser() || ! $user->customer) {
                continue;
            }
            if (! hash_equals($this->matching->profileFingerprint($user, $user->customer), $fingerprint)) {
                continue;
            }

            if ($this->dispatches->dispatchScan((int) $user->id, $fingerprint)) {
                $dispatched++;
            }
        }

        return ['examined' => $generations->count(), 'dispatched' => $dispatched, 'disabled' => false];
    }
}
