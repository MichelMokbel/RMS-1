<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\PrintTerminalStatusRequest;
use App\Models\PosPrintJob;
use App\Models\PosTerminal;
use App\Services\POS\PosPrintJobService;
use App\Services\Security\BranchAccessService;
use Illuminate\Http\JsonResponse;

class PrintTerminalStatusController extends Controller
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly PosPrintJobService $jobs,
    ) {
    }

    public function show(PrintTerminalStatusRequest $request, string $terminal_code): JsonResponse
    {
        $user = $request->user();
        $branchId = $request->validated('branch_id');

        $query = PosTerminal::query()->where('code', $terminal_code);
        if ($branchId !== null) {
            $query->where('branch_id', (int) $branchId);
        }

        $matches = $query->orderBy('id')->get();

        if ($matches->isEmpty()) {
            return response()->json(['message' => 'Terminal not found.'], 404);
        }

        if ($matches->count() > 1 && $branchId === null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'branch_id' => ['branch_id is required when terminal_code is not unique.'],
                ],
            ], 422);
        }

        $terminal = $matches->first();
        if (! $user || ! $this->branchAccess->canAccessBranch($user, (int) $terminal->branch_id)) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'BRANCH_FORBIDDEN'], 403);
        }

        $onlineWindowSeconds = max(5, min(300, (int) config('pos.print_jobs.online_window_seconds', 45)));
        $onlineThreshold = now()->subSeconds($onlineWindowSeconds);

        $agentSeenAt = $terminal->print_agent_seen_at;
        $isOnline = $agentSeenAt !== null && $agentSeenAt->greaterThanOrEqualTo($onlineThreshold);

        $pendingJobs = $this->jobs->pendingJobsCount((int) $terminal->id);

        $claimedJobs = PosPrintJob::query()
            ->where('target_terminal_id', (int) $terminal->id)
            ->where('status', PosPrintJob::STATUS_CLAIMED)
            ->where(function ($query): void {
                $query->whereNull('claim_expires_at')
                    ->orWhere('claim_expires_at', '>=', now());
            })
            ->count();

        return response()->json([
            'terminal' => [
                'id' => (int) $terminal->id,
                'code' => (string) $terminal->code,
                'name' => (string) $terminal->name,
                'branch_id' => (int) $terminal->branch_id,
                'active' => (bool) $terminal->active,
            ],
            'online' => $isOnline,
            'print_agent_online' => $isOnline,
            'print_agent_seen_at' => optional($terminal->print_agent_seen_at)->toISOString(),
            'last_seen_at' => optional($terminal->print_agent_seen_at)->toISOString(),
            'online_window_seconds' => $onlineWindowSeconds,
            'pending_jobs' => (int) $pendingJobs,
            'claimed_jobs' => (int) $claimedJobs,
            'server_timestamp' => now()->utc()->toISOString(),
        ]);
    }
}
