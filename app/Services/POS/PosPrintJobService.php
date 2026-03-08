<?php

namespace App\Services\POS;

use App\Models\PosPrintJob;
use App\Models\PosTerminal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PosPrintJobService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{job:PosPrintJob,created:bool,idempotent:bool}
     */
    public function enqueue(array $data, ?int $actorId = null): array
    {
        $branchId = (int) $data['branch_id'];
        $terminalCode = (string) $data['target_terminal_code'];
        $clientJobId = (string) $data['client_job_id'];
        $jobType = (string) ($data['job_type'] ?? 'receipt');

        $this->validateBranchIsActive($branchId);
        $terminal = $this->resolveTargetTerminal($branchId, $terminalCode);
        $maxAttempts = (int) ($data['max_attempts'] ?? config('pos.print_jobs.max_attempts', 5));
        $maxAttempts = max(1, min(20, $maxAttempts));

        $existing = PosPrintJob::query()->where('client_job_id', $clientJobId)->first();
        if ($existing) {
            $this->guardIdempotencyCompatibility($existing, $branchId, (int) $terminal->id);
            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'idempotent_hit',
                'job_id' => (int) $existing->id,
                'client_job_id' => $clientJobId,
                'branch_id' => $branchId,
                'target_terminal_id' => (int) $terminal->id,
                'status' => (string) $existing->status,
            ]);

            return ['job' => $existing, 'created' => false, 'idempotent' => true];
        }

        try {
            $job = PosPrintJob::query()->create([
                'client_job_id' => $clientJobId,
                'branch_id' => $branchId,
                'target_terminal_id' => (int) $terminal->id,
                'job_type' => $jobType,
                'payload' => (array) $data['payload'],
                'metadata' => (array) ($data['metadata'] ?? []),
                'status' => PosPrintJob::STATUS_PENDING,
                'attempt_count' => 0,
                'max_attempts' => $maxAttempts,
                'next_retry_at' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'acked_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'created_by' => $actorId,
            ]);

            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'created',
                'job_id' => (int) $job->id,
                'client_job_id' => $clientJobId,
                'branch_id' => $branchId,
                'target_terminal_id' => (int) $terminal->id,
                'job_type' => $jobType,
                'max_attempts' => $maxAttempts,
            ]);

            return ['job' => $job, 'created' => true, 'idempotent' => false];
        } catch (QueryException $e) {
            $job = PosPrintJob::query()->where('client_job_id', $clientJobId)->first();
            if (! $job) {
                throw $e;
            }

            $this->guardIdempotencyCompatibility($job, $branchId, (int) $terminal->id);
            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'idempotent_race_hit',
                'job_id' => (int) $job->id,
                'client_job_id' => $clientJobId,
                'branch_id' => $branchId,
                'target_terminal_id' => (int) $terminal->id,
                'status' => (string) $job->status,
            ]);

            return ['job' => $job, 'created' => false, 'idempotent' => true];
        }
    }

    public function pull(PosTerminal $terminal, ?int $waitSeconds = null): ?PosPrintJob
    {
        $terminalId = (int) $terminal->id;
        $waitSeconds = max(1, min(60, (int) ($waitSeconds ?? config('pos.print_jobs.pull_wait_seconds', 20))));
        $sleepMs = max(50, min(5000, (int) config('pos.print_jobs.pull_idle_sleep_ms', 250)));
        $deadline = microtime(true) + $waitSeconds;

        $this->touchPrintAgentHeartbeat($terminal, 'pull');
        logger()->info('pos_print_pull', [
            'event' => 'pull',
            'result' => 'started',
            'terminal_id' => $terminalId,
            'wait_seconds' => $waitSeconds,
        ]);

        do {
            $this->reclaimExpiredClaims($terminalId);
            $job = $this->claimNextAvailableJob($terminalId);
            if ($job) {
                logger()->info('pos_print_pull', [
                    'event' => 'pull',
                    'result' => 'claimed',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => (int) $job->attempt_count,
                    'claim_expires_at' => optional($job->claim_expires_at)->toISOString(),
                ]);

                return $job;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep($sleepMs * 1000);
        } while (true);

        logger()->info('pos_print_pull', [
            'event' => 'pull',
            'result' => 'timeout',
            'terminal_id' => $terminalId,
            'wait_seconds' => $waitSeconds,
        ]);

        return null;
    }

    /**
     * @param  array{ok:bool,error_code?:string|null,error_message?:string|null}  $data
     */
    public function ack(PosTerminal $terminal, int $jobId, array $data): PosPrintJob
    {
        if ($jobId <= 0) {
            throw ValidationException::withMessages(['job_id' => 'Invalid print job id.']);
        }

        $terminalId = (int) $terminal->id;
        $ok = (bool) ($data['ok'] ?? false);
        $errorCode = isset($data['error_code']) ? trim((string) $data['error_code']) : null;
        $errorMessage = isset($data['error_message']) ? trim((string) $data['error_message']) : null;

        $job = DB::transaction(function () use ($terminalId, $jobId, $ok, $errorCode, $errorMessage) {
            $job = PosPrintJob::query()->whereKey($jobId)->lockForUpdate()->first();

            if (! $job) {
                throw ValidationException::withMessages(['job_id' => 'Print job not found.']);
            }

            if ((int) $job->target_terminal_id !== $terminalId) {
                throw ValidationException::withMessages(['job_id' => 'Print job does not belong to this terminal.']);
            }

            if (in_array((string) $job->status, [PosPrintJob::STATUS_COMPLETED, PosPrintJob::STATUS_FAILED], true)) {
                logger()->info('pos_print_ack', [
                    'event' => 'ack',
                    'result' => 'duplicate',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'status' => (string) $job->status,
                ]);

                return $job;
            }

            if ((string) $job->status !== PosPrintJob::STATUS_CLAIMED) {
                throw ValidationException::withMessages(['job_id' => 'Print job is not currently claimed.']);
            }

            if ($ok) {
                $job->forceFill([
                    'status' => PosPrintJob::STATUS_COMPLETED,
                    'acked_at' => now(),
                    'claim_expires_at' => null,
                    'claimed_at' => null,
                    'next_retry_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                logger()->info('pos_print_ack', [
                    'event' => 'ack',
                    'result' => 'completed',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => (int) $job->attempt_count,
                ]);

                return $job;
            }

            $attemptCount = (int) $job->attempt_count;
            $maxAttempts = max(1, (int) $job->max_attempts);

            if ($attemptCount >= $maxAttempts) {
                $job->forceFill([
                    'status' => PosPrintJob::STATUS_FAILED,
                    'acked_at' => now(),
                    'claim_expires_at' => null,
                    'claimed_at' => null,
                    'next_retry_at' => null,
                    'last_error_code' => $errorCode ?: 'PRINT_FAILED',
                    'last_error_message' => $errorMessage ?: 'Print failed and max attempts reached.',
                ])->save();

                logger()->info('pos_print_ack', [
                    'event' => 'ack',
                    'result' => 'failed_terminal',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => $attemptCount,
                    'max_attempts' => $maxAttempts,
                    'error_code' => (string) ($job->last_error_code ?? ''),
                ]);

                return $job;
            }

            $retryAt = now()->addSeconds($this->retryBackoffSeconds($attemptCount));
            $job->forceFill([
                'status' => PosPrintJob::STATUS_PENDING,
                'acked_at' => now(),
                'claim_expires_at' => null,
                'claimed_at' => null,
                'next_retry_at' => $retryAt,
                'last_error_code' => $errorCode ?: 'PRINT_FAILED',
                'last_error_message' => $errorMessage ?: 'Print failed.',
            ])->save();

            logger()->info('pos_print_retry', [
                'event' => 'retry',
                'result' => 'scheduled',
                'terminal_id' => $terminalId,
                'job_id' => (int) $job->id,
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
                'next_retry_at' => $retryAt->toISOString(),
                'error_code' => (string) ($job->last_error_code ?? ''),
            ]);

            return $job;
        }, 3);

        $this->touchPrintAgentHeartbeat($terminal, 'ack');

        return $job->fresh() ?? $job;
    }

    public function touchPrintAgentHeartbeat(PosTerminal $terminal, string $source): void
    {
        $heartbeatAt = now();
        $terminal->forceFill(['print_agent_seen_at' => $heartbeatAt])->save();

        logger()->info('pos_print_terminal_heartbeat', [
            'event' => 'terminal_heartbeat',
            'source' => $source,
            'terminal_id' => (int) $terminal->id,
            'branch_id' => (int) $terminal->branch_id,
            'print_agent_seen_at' => $heartbeatAt->toISOString(),
        ]);
    }

    private function validateBranchIsActive(int $branchId): void
    {
        if ($branchId <= 0) {
            throw ValidationException::withMessages(['branch_id' => 'Invalid branch id.']);
        }

        if (! Schema::hasTable('branches')) {
            return;
        }

        $query = DB::table('branches')->where('id', $branchId);
        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', 1);
        }

        if (! $query->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Selected branch is not active.']);
        }
    }

    private function resolveTargetTerminal(int $branchId, string $terminalCode): PosTerminal
    {
        $terminal = PosTerminal::query()
            ->where('branch_id', $branchId)
            ->where('code', $terminalCode)
            ->first();

        if (! $terminal) {
            throw ValidationException::withMessages(['target_terminal_code' => 'Target terminal was not found for the given branch.']);
        }

        if (! (bool) $terminal->active) {
            throw ValidationException::withMessages(['target_terminal_code' => 'Target terminal is inactive.']);
        }

        return $terminal;
    }

    private function guardIdempotencyCompatibility(PosPrintJob $job, int $branchId, int $terminalId): void
    {
        if ((int) $job->branch_id !== $branchId || (int) $job->target_terminal_id !== $terminalId) {
            throw ValidationException::withMessages(['client_job_id' => 'client_job_id already exists for a different branch or terminal.']);
        }
    }

    private function reclaimExpiredClaims(int $terminalId): int
    {
        $now = now();
        $reclaimed = PosPrintJob::query()
            ->where('target_terminal_id', $terminalId)
            ->where('status', PosPrintJob::STATUS_CLAIMED)
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<', $now)
            ->update([
                'status' => PosPrintJob::STATUS_PENDING,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'next_retry_at' => $now,
                'updated_at' => $now,
            ]);

        if ($reclaimed > 0) {
            logger()->info('pos_print_claim_reclaim', [
                'event' => 'claim_reclaim',
                'terminal_id' => $terminalId,
                'reclaimed_count' => $reclaimed,
            ]);
        }

        return $reclaimed;
    }

    private function claimNextAvailableJob(int $terminalId): ?PosPrintJob
    {
        return DB::transaction(function () use ($terminalId) {
            $now = now();

            $job = PosPrintJob::query()
                ->where('target_terminal_id', $terminalId)
                ->where('status', PosPrintJob::STATUS_PENDING)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', $now);
                })
                ->orderByRaw('case when next_retry_at is null then 0 else 1 end')
                ->orderBy('next_retry_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            $claimTtlSeconds = max(5, min(300, (int) config('pos.print_jobs.claim_ttl_seconds', 45)));
            $job->forceFill([
                'status' => PosPrintJob::STATUS_CLAIMED,
                'attempt_count' => (int) $job->attempt_count + 1,
                'claimed_at' => $now,
                'claim_expires_at' => Carbon::parse($now)->addSeconds($claimTtlSeconds),
                'next_retry_at' => null,
            ])->save();

            logger()->info('pos_print_claim', [
                'event' => 'claim',
                'terminal_id' => $terminalId,
                'job_id' => (int) $job->id,
                'attempt_count' => (int) $job->attempt_count,
                'claim_expires_at' => optional($job->claim_expires_at)->toISOString(),
            ]);

            return $job->fresh();
        }, 3);
    }

    private function retryBackoffSeconds(int $attemptCount): int
    {
        $base = max(1, min(60, (int) config('pos.print_jobs.retry_base_seconds', 2)));
        $max = max($base, min(3600, (int) config('pos.print_jobs.retry_max_seconds', 60)));
        $exp = max(0, $attemptCount - 1);
        $delay = $base * (2 ** $exp);

        return (int) min($max, $delay);
    }
}
