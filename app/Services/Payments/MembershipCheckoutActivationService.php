<?php

namespace App\Services\Payments;

use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\MembershipPlan;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\AR\ArPaymentService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Subscriptions\MembershipQueueService;
use Illuminate\Support\Facades\DB;

class MembershipCheckoutActivationService
{
    public function __construct(
        private readonly ArPaymentService $payments,
        private readonly MembershipQueueService $queues,
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function complete(int $attemptId, int $providerTransactionId): PaymentCheckoutAttempt
    {
        return DB::transaction(function () use ($attemptId, $providerTransactionId): PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if (in_array($attempt->state, ['completed', 'payment_received_as_credit'], true)) {
                return $attempt->fresh('targets.mealPlanRequest');
            }
            if ($attempt->state !== 'paid_processing' || $attempt->purpose !== 'membership') {
                throw new PaymentCheckoutException('CAPTURE_NOT_ACCEPTED', 409, __('This membership payment is not ready for completion.'));
            }

            $providerTransaction = PaymentProviderTransaction::query()->lockForUpdate()->findOrFail($providerTransactionId);
            $intent = is_array($attempt->financial_intent) ? $attempt->financial_intent : [];
            if ((int) ($intent['provider_transaction_id'] ?? 0) !== (int) $providerTransaction->id
                || (int) $providerTransaction->attempt_id !== (int) $attempt->id
                || $providerTransaction->verified_paid_at === null
                || (int) $providerTransaction->verified_amount_cents !== (int) $attempt->payable_amount_cents
                || $providerTransaction->verified_currency !== 'QAR') {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment capture does not match this membership checkout.'));
            }

            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $attempt->customer_id);
            if (! $customer->isActive()) {
                throw new PaymentCheckoutException('CUSTOMER_UNAVAILABLE', 409, __('The payment customer is unavailable.'));
            }
            $source = $attempt->paymentSource()->lockForUpdate()->first();
            if (! $source
                || (int) $source->company_id !== (int) $attempt->company_id
                || (int) $source->id !== (int) ($attempt->source_account_snapshot['payment_source_id'] ?? 0)) {
                throw new PaymentCheckoutException('PAYMENT_SOURCE_MISMATCH', 503, __('The payment source is unavailable.'));
            }
            $clearingAccountId = (int) ($attempt->source_account_snapshot['clearing_account_id'] ?? 0);
            if ($clearingAccountId <= 0) {
                throw new PaymentCheckoutException('CLEARING_ACCOUNT_MISSING', 503, __('The payment clearing account is unavailable.'));
            }

            $targets = PaymentCheckoutTarget::query()
                ->where('attempt_id', $attempt->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            $target = $targets->first();
            if ($targets->count() !== 1 || ! $target || $target->target_type !== 'meal_plan_request'
                || ! $target->meal_plan_request_id
                || (int) $target->expected_amount_cents !== (int) $attempt->payable_amount_cents
                || $target->order_id || $target->invoice_id) {
                throw new PaymentCheckoutException('TARGET_TOTAL_MISMATCH', 503, __('The membership checkout target is inconsistent.'));
            }

            $actorId = (int) config('payments.system_user_id');
            if ($actorId <= 0) {
                throw new PaymentCheckoutException('SYSTEM_ACTOR_MISSING', 503, __('The payment system actor is unavailable.'));
            }
            $allocationDate = (string) ($intent['allocation_date'] ?? '');
            if ($allocationDate === '') {
                throw new PaymentCheckoutException('FINANCIAL_DATE_MISSING', 503, __('The payment financial date is unavailable.'));
            }

            $payment = $this->payments->createPaymentWithAllocations([
                'customer_id' => $customer->id,
                'branch_id' => $attempt->branch_id,
                'amount_cents' => (int) $attempt->payable_amount_cents,
                'method' => 'skipcash',
                'currency' => 'QAR',
                'received_at' => $providerTransaction->verified_finished_at,
                'reference' => $providerTransaction->provider_payment_id,
                'notes' => __('SkipCash membership receipt for checkout :reference', ['reference' => $attempt->reference]),
                'client_uuid' => $providerTransaction->receipt_client_uuid,
                'payment_source_id' => $source->id,
                'settlement_account_id' => $clearingAccountId,
                'record_bank_transaction' => false,
                'allocations' => [],
            ], $actorId);
            if ((int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
                || (int) $payment->unallocatedCents() !== (int) $attempt->payable_amount_cents) {
                throw new PaymentCheckoutException('PAYMENT_RECORD_INCOMPLETE', 503, __('The membership payment could not be recorded.'));
            }
            $providerTransaction->update([
                'payment_id' => $payment->id,
                'receipt_date' => $allocationDate,
            ]);

            if (! $providerTransaction->verified_finished_at
                || ! $providerTransaction->verified_finished_at->lt($attempt->expires_at)) {
                $providerTransaction->update(['classification' => 'retained_credit']);
                $target->update([
                    'hold_state' => 'released',
                    'released_at' => now('UTC'),
                ]);
                $attempt->update([
                    'state' => 'payment_received_as_credit',
                    'completed_at' => now('UTC'),
                    'next_recovery_at' => null,
                    'last_error_code' => null,
                    'notification_dispatch' => null,
                ]);
                $this->auditLog->log('payment.membership.received_as_credit', $actorId, $attempt, [
                    'provider_transaction_id' => $providerTransaction->id,
                    'payment_id' => $payment->id,
                    'meal_plan_request_id' => $target->meal_plan_request_id,
                    'receipt_date' => $allocationDate,
                ], (int) $attempt->company_id);

                return $attempt->fresh('targets.mealPlanRequest');
            }

            $planId = (int) ($target->item_snapshot['plan_id'] ?? 0);
            $plan = MembershipPlan::query()->lockForUpdate()->find($planId);
            if (! $plan) {
                throw new PaymentCheckoutException('MEMBERSHIP_PLAN_MISSING', 503, __('The retained membership plan is unavailable.'));
            }
            $conversion = $this->queues->convertPaidPurchase(
                $attempt,
                $target,
                $providerTransaction->fresh(),
                $payment,
                $plan,
                $actorId,
            );
            $target->update([
                'hold_state' => 'activated',
                'activated_at' => now('UTC'),
            ]);
            $providerTransaction->update(['classification' => 'purchase']);

            $notificationSnapshots = [
                'customer_email' => $attempt->customer_snapshot['email'] ?? null,
                'admin_emails' => $this->adminRecipients((int) $attempt->company_id),
                'meal_plan_request_id' => (int) $conversion['request']->id,
                'subscription_id' => (int) $conversion['subscription']->id,
                'purchase_block_id' => (int) $conversion['block']->id,
                'plan_code' => (string) $plan->code,
                'meal_count' => (int) $plan->meal_count,
                'amount_cents' => (int) $attempt->payable_amount_cents,
                'reference' => $attempt->reference,
            ];
            $attempt->update([
                'state' => 'completed',
                'completed_at' => now('UTC'),
                'next_recovery_at' => null,
                'last_error_code' => null,
                'notification_snapshots' => $notificationSnapshots,
                'notification_dispatch' => [
                    'customer_confirmation' => ['state' => 'pending'],
                    'admin_confirmation' => ['state' => 'pending'],
                ],
            ]);
            $this->auditLog->log('payment.membership_checkout.completed', $actorId, $attempt, [
                'provider_transaction_id' => $providerTransaction->id,
                'payment_id' => $payment->id,
                'meal_plan_request_id' => $conversion['request']->id,
                'subscription_id' => $conversion['subscription']->id,
                'purchase_block_id' => $conversion['block']->id,
                'receipt_date' => $allocationDate,
            ], (int) $attempt->company_id);
            DB::afterCommit(function () use ($attempt): void {
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'customer');
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'admin');
            });

            return $attempt->fresh('targets.mealPlanRequest');
        }, 3);
    }

    /** @return array<int, string> */
    private function adminRecipients(int $companyId): array
    {
        try {
            return $this->mailSettings->adminRecipientsForCompany($companyId);
        } catch (MailConfigurationUnavailableException) {
            return [];
        }
    }
}
