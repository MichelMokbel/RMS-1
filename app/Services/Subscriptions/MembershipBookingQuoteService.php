<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\OrdinaryOrderQuoteService;
use App\Services\Payments\PaymentCheckoutException;
use Illuminate\Validation\ValidationException;

class MembershipBookingQuoteService
{
    public function __construct(
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly OrdinaryOrderQuoteService $ordinaryQuotes,
        private readonly MembershipQueueService $queues,
        private readonly CheckoutCanonicalizer $canonicalizer,
    ) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function quote(User $user, array $request): array
    {
        if (! (bool) config('payments.membership.queue_enabled', false)
            || ! (bool) config('payments.membership.booking_enabled', false)) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_DISABLED', 503, __('Membership meal booking is not available yet.'));
        }

        $selectedBranchId = (int) ($request['selected_branch_id'] ?? 0);
        $configuredBranchId = (int) config('payments.public_order_branch_id', 1);
        if ($selectedBranchId !== $configuredBranchId) {
            throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
        }

        $user = $this->identityResolver->resolveForCheckout($user);
        $rawSelections = $request['selections'] ?? null;
        if (! is_array($rawSelections) || $rawSelections === []) {
            throw ValidationException::withMessages(['selections' => __('Select at least one future main dish.')]);
        }

        $normalized = $this->ordinaryQuotes->normalizeCart(['items' => $rawSelections]);
        $membershipSelections = array_map(function (array $day): array {
            $mainQuantity = 0;
            foreach ($day['mains'] as $main) {
                if ($main['portion'] !== 'plate') {
                    throw ValidationException::withMessages([
                        'selections' => __('Membership meals support plate main dishes only.'),
                    ]);
                }
                $mainQuantity += (int) $main['qty'];
            }

            return [
                'key' => $day['date'],
                'mains' => $day['mains'],
                'salad_qty' => $mainQuantity,
                'dessert_qty' => $mainQuantity,
                'notes' => $day['notes'],
            ];
        }, $normalized);

        $ordinary = $this->ordinaryQuotes->quote($user, [
            'cart' => ['items' => $membershipSelections],
        ]);
        $context = $ordinary['_context'];
        $queue = $this->queues->summary(
            (int) $user->customer_id,
            (int) $context['company_id'],
            (int) $context['branch']->id,
        );
        $queueReference = trim((string) ($request['queue_reference'] ?? ''));
        if (! $queue['queue_reference'] || ! hash_equals((string) $queue['queue_reference'], $queueReference)) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_NOT_AVAILABLE_FOR_BRANCH',
                422,
                __('No paid membership allowance is available for this branch.'),
                ['support_phone' => $context['settings']->order_support_phone, 'can_buy_membership' => true],
            );
        }

        $acceptedDays = $ordinary['_priced_days'];
        $mainQuantity = collect($acceptedDays)->sum(fn (array $day): int => collect($day['submission']['mains'])
            ->sum(fn (array $main): int => (int) $main['qty']));
        if ($mainQuantity <= 0) {
            return [
                'result_kind' => 'covered_booking',
                'selections' => [],
                'excluded_today' => $ordinary['excluded_today'],
                'main_quantity' => 0,
                'payable_amount_cents' => 0,
                'currency' => 'QAR',
                'quote_fingerprint' => null,
                'terms_version' => $ordinary['terms_version'],
                'terms_url' => $ordinary['terms_url'],
                'support_phone' => $ordinary['support_phone'],
                'queue' => $queue,
                'can_book' => false,
            ];
        }
        if ($mainQuantity > (int) $queue['available_meals']) {
            throw new PaymentCheckoutException(
                'INSUFFICIENT_MEMBERSHIP_BALANCE',
                422,
                __('Your membership has :available meals available, but :selected were selected.', [
                    'available' => (int) $queue['available_meals'],
                    'selected' => $mainQuantity,
                ]),
                ['queue' => $queue, 'can_buy_membership' => true],
            );
        }

        $canonicalDays = array_map(fn (array $day): array => $day['canonical_tuple'], $acceptedDays);
        $quoteFingerprint = $this->canonicalizer->hash([
            'membership-booking-quote-v1',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            (string) $queueReference,
            (string) $queue['queue_revision'],
            $canonicalDays,
            (string) $ordinary['terms_version'],
            (string) $ordinary['terms_content_hash'],
        ]);

        return [
            'result_kind' => 'covered_booking',
            'queue_reference' => $queueReference,
            'queue_revision' => (int) $queue['queue_revision'],
            'selections' => array_map(fn (array $day): array => $day['submission'], $acceptedDays),
            'excluded_today' => $ordinary['excluded_today'],
            'main_quantity' => $mainQuantity,
            'payable_amount_cents' => 0,
            'currency' => 'QAR',
            'quote_fingerprint' => $quoteFingerprint,
            'terms_version' => $ordinary['terms_version'],
            'terms_url' => $ordinary['terms_url'],
            'terms_content_hash' => $ordinary['terms_content_hash'],
            'support_phone' => $ordinary['support_phone'],
            'current_qatar_date' => now('Asia/Qatar')->toDateString(),
            'queue' => $queue,
            'can_book' => true,
            '_context' => $context,
            '_priced_days' => $acceptedDays,
        ];
    }
}
