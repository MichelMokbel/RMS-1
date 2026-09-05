<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Payments\CheckoutStatusPresenter;
use App\Services\Payments\MembershipCheckoutService;
use App\Services\Payments\MembershipQuoteService;
use App\Services\Payments\OrdinaryOrderCheckoutService;
use App\Services\Payments\OrdinaryOrderQuoteService;
use App\Services\Payments\PaymentCheckoutException;
use Illuminate\Http\Request;

class CustomerCheckoutController extends Controller
{
    public function __construct(
        private readonly OrdinaryOrderQuoteService $ordinaryQuotes,
        private readonly OrdinaryOrderCheckoutService $ordinaryCheckouts,
        private readonly MembershipQuoteService $membershipQuotes,
        private readonly MembershipCheckoutService $membershipCheckouts,
        private readonly CheckoutStatusPresenter $statuses,
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    public function quote(Request $request)
    {
        try {
            $purpose = (string) $request->input('purpose');
            if ($purpose === 'membership') {
                $this->assertExactKeys($request->all(), ['purpose', 'selected_branch_id', 'plan_code', 'selections', 'promo_code']);
                $payload = $request->validate([
                    'purpose' => ['required', 'in:membership'],
                    'selected_branch_id' => ['required', 'integer', 'min:1'],
                    'plan_code' => ['required', 'string', 'max:20'],
                    'selections' => ['present', 'array'],
                    'promo_code' => ['nullable', 'string', 'max:80'],
                ]);
                $quote = $this->membershipQuotes->quote($request->user(), $payload);
                unset($quote['_context'], $quote['_plan']);
            } else {
                $this->assertExactKeys($request->all(), ['purpose', 'cart']);
                $payload = $request->validate([
                    'purpose' => ['required', 'in:ordinary_order'],
                    'cart' => ['required', 'array'],
                ]);
                $quote = $this->ordinaryQuotes->quote($request->user(), $payload);
                unset($quote['_context'], $quote['_priced_days'], $quote['_pricing_version'], $quote['_canonical_days']);
            }

            return response()->json($quote);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function store(Request $request)
    {
        try {
            $purpose = (string) $request->input('purpose');
            if ($purpose === 'membership') {
                $this->assertExactKeys($request->all(), [
                    'client_uuid',
                    'purpose',
                    'selected_branch_id',
                    'plan_code',
                    'selections',
                    'promo_code',
                    'quote_fingerprint',
                    'accepted_terms_version',
                ]);
                $payload = $request->validate([
                    'client_uuid' => ['required', 'uuid'],
                    'purpose' => ['required', 'in:membership'],
                    'selected_branch_id' => ['required', 'integer', 'min:1'],
                    'plan_code' => ['required', 'string', 'max:20'],
                    'selections' => ['present', 'array'],
                    'promo_code' => ['nullable', 'string', 'max:80'],
                    'quote_fingerprint' => ['required', 'string', 'size:64'],
                    'accepted_terms_version' => ['required', 'string', 'max:80'],
                ]);
                $result = $this->membershipCheckouts->create($request->user(), $payload);
            } else {
                $this->assertExactKeys($request->all(), [
                    'client_uuid',
                    'purpose',
                    'cart',
                    'quote_fingerprint',
                    'accepted_terms_version',
                    'separate_purchase_from',
                ]);
                $payload = $request->validate([
                    'client_uuid' => ['required', 'uuid'],
                    'purpose' => ['required', 'in:ordinary_order'],
                    'cart' => ['required', 'array'],
                    'quote_fingerprint' => ['required', 'string', 'size:64'],
                    'accepted_terms_version' => ['required', 'string', 'max:80'],
                    'separate_purchase_from' => ['nullable', 'uuid'],
                ]);
                $result = $this->ordinaryCheckouts->create($request->user(), $payload);
            }

            return response()->json($result['result'] + ['replayed' => $result['replayed']], $result['status']);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,paid_processing,completed,declined'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $customerIds = $this->customerOwnership->historicalCustomerIds((int) $request->user()->customer_id);
        $query = PaymentCheckoutAttempt::query()
            ->whereIn('customer_id', $customerIds)
            ->latest('id');
        $status = $request->string('status')->toString();
        if ($status !== '') {
            $internal = match ($status) {
                'pending' => ['initiating', 'pending'],
                'paid_processing' => ['paid_processing'],
                'completed' => ['completed'],
                default => ['declined', 'expired'],
            };
            $query->whereIn('state', $internal);
        }
        $page = $query->paginate((int) $request->input('per_page', 20));
        $page->setCollection($page->getCollection()->map(function (PaymentCheckoutAttempt $attempt): array {
            $result = $this->statuses->present($attempt);
            unset($result['pay_url']);

            return $result;
        }));

        return response()->json($page);
    }

    public function show(Request $request, string $reference)
    {
        $attempt = PaymentCheckoutAttempt::query()->where('reference', $reference)->firstOrFail();
        $customerIds = $this->customerOwnership->historicalCustomerIds((int) $request->user()->customer_id);
        if (! in_array((int) $attempt->customer_id, $customerIds, true)) {
            abort(403);
        }

        return response()->json($this->statuses->present($attempt, true));
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
            abort(response()->json([
                'message' => __('Unsupported checkout fields were submitted.'),
            ], 422));
        }
    }
}
