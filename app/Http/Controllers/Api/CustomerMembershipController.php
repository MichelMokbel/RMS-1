<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\MembershipBookingCheckoutService;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Subscriptions\MembershipBookingQuoteService;
use App\Services\Subscriptions\MembershipBookingReadService;
use App\Services\Subscriptions\MembershipBookingService;
use App\Services\Subscriptions\MembershipQueueService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerMembershipController extends Controller
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly MembershipQueueService $queues,
        private readonly MembershipBookingQuoteService $quotes,
        private readonly MembershipBookingService $bookings,
        private readonly MembershipBookingReadService $bookingReads,
        private readonly MembershipBookingCheckoutService $bookingCheckouts,
    ) {}

    public function show(Request $request)
    {
        if (! (bool) config('payments.membership.queue_enabled', false)) {
            return response()->json(['message' => __('Membership access is not available yet.')], 503);
        }
        $payload = $request->validate([
            'selected_branch_id' => ['required', 'integer', 'min:1'],
        ]);
        [$companyId, $branchId] = $this->scope((int) $payload['selected_branch_id']);

        return response()->json([
            'data' => $this->queues->summary(
                (int) $request->user()->customer_id,
                $companyId,
                $branchId,
            ),
            'booking_enabled' => (bool) config('payments.membership.booking_enabled', false),
        ]);
    }

    public function quote(Request $request)
    {
        try {
            $this->assertExactKeys($request->all(), [
                'selected_branch_id',
                'queue_reference',
                'selections',
                'booking_reference',
                'booking_revision',
            ]);
            $payload = $request->validate([
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'queue_reference' => ['required', 'string', 'max:80'],
                'selections' => ['required', 'array', 'min:1'],
                'booking_reference' => ['nullable', 'uuid', 'required_with:booking_revision'],
                'booking_revision' => ['nullable', 'integer', 'min:1', 'required_with:booking_reference'],
            ]);
            $quote = $this->quotes->quote($request->user(), $payload);
            unset($quote['_context'], $quote['_priced_days']);

            return response()->json($quote);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function index(Request $request)
    {
        try {
            $payload = $request->validate([
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'queue_reference' => ['required', 'string', 'max:80'],
                'filter' => ['nullable', 'in:future,history,all'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            return response()->json($this->bookingReads->index(
                $request->user(),
                (int) $payload['selected_branch_id'],
                (string) $payload['queue_reference'],
                (string) ($payload['filter'] ?? 'future'),
                (int) ($payload['per_page'] ?? 20),
            ));
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function store(Request $request)
    {
        try {
            $this->assertExactKeys($request->all(), [
                'client_uuid',
                'selected_branch_id',
                'queue_reference',
                'queue_revision',
                'selections',
                'quote_fingerprint',
                'accepted_terms_version',
            ]);
            $payload = $request->validate([
                'client_uuid' => ['required', 'uuid'],
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'queue_reference' => ['required', 'string', 'max:80'],
                'queue_revision' => ['required', 'integer', 'min:0'],
                'selections' => ['required', 'array', 'min:1'],
                'quote_fingerprint' => ['required', 'string', 'size:64'],
                'accepted_terms_version' => ['required', 'string', 'max:80'],
            ]);
            $result = $this->bookingCheckouts->replay($request->user(), $payload);
            $result ??= $this->bookings->replay($request->user(), $payload);
            if ($result === null) {
                $quote = $this->quotes->quote($request->user(), $payload);
                $result = (int) $quote['payable_amount_cents'] > 0
                    ? $this->bookingCheckouts->create($request->user(), $payload)
                    : $this->bookings->create($request->user(), $payload);
            }

            return response()->json($result['result'] + ['replayed' => $result['replayed']], $result['status']);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function update(string $reference, Request $request)
    {
        try {
            $this->assertExactKeys($request->all(), [
                'client_uuid',
                'expected_booking_revision',
                'selected_branch_id',
                'queue_reference',
                'queue_revision',
                'selections',
                'quote_fingerprint',
                'accepted_terms_version',
            ]);
            $payload = $request->validate([
                'client_uuid' => ['required', 'uuid'],
                'expected_booking_revision' => ['required', 'integer', 'min:1'],
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'queue_reference' => ['required', 'string', 'max:80'],
                'queue_revision' => ['required', 'integer', 'min:0'],
                'selections' => ['required', 'array', 'min:1'],
                'quote_fingerprint' => ['required', 'string', 'size:64'],
                'accepted_terms_version' => ['required', 'string', 'max:80'],
            ]);
            $result = $this->bookings->replace($request->user(), $reference, $payload);

            return response()->json($result['result'] + ['replayed' => $result['replayed']], $result['status']);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function destroy(string $reference, Request $request)
    {
        try {
            $this->assertExactKeys($request->all(), [
                'client_uuid',
                'expected_booking_revision',
                'selected_branch_id',
                'queue_reference',
                'queue_revision',
            ]);
            $payload = $request->validate([
                'client_uuid' => ['required', 'uuid'],
                'expected_booking_revision' => ['required', 'integer', 'min:1'],
                'selected_branch_id' => ['required', 'integer', 'min:1'],
                'queue_reference' => ['required', 'string', 'max:80'],
                'queue_revision' => ['required', 'integer', 'min:0'],
            ]);
            $result = $this->bookings->cancel($request->user(), $reference, $payload);

            return response()->json($result['result'] + ['replayed' => $result['replayed']], $result['status']);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    /** @return array{0:int,1:int} */
    private function scope(int $submittedBranchId): array
    {
        $configuredBranchId = (int) config('payments.public_order_branch_id', 1);
        $companyId = $this->accountingContext->defaultCompanyId();
        $branch = Branch::query()->find($configuredBranchId);
        if ($submittedBranchId !== $configuredBranchId || ! $companyId || ! $branch
            || ! $branch->is_active || (int) $branch->company_id !== $companyId) {
            throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
        }

        return [$companyId, $configuredBranchId];
    }

    private function error(PaymentCheckoutException $exception)
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeName,
            ...$exception->context,
        ], $exception->status);
    }

    /** @param array<string, mixed> $payload
     * @param  array<int, string>  $allowed
     */
    private function assertExactKeys(array $payload, array $allowed): void
    {
        if (array_diff(array_keys($payload), $allowed) !== []) {
            abort(response()->json(['message' => __('Unsupported membership fields were submitted.')], 422));
        }
    }
}
