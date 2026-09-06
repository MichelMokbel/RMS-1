<?php

return [
    'enabled' => (bool) env('PAYMENT_CONSISTENCY_ENABLED', false),
    'batch_size' => (int) env('PAYMENT_CONSISTENCY_BATCH_SIZE', 100),
    'catchup_lookback_minutes' => 20,
    'deferred_retry_minutes' => 5,
    'run_stale_seconds' => 300,
    'timezone' => 'Asia/Qatar',
    'rules' => [
        'provider_checkout_v1' => [
            'version' => 1,
            'subject_types' => ['payment_checkout_attempt'],
            'fingerprint_readers' => ['attempt', 'targets', 'provider_transactions', 'provider_events', 'payments'],
        ],
        'ordinary_accounting_v1' => [
            'version' => 1,
            'subject_types' => ['payment_checkout_target'],
            'fingerprint_readers' => ['attempt', 'target', 'order', 'invoice', 'invoice_items', 'payment', 'allocations', 'subledger', 'subledger_lines'],
        ],
        'membership_purchase_v1' => [
            'version' => 1,
            'subject_types' => ['membership_purchase_block', 'meal_plan_request'],
            'fingerprint_readers' => ['block_or_request', 'plan', 'checkout', 'checkout_targets', 'provider_transaction', 'payment', 'subscription', 'promotion_redemption', 'manual_conversion_audits'],
        ],
        'membership_balance_v1' => [
            'version' => 1,
            'subject_types' => ['meal_subscription'],
            'fingerprint_readers' => ['compatible_roots', 'purchase_blocks', 'opening_ranges', 'booking_funding', 'booking_operations'],
        ],
        'membership_sequence_v1' => [
            'version' => 1,
            'subject_types' => ['meal_subscription'],
            'fingerprint_readers' => ['compatible_roots', 'purchase_blocks', 'position_ranges', 'booking_funding', 'booking_operations'],
        ],
        'booking_correction_v1' => [
            'version' => 1,
            'subject_types' => ['meal_subscription_order'],
            'fingerprint_readers' => ['subscription_order', 'order', 'invoice', 'purchase_blocks', 'booking_funding', 'payment_allocations', 'invoice_void_audits', 'operation_audits'],
        ],
        'booking_policy_v1' => [
            'version' => 1,
            'subject_types' => ['meal_subscription_order'],
            'fingerprint_readers' => ['subscription_order', 'booking_operation', 'accepted_result_snapshot', 'notification_snapshot_consistency', 'saved_cutoff', 'saved_deadline', 'subscription_pauses', 'order', 'invoice', 'booking_funding'],
        ],
        'customer_ownership_v1' => [
            'version' => 1,
            'subject_types' => ['customer'],
            'fingerprint_readers' => ['customer_chain', 'portal_users', 'match_reviews', 'merge_audits', 'live_customer_references'],
        ],
        'promotion_usage_v1' => [
            'version' => 1,
            'subject_types' => ['membership_promotion'],
            'fingerprint_readers' => ['promotion', 'reservations', 'redemptions', 'checkout_attempts', 'requests', 'blocks', 'payments', 'subscriptions', 'conversion_audits'],
        ],
        'saved_credit_v1' => [
            'version' => 1,
            'subject_types' => ['payment'],
            'fingerprint_readers' => ['payment', 'active_and_voided_allocations', 'invoices', 'membership_blocks', 'booking_funding', 'allocation_audits'],
        ],
        'settlement_v1' => [
            'version' => 1,
            'subject_types' => ['gateway_settlement_import'],
            'fingerprint_readers' => ['payment_source', 'imports', 'cross_import_rows', 'provider_transactions', 'payments', 'settlements', 'items', 'adjustments', 'default_bank', 'bank_transactions', 'subledger'],
        ],
        'notification_operations_v1' => [
            'version' => 1,
            'subject_types' => ['payment_checkout_attempt', 'meal_plan_request', 'meal_subscription_order'],
            'fingerprint_readers' => ['typed_parent', 'notification_dispatch', 'email_logs', 'operations_issue_episode'],
        ],
    ],
];
