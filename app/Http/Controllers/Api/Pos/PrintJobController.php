<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\PrintJobAckRequest;
use App\Http\Requests\Api\Pos\PrintJobEnqueueRequest;
use App\Http\Requests\Api\Pos\PrintJobPullRequest;
use App\Models\PosPrintJob;
use App\Services\POS\PosPrintJobService;
use App\Services\Security\BranchAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PrintJobController extends Controller
{
    public function __construct(
        private readonly PosPrintJobService $jobs,
        private readonly BranchAccessService $branchAccess,
    ) {
    }

    public function store(PrintJobEnqueueRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $branchId = (int) $data['branch_id'];

        if (! $user || ! $this->branchAccess->canAccessBranch($user, $branchId)) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'BRANCH_FORBIDDEN'], 403);
        }

        $result = $this->jobs->enqueue($data, $user ? (int) $user->id : null);
        /** @var PosPrintJob $job */
        $job = $result['job'];
        $created = (bool) $result['created'];
        $idempotent = (bool) $result['idempotent'];

        return response()->json([
            'job' => $this->serializeJob($job),
            'created' => $created,
            'idempotent' => $idempotent,
        ], $created ? 201 : 200);
    }

    public function pull(PrintJobPullRequest $request): Response|JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $job = $this->jobs->pull($terminal, $request->validated('wait_seconds'));

        if (! $job) {
            return response()->noContent();
        }

        return response()->json([
            'job' => $this->serializeJob($job),
        ]);
    }

    public function ack(PrintJobAckRequest $request, int $job_id): JsonResponse
    {
        $terminal = $request->attributes->get('pos_terminal');
        if (! $terminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        $job = $this->jobs->ack($terminal, $job_id, $request->validated());

        $retryScheduled = (string) $job->status === PosPrintJob::STATUS_PENDING && $job->next_retry_at !== null;

        return response()->json([
            'job' => $this->serializeJob($job),
            'retry_scheduled' => $retryScheduled,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeJob(PosPrintJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'client_job_id' => (string) $job->client_job_id,
            'branch_id' => (int) $job->branch_id,
            'target_terminal_id' => (int) $job->target_terminal_id,
            'job_type' => (string) $job->job_type,
            'payload' => (array) ($job->payload ?? []),
            'metadata' => (array) ($job->metadata ?? []),
            'status' => (string) $job->status,
            'attempt_count' => (int) $job->attempt_count,
            'max_attempts' => (int) $job->max_attempts,
            'next_retry_at' => optional($job->next_retry_at)->toISOString(),
            'claimed_at' => optional($job->claimed_at)->toISOString(),
            'claim_expires_at' => optional($job->claim_expires_at)->toISOString(),
            'acked_at' => optional($job->acked_at)->toISOString(),
            'last_error_code' => $job->last_error_code !== null ? (string) $job->last_error_code : null,
            'last_error_message' => $job->last_error_message !== null ? (string) $job->last_error_message : null,
            'created_at' => optional($job->created_at)->toISOString(),
            'updated_at' => optional($job->updated_at)->toISOString(),
        ];
    }
}
