<?php

namespace App\Services\Payments;

use App\Services\Payments\Consistency\CheckoutAccountingConsistencyRule;
use App\Services\Payments\Consistency\CustomerCreditConsistencyRule;
use App\Services\Payments\Consistency\MembershipConsistencyRule;
use App\Services\Payments\Consistency\NotificationOperationsConsistencyRule;
use App\Services\Payments\Consistency\PaymentConsistencyRule;
use App\Services\Payments\Consistency\PromotionConsistencyRule;
use App\Services\Payments\Consistency\SettlementConsistencyRule;

class PaymentConsistencyRuleRegistry
{
    private const REQUIRED_CONTRACTS = [
        'provider_checkout_v1' => [
            'subject_types' => ['payment_checkout_attempt'],
            'fingerprint_readers' => ['attempt', 'targets', 'provider_transactions', 'provider_events', 'payments'],
        ],
        'ordinary_accounting_v1' => [
            'subject_types' => ['payment_checkout_target'],
            'fingerprint_readers' => ['attempt', 'target', 'order', 'invoice', 'invoice_items', 'payment', 'allocations', 'subledger', 'subledger_lines'],
        ],
        'membership_purchase_v1' => [
            'subject_types' => ['membership_purchase_block', 'meal_plan_request'],
            'fingerprint_readers' => ['block_or_request', 'plan', 'checkout', 'checkout_targets', 'provider_transaction', 'payment', 'subscription', 'promotion_redemption', 'manual_conversion_audits'],
        ],
        'membership_balance_v1' => [
            'subject_types' => ['meal_subscription'],
            'fingerprint_readers' => ['compatible_roots', 'purchase_blocks', 'opening_ranges', 'booking_funding', 'booking_operations'],
        ],
        'membership_sequence_v1' => [
            'subject_types' => ['meal_subscription'],
            'fingerprint_readers' => ['compatible_roots', 'purchase_blocks', 'position_ranges', 'booking_funding', 'booking_operations'],
        ],
        'booking_correction_v1' => [
            'subject_types' => ['meal_subscription_order'],
            'fingerprint_readers' => ['subscription_order', 'order', 'invoice', 'purchase_blocks', 'booking_funding', 'payment_allocations', 'invoice_void_audits', 'operation_audits'],
        ],
        'booking_policy_v1' => [
            'subject_types' => ['meal_subscription_order'],
            'fingerprint_readers' => ['subscription_order', 'booking_operation', 'accepted_result_snapshot', 'notification_snapshot_consistency', 'saved_cutoff', 'saved_deadline', 'subscription_pauses', 'order', 'invoice', 'booking_funding'],
        ],
        'customer_ownership_v1' => [
            'subject_types' => ['customer'],
            'fingerprint_readers' => ['customer_chain', 'portal_users', 'match_reviews', 'merge_audits', 'live_customer_references'],
        ],
        'promotion_usage_v1' => [
            'subject_types' => ['membership_promotion'],
            'fingerprint_readers' => ['promotion', 'reservations', 'redemptions', 'checkout_attempts', 'requests', 'blocks', 'payments', 'subscriptions', 'conversion_audits'],
        ],
        'saved_credit_v1' => [
            'subject_types' => ['payment'],
            'fingerprint_readers' => ['payment', 'active_and_voided_allocations', 'invoices', 'membership_blocks', 'booking_funding', 'allocation_audits'],
        ],
        'settlement_v1' => [
            'subject_types' => ['gateway_settlement_import'],
            'fingerprint_readers' => ['payment_source', 'imports', 'cross_import_rows', 'provider_transactions', 'payments', 'settlements', 'items', 'adjustments', 'default_bank', 'bank_transactions', 'subledger'],
        ],
        'notification_operations_v1' => [
            'subject_types' => ['payment_checkout_attempt', 'meal_plan_request', 'meal_subscription_order'],
            'fingerprint_readers' => ['typed_parent', 'notification_dispatch', 'email_logs', 'operations_issue_episode'],
        ],
    ];

    /** @var array<string, PaymentConsistencyRule> */
    private array $adapters = [];

    public function __construct(
        CheckoutAccountingConsistencyRule $checkout,
        CustomerCreditConsistencyRule $customers,
        MembershipConsistencyRule $memberships,
        PromotionConsistencyRule $promotions,
        SettlementConsistencyRule $settlements,
        NotificationOperationsConsistencyRule $notifications,
    ) {
        foreach ([$checkout, $customers, $memberships, $promotions, $settlements, $notifications] as $adapter) {
            foreach ($adapter->ruleCodes() as $code) {
                if (isset($this->adapters[$code])) {
                    throw new \LogicException("Duplicate payment consistency adapter: {$code}");
                }
                $this->adapters[$code] = $adapter;
            }
        }
        $this->assertContract();
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return array_keys($this->definitions());
    }

    /** @return array<string, int> */
    public function versions(): array
    {
        return collect($this->definitions())
            ->mapWithKeys(fn (array $definition, string $code): array => [$code => (int) $definition['version']])
            ->all();
    }

    /** @return array<string, mixed> */
    public function definition(string $ruleCode): array
    {
        return $this->definitions()[$ruleCode]
            ?? throw new \InvalidArgumentException("Unknown payment consistency rule: {$ruleCode}");
    }

    public function adapter(string $ruleCode): PaymentConsistencyRule
    {
        return $this->adapters[$ruleCode]
            ?? throw new \LogicException("Payment consistency rule has no adapter: {$ruleCode}");
    }

    public function supports(string $ruleCode, string $subjectType): bool
    {
        return in_array($subjectType, (array) ($this->definition($ruleCode)['subject_types'] ?? []), true);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->definitions(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $definitions = config('payment_consistency.rules', []);
        if (! is_array($definitions)) {
            throw new \LogicException('Payment consistency rules configuration must be an array.');
        }

        return $definitions;
    }

    private function assertContract(): void
    {
        $definitions = $this->definitions();
        $configuredCodes = array_keys($definitions);
        $adapterCodes = array_keys($this->adapters);
        $requiredCodes = array_keys(self::REQUIRED_CONTRACTS);
        sort($configuredCodes);
        sort($adapterCodes);
        sort($requiredCodes);
        if ($configuredCodes !== $adapterCodes || $configuredCodes !== $requiredCodes) {
            throw new \LogicException('The payment consistency registry and adapters do not cover the same rule set.');
        }
        foreach ($definitions as $code => $definition) {
            if (! preg_match('/^[a-z0-9_]+_v\d+$/', $code)
                || (int) ($definition['version'] ?? 0) <= 0
                || empty($definition['subject_types'])
                || empty($definition['fingerprint_readers'])) {
                throw new \LogicException("Payment consistency rule contract is incomplete: {$code}");
            }
            if (($definition['subject_types'] ?? null) !== self::REQUIRED_CONTRACTS[$code]['subject_types']
                || ($definition['fingerprint_readers'] ?? null) !== self::REQUIRED_CONTRACTS[$code]['fingerprint_readers']) {
                throw new \LogicException("Payment consistency rule readers are incomplete: {$code}");
            }
            foreach ((array) $definition['subject_types'] as $subjectType) {
                if (! is_string($subjectType) || ! preg_match('/^[a-z0-9_]+$/', $subjectType)) {
                    throw new \LogicException("Payment consistency subject type is invalid: {$code}");
                }
            }
        }
    }
}
