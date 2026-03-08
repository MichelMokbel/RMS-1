<?php

namespace App\Services\POS;

use App\Models\PosPrintJob;
use App\Models\PosPrintStreamEvent;
use App\Models\PosTerminal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosPrintJobService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{job:PosPrintJob,target_terminal:PosTerminal,created:bool}
     */
    public function enqueue(PosTerminal $sourceTerminal, array $data, ?int $actorId = null): array
    {
        $clientJobId = trim((string) $data['client_job_id']);
        $targetTerminalCode = trim((string) $data['target_terminal_code']);
        $target = trim((string) $data['target']);
        $docType = trim((string) $data['doc_type']);
        $payloadBase64 = (string) $data['payload_base64'];
        $metadata = (array) ($data['metadata'] ?? []);
        $clientCreatedAt = isset($data['created_at']) ? Carbon::parse((string) $data['created_at']) : null;
        $maxAttempts = max(1, min(20, (int) config('pos.print_jobs.max_attempts', 5)));

        $targetTerminal = $this->resolveTargetTerminal(
            branchId: (int) $sourceTerminal->branch_id,
            terminalCode: $targetTerminalCode
        );

        $existing = PosPrintJob::query()
            ->where('source_terminal_id', (int) $sourceTerminal->id)
            ->where('client_job_id', $clientJobId)
            ->first();

        if ($existing) {
            $this->guardIdempotencyCompatibility($existing, (int) $targetTerminal->id);

            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'idempotent_hit',
                'job_id' => (int) $existing->id,
                'client_job_id' => $clientJobId,
                'source_terminal_id' => (int) $sourceTerminal->id,
                'target_terminal_id' => (int) $targetTerminal->id,
                'status' => (string) $existing->status,
            ]);

            return [
                'job' => $existing,
                'target_terminal' => $targetTerminal,
                'created' => false,
            ];
        }

        try {
            $job = DB::transaction(function () use (
                $sourceTerminal,
                $targetTerminal,
                $clientJobId,
                $target,
                $docType,
                $payloadBase64,
                $metadata,
                $clientCreatedAt,
                $maxAttempts,
                $actorId
            ) {
                $job = PosPrintJob::query()->create([
                    'client_job_id' => $clientJobId,
                    'source_terminal_id' => (int) $sourceTerminal->id,
                    'branch_id' => (int) $targetTerminal->branch_id,
                    'target_terminal_id' => (int) $targetTerminal->id,
                    'target' => $target,
                    'doc_type' => $docType,
                    'payload_base64' => $payloadBase64,
                    'client_created_at' => $clientCreatedAt,
                    'job_type' => $docType,
                    'payload' => [
                        'target' => $target,
                        'doc_type' => $docType,
                        'payload_base64' => $payloadBase64,
                    ],
                    'metadata' => $metadata,
                    'status' => PosPrintJob::STATUS_QUEUED,
                    'attempt_count' => 0,
                    'max_attempts' => $maxAttempts,
                    'next_retry_at' => null,
                    'claimed_at' => null,
                    'claimed_by_terminal_id' => null,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'acked_at' => null,
                    'processing_ms' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'created_by' => $actorId,
                ]);

                $this->publishJobAvailableEvent($targetTerminal, 'enqueue');

                return $job;
            }, 3);

            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'created',
                'job_id' => (int) $job->id,
                'client_job_id' => $clientJobId,
                'source_terminal_id' => (int) $sourceTerminal->id,
                'target_terminal_id' => (int) $targetTerminal->id,
                'doc_type' => $docType,
            ]);

            return [
                'job' => $job,
                'target_terminal' => $targetTerminal,
                'created' => true,
            ];
        } catch (QueryException $e) {
            $job = PosPrintJob::query()
                ->where('source_terminal_id', (int) $sourceTerminal->id)
                ->where('client_job_id', $clientJobId)
                ->first();
            if (! $job) {
                throw $e;
            }

            $this->guardIdempotencyCompatibility($job, (int) $targetTerminal->id);

            logger()->info('pos_print_enqueue', [
                'event' => 'enqueue',
                'result' => 'idempotent_race_hit',
                'job_id' => (int) $job->id,
                'client_job_id' => $clientJobId,
                'source_terminal_id' => (int) $sourceTerminal->id,
                'target_terminal_id' => (int) $targetTerminal->id,
                'status' => (string) $job->status,
            ]);

            return [
                'job' => $job,
                'target_terminal' => $targetTerminal,
                'created' => false,
            ];
        }
    }

    /**
     * @return array<int, PosPrintJob>
     */
    public function pull(PosTerminal $terminal, ?int $waitSeconds = null, ?int $limit = null): array
    {
        $terminalId = (int) $terminal->id;
        $waitSeconds = max(0, min(60, (int) ($waitSeconds ?? 0)));
        $limit = max(1, min(100, (int) ($limit ?? 20)));
        $sleepMs = max(25, min(5000, (int) config('pos.print_jobs.pull_idle_sleep_ms', 250)));
        $deadline = microtime(true) + $waitSeconds;

        $this->touchPrintAgentHeartbeat($terminal, 'pull');
        logger()->info('pos_print_pull', [
            'event' => 'pull',
            'result' => 'started',
            'terminal_id' => $terminalId,
            'wait_seconds' => $waitSeconds,
            'limit' => $limit,
        ]);

        do {
            $this->reclaimExpiredClaims($terminalId);
            $jobs = $this->claimNextAvailableJobs($terminalId, $limit);

            if ($jobs !== []) {
                logger()->info('pos_print_pull', [
                    'event' => 'pull',
                    'result' => 'claimed',
                    'terminal_id' => $terminalId,
                    'count' => count($jobs),
                    'job_ids' => array_map(static fn (PosPrintJob $j) => (int) $j->id, $jobs),
                ]);

                return $jobs;
            }

            if ($waitSeconds === 0 || microtime(true) >= $deadline) {
                break;
            }

            usleep($sleepMs * 1000);
        } while (true);

        logger()->info('pos_print_pull', [
            'event' => 'pull',
            'result' => 'empty',
            'terminal_id' => $terminalId,
            'wait_seconds' => $waitSeconds,
        ]);

        return [];
    }

    /**
     * @param  array{claim_token:string,status:string,error_code?:string|null,error_message?:string|null,processing_ms?:int|null}  $data
     * @return array{job:PosPrintJob,final_status:string,next_retry_at:?string}
     */
    public function ack(PosTerminal $terminal, int $jobId, array $data): array
    {
        if ($jobId <= 0) {
            throw ValidationException::withMessages(['job_id' => 'Invalid print job id.']);
        }

        $terminalId = (int) $terminal->id;
        $claimToken = trim((string) $data['claim_token']);
        $status = trim((string) $data['status']);
        $errorCode = isset($data['error_code']) ? trim((string) $data['error_code']) : null;
        $errorMessage = isset($data['error_message']) ? trim((string) $data['error_message']) : null;
        $processingMs = isset($data['processing_ms']) ? (int) $data['processing_ms'] : null;

        $result = DB::transaction(function () use (
            $terminalId,
            $jobId,
            $claimToken,
            $status,
            $errorCode,
            $errorMessage,
            $processingMs
        ) {
            $job = PosPrintJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job) {
                throw ValidationException::withMessages(['job_id' => 'Print job not found.']);
            }

            if ((int) $job->target_terminal_id !== $terminalId) {
                throw ValidationException::withMessages(['job_id' => 'Print job does not belong to this terminal.']);
            }

            if ((string) $job->status !== PosPrintJob::STATUS_CLAIMED) {
                throw ValidationException::withMessages(['job_id' => 'Print job is not claimed.']);
            }

            if ((string) ($job->claim_token ?? '') !== $claimToken) {
                throw ValidationException::withMessages(['claim_token' => 'Invalid claim token.']);
            }

            if ($job->claim_expires_at && $job->claim_expires_at->isPast()) {
                throw ValidationException::withMessages(['claim_token' => 'Claim token expired.']);
            }

            if ($status === PosPrintJob::STATUS_PRINTED) {
                $job->forceFill([
                    'status' => PosPrintJob::STATUS_PRINTED,
                    'acked_at' => now(),
                    'processing_ms' => $processingMs,
                    'claimed_at' => null,
                    'claimed_by_terminal_id' => null,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'next_retry_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                logger()->info('pos_print_ack', [
                    'event' => 'ack',
                    'result' => 'printed',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => (int) $job->attempt_count,
                    'processing_ms' => $processingMs,
                ]);

                return [
                    'job' => $job,
                    'final_status' => PosPrintJob::STATUS_PRINTED,
                    'next_retry_at' => null,
                ];
            }

            $attemptCount = (int) $job->attempt_count;
            $maxAttempts = max(1, (int) $job->max_attempts);
            $nextRetryAt = null;

            if ($attemptCount >= $maxAttempts) {
                $job->forceFill([
                    'status' => PosPrintJob::STATUS_FAILED,
                    'acked_at' => now(),
                    'processing_ms' => $processingMs,
                    'claimed_at' => null,
                    'claimed_by_terminal_id' => null,
                    'claim_token' => null,
                    'claim_expires_at' => null,
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
            } else {
                $nextRetryAt = now()->addSeconds($this->retryBackoffSeconds($attemptCount));

                $job->forceFill([
                    'status' => PosPrintJob::STATUS_QUEUED,
                    'acked_at' => now(),
                    'processing_ms' => $processingMs,
                    'claimed_at' => null,
                    'claimed_by_terminal_id' => null,
                    'claim_token' => null,
                    'claim_expires_at' => null,
                    'next_retry_at' => $nextRetryAt,
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
                    'next_retry_at' => $nextRetryAt->toISOString(),
                    'error_code' => (string) ($job->last_error_code ?? ''),
                ]);
            }

            return [
                'job' => $job,
                'final_status' => PosPrintJob::STATUS_FAILED,
                'next_retry_at' => $nextRetryAt?->toISOString(),
            ];
        }, 3);

        $this->touchPrintAgentHeartbeat($terminal, 'ack');
        /** @var PosPrintJob $job */
        $job = $result['job']->fresh() ?? $result['job'];

        return [
            'job' => $job,
            'final_status' => (string) $result['final_status'],
            'next_retry_at' => $result['next_retry_at'],
        ];
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

    /**
     * @return Collection<int, PosPrintStreamEvent>
     */
    public function streamEventsSince(int $terminalId, int $afterId, int $limit = 50): Collection
    {
        return PosPrintStreamEvent::query()
            ->where('terminal_id', $terminalId)
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    public function pruneStreamEvents(int $olderThanHours): int
    {
        $cutoff = now()->subHours(max(1, $olderThanHours));

        return PosPrintStreamEvent::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    public function pendingJobsCount(int $terminalId): int
    {
        return PosPrintJob::query()
            ->where('target_terminal_id', $terminalId)
            ->where(function ($query): void {
                $query->where('status', PosPrintJob::STATUS_QUEUED)
                    ->orWhere(function ($expired): void {
                        $expired->where('status', PosPrintJob::STATUS_CLAIMED)
                            ->whereNotNull('claim_expires_at')
                            ->where('claim_expires_at', '<', now());
                    });
            })
            ->count();
    }

    public function publishJobAvailableEvent(PosTerminal $terminal, string $reason): PosPrintStreamEvent
    {
        $payload = [
            'terminal_code' => (string) $terminal->code,
            'pending_jobs' => $this->pendingJobsCount((int) $terminal->id),
            'reason' => $reason,
            'server_time' => now()->utc()->toISOString(),
        ];

        $event = PosPrintStreamEvent::query()->create([
            'terminal_id' => (int) $terminal->id,
            'event_type' => PosPrintStreamEvent::EVENT_JOB_AVAILABLE,
            'payload_json' => $payload,
            'created_at' => now(),
        ]);

        logger()->info('pos_print_stream_event_publish', [
            'event' => 'stream_publish',
            'event_type' => PosPrintStreamEvent::EVENT_JOB_AVAILABLE,
            'stream_event_id' => (int) $event->id,
            'terminal_id' => (int) $terminal->id,
            'payload' => $payload,
        ]);

        return $event;
    }

    private function resolveTargetTerminal(int $branchId, string $terminalCode): PosTerminal
    {
        $terminal = PosTerminal::query()
            ->where('branch_id', $branchId)
            ->where('code', $terminalCode)
            ->first();

        if (! $terminal) {
            throw ValidationException::withMessages([
                'target_terminal_code' => 'Target terminal was not found for the current branch.',
            ]);
        }

        if (! (bool) $terminal->active) {
            throw ValidationException::withMessages([
                'target_terminal_code' => 'Target terminal is inactive.',
            ]);
        }

        return $terminal;
    }

    private function guardIdempotencyCompatibility(PosPrintJob $job, int $targetTerminalId): void
    {
        if ((int) $job->target_terminal_id !== $targetTerminalId) {
            throw ValidationException::withMessages([
                'client_job_id' => 'client_job_id already exists for a different target terminal.',
            ]);
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
                'status' => PosPrintJob::STATUS_QUEUED,
                'claimed_at' => null,
                'claimed_by_terminal_id' => null,
                'claim_token' => null,
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

    /**
     * @return array<int, PosPrintJob>
     */
    private function claimNextAvailableJobs(int $terminalId, int $limit): array
    {
        return DB::transaction(function () use ($terminalId, $limit) {
            $now = now();
            $claimTtlSeconds = max(5, min(300, (int) config('pos.print_jobs.claim_ttl_seconds', 45)));

            $jobs = PosPrintJob::query()
                ->where('target_terminal_id', $terminalId)
                ->where('status', PosPrintJob::STATUS_QUEUED)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', $now);
                })
                ->orderByRaw('case when next_retry_at is null then 0 else 1 end')
                ->orderBy('next_retry_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit($limit)
                ->get();

            if ($jobs->isEmpty()) {
                return [];
            }

            $claimedIds = [];
            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => PosPrintJob::STATUS_CLAIMED,
                    'attempt_count' => (int) $job->attempt_count + 1,
                    'claimed_at' => $now,
                    'claimed_by_terminal_id' => $terminalId,
                    'claim_token' => Str::random(48),
                    'claim_expires_at' => $now->copy()->addSeconds($claimTtlSeconds),
                    'next_retry_at' => null,
                ])->save();

                logger()->info('pos_print_claim', [
                    'event' => 'claim',
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => (int) $job->attempt_count,
                    'claim_expires_at' => optional($job->claim_expires_at)->toISOString(),
                ]);

                $claimedIds[] = (int) $job->id;
            }

            return PosPrintJob::query()
                ->whereIn('id', $claimedIds)
                ->orderBy('id')
                ->get()
                ->all();
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
