<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Promotions\MembershipPromotionRequestService;
use Illuminate\Http\Request;

class CustomerMembershipRequestController extends Controller
{
    public function __construct(
        private readonly MembershipPromotionRequestService $requests,
    ) {}

    public function store(Request $request)
    {
        try {
            $this->assertExactKeys($request->all(), [
                'client_uuid',
                'selected_branch_id',
                'plan_code',
                'promo_code',
                'selections',
                'quote_fingerprint',
                'accepted_terms_version',
            ]);
            $payload = $request->validate([
                'client_uuid' => ['required', 'uuid'],
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'plan_code' => ['required', 'string', 'max:20'],
                'promo_code' => ['required', 'string', 'max:80'],
                'selections' => ['present', 'array'],
                'quote_fingerprint' => ['required', 'string', 'size:64'],
                'accepted_terms_version' => ['required', 'string', 'max:80'],
            ]);
            $result = $this->requests->create($request->user(), $payload);

            return response()->json(
                $result['result'] + ['replayed' => $result['replayed']],
                $result['status'],
            );
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function show(Request $request, string $reference)
    {
        $mealPlanRequest = $this->requests->findOwned($request->user(), $reference);

        return response()->json($this->requests->present($mealPlanRequest));
    }

    /** @param array<string, mixed> $payload @param array<int, string> $allowed */
    private function assertExactKeys(array $payload, array $allowed): void
    {
        if (array_diff(array_keys($payload), $allowed) !== []) {
            abort(response()->json([
                'message' => __('Unsupported membership request fields were submitted.'),
            ], 422));
        }
    }

    private function error(PaymentCheckoutException $exception)
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeName,
            ...$exception->context,
        ], $exception->status);
    }
}
