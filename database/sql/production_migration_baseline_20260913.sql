-- Migration ledger baseline originally prepared on 2026-09-13 and reverified against the final snapshot supplied on 2026-09-26.
-- Final source file: ilwlvmmy_inventory (4).sql
-- Final source SHA256: c167ceb3ed860d6e8b8ef4a3b5421e14cc9080e0a7d9c1e7af914bfa7f83220c
--
-- This file only records migration effects that were verified as already present.
-- It never changes application data or application schema.
-- This exact manifest was rehearsed successfully against the final production snapshot.

START TRANSACTION;

CREATE TEMPORARY TABLE rms_verified_migration_baseline (
    migration VARCHAR(255) NOT NULL PRIMARY KEY
);

INSERT INTO rms_verified_migration_baseline (migration) VALUES
    ('2026_02_17_000001_create_company_food_projects_table'),
    ('2026_02_17_000002_create_company_food_options_table'),
    ('2026_02_17_000003_create_company_food_orders_table'),
    ('2026_02_17_000004_make_company_food_order_email_nullable'),
    ('2026_02_17_000005_create_company_food_employees_table'),
    ('2026_02_17_000006_create_company_food_employee_lists_table'),
    ('2026_02_17_000007_create_company_food_list_categories_table'),
    ('2026_02_17_000008_add_employee_list_to_company_food_employees'),
    ('2026_02_17_000009_add_employee_list_and_soup_to_company_food_orders'),
    ('2026_02_17_000010_add_menu_date_to_company_food_options'),
    ('2026_02_17_000011_add_order_date_to_company_food_orders'),
    ('2026_02_17_000012_add_employee_list_to_company_food_options'),
    ('2026_03_07_000013_create_ap_invoice_attachments_table'),
    ('2026_03_07_000014_add_spend_indexes_to_ap_invoices_table'),
    ('2026_03_07_000015_create_expense_profiles_and_events_tables'),
    ('2026_03_07_000016_add_petty_cash_payment_method_to_ap_payments'),
    ('2026_03_07_000017_cutover_drop_legacy_expense_tables'),
    ('2026_03_08_000018_create_pos_print_jobs_table'),
    ('2026_03_08_000019_add_print_agent_seen_at_to_pos_terminals'),
    ('2026_03_09_000020_update_pos_print_jobs_for_realtime_relay'),
    ('2026_03_09_000021_create_pos_print_stream_events_table'),
    ('2026_03_10_000020_add_status_to_recipes_table'),
    ('2026_03_11_000022_add_stream_delivery_columns_for_pos_print'),
    ('2026_03_18_000001_add_sub_recipe_to_recipe_items_table'),
    ('2026_03_24_000030_add_customer_portal_columns_and_phone_verification_table'),
    ('2026_03_24_000031_add_customer_role'),
    ('2026_03_28_000001_add_line_notes_to_purchase_order_items_table'),
    ('2026_03_28_000002_resequence_customer_codes'),
    ('2026_04_11_000002_create_pastry_orders_table'),
    ('2026_04_11_000003_create_pastry_order_items_table'),
    ('2026_04_11_000004_add_source_pastry_order_id_to_ar_invoices'),
    ('2026_04_11_000005_add_pastry_orders_manage_permission'),
    ('2026_04_11_000006_create_pastry_order_images_table'),
    ('2026_04_11_000007_seed_pastry_items_category'),
    ('2026_04_11_000008_drop_pastry_order_number_sequences_table'),
    ('2026_04_13_000001_seed_pastry_menu_items'),
    ('2026_04_14_000001_add_payment_link_and_tracking_flag_to_meal_subscriptions'),
    ('2026_04_14_000001_backfill_payments_company_id'),
    ('2026_04_19_000001_add_sales_order_number_to_pastry_orders_table'),
    ('2026_04_19_000001_harden_accounting_payment_idempotency_and_bank_uniqueness'),
    ('2026_04_19_000002_add_pastry_user_role'),
    ('2026_04_19_000002_add_subledger_entries_source_event_unique'),
    ('2026_04_19_000003_add_payment_allocations_active_unique'),
    ('2026_04_19_000004_add_ap_payment_allocations_active_unique'),
    ('2026_04_19_000005_add_journal_entries_immutability_trigger'),
    ('2026_04_19_000006_create_ar_clearing_settlements_table'),
    ('2026_04_19_000007_create_ar_clearing_settlement_items_table'),
    ('2026_04_19_000008_create_ap_cheque_clearances_table'),
    ('2026_04_19_000009_add_clearing_settled_at_to_payments'),
    ('2026_04_19_000010_add_cheque_cleared_at_to_ap_payments'),
    ('2026_04_22_000002_extend_marketing_spend_snapshots_for_drilldown'),
    ('2026_04_23_000011_adjust_ap_cheque_clearance_client_uuid_uniqueness'),
    ('2026_04_23_000012_adjust_ar_clearing_settlement_client_uuid_uniqueness'),
    ('2026_04_23_000013_ensure_current_accounting_periods_exist'),
    ('2026_04_23_000014_drop_legacy_ap_payment_allocations_unique'),
    ('2026_04_24_000001_create_order_sheet_tables'),
    ('2026_04_24_000002_add_order_id_to_order_sheet_entries'),
    ('2026_04_25_000001_add_customer_portal_profile_fields_and_order_user_link'),
    ('2026_04_30_000001_add_accounting_role_and_permissions'),
    ('2026_05_02_000001_create_email_logs_table'),
    ('2026_06_03_000001_add_bank_account_id_to_petty_cash_issues'),
    ('2026_07_22_000101_upgrade_menu_proposal_template_mode'),
    ('2026_08_10_000001_add_revision_lineage_to_ap_invoices'),
    ('2026_08_27_000001_add_bank_funding_to_petty_cash_import_batches'),
    ('2026_09_12_000001_add_excluded_order_ids_to_order_sheets'),
    ('2026_09_12_000001_add_portion_type_to_order_sheet_entry_quantities'),
    ('2026_09_13_000001_add_portion_type_to_order_sheet_entry_extras');

SET @rms_baseline_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT baseline.migration, @rms_baseline_batch
FROM rms_verified_migration_baseline AS baseline
LEFT JOIN migrations AS recorded
    ON TRIM(recorded.migration) = baseline.migration
WHERE recorded.id IS NULL
ORDER BY baseline.migration;

SELECT ROW_COUNT() AS migration_rows_recorded, @rms_baseline_batch AS baseline_batch;

DROP TEMPORARY TABLE rms_verified_migration_baseline;

COMMIT;
