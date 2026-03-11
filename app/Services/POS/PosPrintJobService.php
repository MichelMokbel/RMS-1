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

            if ($this->isStreamActive($targetTerminal)) {
                $this->dispatchPendingJobsForTerminal($targetTerminal, source: 'enqueue.idempotent');
            }

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
                return PosPrintJob::query()->create([
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

            if ($this->isStreamActive($targetTerminal)) {
                $this->dispatchPendingJobsForTerminal($targetTerminal, source: 'enqueue');
            }

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

            if ($this->isStreamActive($targetTerminal)) {
                $this->dispatchPendingJobsForTerminal($targetTerminal, source: 'enqueue.idempotent_race');
            }

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

            $ackLatencyMs = $job->claimed_at ? (int) $job->claimed_at->diffInMilliseconds(now()) : null;

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
                    'ack_latency_ms' => $ackLatencyMs,
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
                    'ack_latency_ms' => $ackLatencyMs,
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
                    'ack_latency_ms' => $ackLatencyMs,
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

    public function touchPrintStreamHeartbeat(PosTerminal $terminal, string $source): void
    {
        $heartbeatAt = now();
        $terminal->forceFill(['print_stream_seen_at' => $heartbeatAt])->save();

        logger()->info('pos_print_stream_heartbeat', [
            'event' => 'stream_heartbeat',
            'source' => $source,
            'terminal_id' => (int) $terminal->id,
            'branch_id' => (int) $terminal->branch_id,
            'print_stream_seen_at' => $heartbeatAt->toISOString(),
        ]);
    }

    public function isStreamActive(PosTerminal|int $terminal): bool
    {
        $terminalId = $terminal instanceof PosTerminal ? (int) $terminal->id : (int) $terminal;
        if ($terminalId <= 0) {
            return false;
        }

        $windowSeconds = max(3, min(120, (int) config('pos.print_jobs.stream_active_window_seconds', 20)));
        $threshold = now()->subSeconds($windowSeconds);
        $streamSeenAt = PosTerminal::query()->whereKey($terminalId)->value('print_stream_seen_at');

        if (! $streamSeenAt) {
            return false;
        }

        $seenAt = $streamSeenAt instanceof Carbon ? $streamSeenAt : Carbon::parse((string) $streamSeenAt);

        return $seenAt->greaterThanOrEqualTo($threshold);
    }

    public function dispatchPendingJobsForTerminal(PosTerminal $terminal, ?int $limit = null, string $source = 'stream.tick'): int
    {
        $terminalId = (int) $terminal->id;
        $limit = max(1, min(100, (int) ($limit ?? config('pos.print_jobs.stream_dispatch_batch_size', 20))));

        $reclaimed = $this->reclaimExpiredClaims($terminalId);
        $pushed = $this->claimAndPublishNextJobs($terminal, $limit, $source);

        if ($reclaimed > 0 || $pushed > 0) {
            logger()->info('pos_print_push_dispatch', [
                'event' => 'push_dispatch',
                'terminal_id' => $terminalId,
                'source' => $source,
                'reclaimed_count' => $reclaimed,
                'pushed_count' => $pushed,
            ]);
        }

        return $pushed;
    }

    /**
     * @return array{events:Collection<int, PosPrintStreamEvent>,max_event_id:int}
     */
    public function streamEventsSince(int $terminalId, int $afterId, int $limit = 50): array
    {
        $rawEvents = PosPrintStreamEvent::query()
            ->where('terminal_id', $terminalId)
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get();

        if ($rawEvents->isEmpty()) {
            return [
                'events' => collect(),
                'max_event_id' => max(0, $afterId),
            ];
        }

        $maxEventId = (int) ($rawEvents->max('id') ?? $afterId);

        $jobIds = $rawEvents
            ->filter(static fn (PosPrintStreamEvent $event): bool => (string) $event->event_type === PosPrintStreamEvent::EVENT_JOB)
            ->pluck('job_id')
            ->filter(static fn ($id): bool => (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $jobsById = PosPrintJob::query()
            ->whereIn('id', $jobIds)
            ->get()
            ->keyBy(static fn (PosPrintJob $job): int => (int) $job->id);

        $now = now();

        $filtered = $rawEvents
            ->filter(function (PosPrintStreamEvent $event) use ($terminalId, $jobsById, $now): bool {
                if ((string) $event->event_type !== PosPrintStreamEvent::EVENT_JOB) {
                    return true;
                }

                $jobId = (int) ($event->job_id ?? 0);
                $claimToken = (string) ($event->claim_token ?? '');
                if ($jobId <= 0 || $claimToken === '') {
                    return false;
                }

                /** @var PosPrintJob|null $job */
                $job = $jobsById->get($jobId);
                if (! $job) {
                    return false;
                }

                if ((int) $job->target_terminal_id !== $terminalId) {
                    return false;
                }

                if ((string) $job->status !== PosPrintJob::STATUS_CLAIMED) {
                    return false;
                }

                if ((string) ($job->claim_token ?? '') !== $claimToken) {
                    return false;
                }

                if ($job->claim_expires_at && $job->claim_expires_at->lt($now)) {
                    return false;
                }

                return true;
            })
            ->values();

        return [
            'events' => $filtered,
            'max_event_id' => $maxEventId,
        ];
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

            $jobs = $this->claimableJobsQuery($terminalId, $now)
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
                    'mode' => 'pull',
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

    private function claimAndPublishNextJobs(PosTerminal $terminal, int $limit, string $source): int
    {
        return DB::transaction(function () use ($terminal, $limit, $source): int {
            $terminalId = (int) $terminal->id;
            $now = now();
            $claimTtlSeconds = max(5, min(300, (int) config('pos.print_jobs.claim_ttl_seconds', 45)));

            $jobs = $this->claimableJobsQuery($terminalId, $now)
                ->lockForUpdate()
                ->limit($limit)
                ->get();

            if ($jobs->isEmpty()) {
                return 0;
            }

            $pushedCount = 0;
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
                    'mode' => 'push',
                    'source' => $source,
                ]);

                $event = $this->createJobStreamEvent($terminal, $job, $source, $now);
                $pushedCount++;

                logger()->info('pos_print_stream_event_publish', [
                    'event' => 'stream_publish',
                    'event_type' => PosPrintStreamEvent::EVENT_JOB,
                    'stream_event_id' => (int) $event->id,
                    'terminal_id' => $terminalId,
                    'job_id' => (int) $job->id,
                    'attempt_count' => (int) $job->attempt_count,
                    'source' => $source,
                ]);
            }

            return $pushedCount;
        }, 3);
    }

    private function createJobStreamEvent(PosTerminal $terminal, PosPrintJob $job, string $source, Carbon $serverNow): PosPrintStreamEvent
    {
        $payload = [
            'job_id' => (int) $job->id,
            'client_job_id' => (string) ($job->client_job_id ?? ''),
            'target_terminal_code' => (string) $terminal->code,
            'target' => (string) ($job->target ?? ''),
            'doc_type' => (string) ($job->doc_type ?? ''),
            'payload_base64' => (string) ($job->payload_base64 ?? ''),
            'metadata' => (array) ($job->metadata ?? []),
            'claim_token' => (string) ($job->claim_token ?? ''),
            'attempt_count' => (int) $job->attempt_count,
            'claim_expires_at' => optional($job->claim_expires_at)->utc()?->toISOString(),
            'queued_at' => optional($job->created_at)->utc()?->toISOString(),
            'server_time' => $serverNow->copy()->utc()->toISOString(),
        ];

        return PosPrintStreamEvent::query()->create([
            'terminal_id' => (int) $terminal->id,
            'job_id' => (int) $job->id,
            'claim_token' => (string) ($job->claim_token ?? ''),
            'event_type' => PosPrintStreamEvent::EVENT_JOB,
            'payload_json' => $payload,
            'created_at' => $serverNow,
        ]);
    }

    private function claimableJobsQuery(int $terminalId, Carbon $now)
    {
        return PosPrintJob::query()
            ->where('target_terminal_id', $terminalId)
            ->where('status', PosPrintJob::STATUS_QUEUED)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
            })
            ->orderByRaw('case when next_retry_at is null then 0 else 1 end')
            ->orderBy('next_retry_at')
            ->orderBy('created_at');
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
