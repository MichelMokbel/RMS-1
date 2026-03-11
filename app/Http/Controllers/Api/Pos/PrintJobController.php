<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\PrintJobAckRequest;
use App\Http\Requests\Api\Pos\PrintJobEnqueueRequest;
use App\Http\Requests\Api\Pos\PrintJobPullRequest;
use App\Models\PosPrintJob;
use App\Models\PosPrintStreamEvent;
use App\Models\PosTerminal;
use App\Services\POS\PosPrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintJobController extends Controller
{
    public function __construct(
        private readonly PosPrintJobService $jobs,
    ) {
    }

    public function store(PrintJobEnqueueRequest $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal instanceof PosTerminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $result = $this->jobs->enqueue(
            sourceTerminal: $terminal,
            data: $request->validated(),
            actorId: $request->user() ? (int) $request->user()->id : null,
        );

        /** @var PosPrintJob $job */
        $job = $result['job'];
        /** @var PosTerminal $targetTerminal */
        $targetTerminal = $result['target_terminal'];

        return response()->json([
            'job_id' => (int) $job->id,
            'client_job_id' => (string) $job->client_job_id,
            'status' => (string) $job->status,
            'target_terminal_code' => (string) $targetTerminal->code,
            'queued_at' => optional($job->created_at)->utc()?->toISOString(),
        ]);
    }

    public function pull(PrintJobPullRequest $request): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal instanceof PosTerminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $jobs = $this->jobs->pull(
            terminal: $terminal,
            waitSeconds: $request->validated('wait_seconds'),
            limit: $request->validated('limit')
        );

        return response()->json([
            'jobs' => array_map(fn (PosPrintJob $job) => $this->serializePulledJob($job, $terminal), $jobs),
            'server_timestamp' => now()->utc()->toISOString(),
        ]);
    }

    public function ack(PrintJobAckRequest $request, int $job_id): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal instanceof PosTerminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $result = $this->jobs->ack($terminal, $job_id, $request->validated());
        /** @var PosPrintJob $job */
        $job = $result['job'];

        return response()->json([
            'job_id' => (int) $job->id,
            'final_status' => (string) $result['final_status'],
            'attempt_count' => (int) $job->attempt_count,
            'next_retry_at' => $result['next_retry_at'],
        ]);
    }

    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal instanceof PosTerminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $heartbeatSec = max(1, min(60, (int) config('pos.print_jobs.stream_heartbeat_seconds', 15)));
        $sleepMs = max(25, min(5000, (int) config('pos.print_jobs.stream_idle_sleep_ms', 250)));
        $batchSize = max(1, min(200, (int) config('pos.print_jobs.stream_event_batch_size', 50)));
        $dispatchBatchSize = max(1, min(100, (int) config('pos.print_jobs.stream_dispatch_batch_size', 20)));
        $maxSeconds = max(1, min(300, (int) config(
            'pos.print_jobs.stream_max_seconds',
            app()->environment('testing') ? 2 : 55
        )));
        $initialLastEventId = max(0, (int) $request->header('Last-Event-ID', 0));

        return response()->stream(function () use (
            $terminal,
            $heartbeatSec,
            $sleepMs,
            $batchSize,
            $dispatchBatchSize,
            $maxSeconds,
            $initialLastEventId
        ): void {
            ignore_user_abort(true);
            $lastEventId = $initialLastEventId;

            $this->jobs->touchPrintAgentHeartbeat($terminal, 'stream.connect');
            $this->jobs->touchPrintStreamHeartbeat($terminal, 'stream.connect');
            $pushedOnConnect = $this->jobs->dispatchPendingJobsForTerminal(
                $terminal,
                $dispatchBatchSize,
                'stream.connect'
            );

            $this->emitEvent('ready', [
                'terminal_code' => (string) $terminal->code,
                'heartbeat_sec' => $heartbeatSec,
                'server_time' => now()->utc()->toISOString(),
            ]);

            $this->flushOutput();

            logger()->info('pos_print_stream', [
                'event' => 'stream_connect',
                'terminal_id' => (int) $terminal->id,
                'terminal_code' => (string) $terminal->code,
                'last_event_id' => $initialLastEventId,
                'pushed_on_connect' => $pushedOnConnect,
            ]);

            $deadline = microtime(true) + $maxSeconds;
            $nextHeartbeatAt = microtime(true) + $heartbeatSec;

            while (! connection_aborted() && microtime(true) < $deadline) {
                $emitted = false;
                $this->jobs->dispatchPendingJobsForTerminal($terminal, $dispatchBatchSize, 'stream.tick');

                $batch = $this->jobs->streamEventsSince((int) $terminal->id, $lastEventId, $batchSize);
                /** @var array{events:\Illuminate\Support\Collection<int, PosPrintStreamEvent>,max_event_id:int} $batch */
                $events = $batch['events'];
                $maxEventId = (int) $batch['max_event_id'];
                $jobEventCount = 0;
                /** @var PosPrintStreamEvent $event */
                foreach ($events as $event) {
                    $payload = is_array($event->payload_json) ? $event->payload_json : [];
                    $this->emitEvent((string) $event->event_type, $payload, (int) $event->id);
                    $eventId = (int) $event->id;
                    if ($eventId > $lastEventId) {
                        $lastEventId = $eventId;
                    }
                    if ((string) $event->event_type === PosPrintStreamEvent::EVENT_JOB) {
                        $jobEventCount++;
                    }
                    $emitted = true;
                }

                if ($maxEventId > $lastEventId) {
                    $lastEventId = $maxEventId;
                }

                if ($initialLastEventId > 0 && $jobEventCount > 0) {
                    logger()->info('pos_print_stream_replay', [
                        'event' => 'replay',
                        'terminal_id' => (int) $terminal->id,
                        'terminal_code' => (string) $terminal->code,
                        'last_event_id_from_client' => $initialLastEventId,
                        'replayed_jobs' => $jobEventCount,
                    ]);
                }

                $nowMicros = microtime(true);
                if ($nowMicros >= $nextHeartbeatAt) {
                    $this->jobs->touchPrintAgentHeartbeat($terminal, 'stream.keepalive');
                    $this->jobs->touchPrintStreamHeartbeat($terminal, 'stream.keepalive');
                    $seenAt = optional($terminal->fresh()->print_agent_seen_at)->utc()?->toISOString();

                    $this->emitEvent('keepalive', [
                        'server_time' => now()->utc()->toISOString(),
                    ]);
                    $this->emitEvent('terminal_status', [
                        'terminal_code' => (string) $terminal->code,
                        'online' => true,
                        'print_agent_seen_at' => $seenAt,
                    ]);

                    $nextHeartbeatAt = $nowMicros + $heartbeatSec;
                    $emitted = true;
                }

                if ($emitted) {
                    $this->flushOutput();
                }

                usleep($sleepMs * 1000);
            }

            logger()->info('pos_print_stream', [
                'event' => 'stream_disconnect',
                'terminal_id' => (int) $terminal->id,
                'terminal_code' => (string) $terminal->code,
                'last_event_id' => $lastEventId,
                'aborted' => connection_aborted(),
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePulledJob(PosPrintJob $job, PosTerminal $terminal): array
    {
        return [
            'job_id' => (int) $job->id,
            'client_job_id' => (string) $job->client_job_id,
            'target_terminal_code' => (string) $terminal->code,
            'target' => (string) ($job->target ?? ''),
            'doc_type' => (string) ($job->doc_type ?? ''),
            'payload_base64' => (string) ($job->payload_base64 ?? ''),
            'created_at' => optional($job->client_created_at)->utc()?->toISOString(),
            'metadata' => (array) ($job->metadata ?? []),
            'claim_token' => (string) ($job->claim_token ?? ''),
            'attempt_count' => (int) $job->attempt_count,
            'claim_expires_at' => optional($job->claim_expires_at)->utc()?->toISOString(),
            'queued_at' => optional($job->created_at)->utc()?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitEvent(string $event, array $payload, ?int $eventId = null): void
    {
        if ($eventId !== null) {
            echo 'id: '.$eventId."\n";
        }
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
