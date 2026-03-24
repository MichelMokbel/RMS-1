-- Accounting module production migration script
-- Generated from the accounting migration set on 2026-03-24.
-- Intended for MySQL 8.x and for one-time execution on a database that already has the base RMS schema.
-- After running this script, the corresponding Laravel migration rows are also inserted into `migrations`.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. Base accounting / ledger tables
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ledger_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `parent_account_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(20) NOT NULL,
  `account_class` varchar(30) DEFAULT NULL,
  `detail_type` varchar(50) DEFAULT NULL,
  `default_tax_code` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `allow_direct_posting` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledger_accounts_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subledger_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(50) NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned DEFAULT NULL,
  `event` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `source_document_type` varchar(50) DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `currency_code` varchar(10) NOT NULL DEFAULT 'QAR',
  `status` varchar(20) NOT NULL DEFAULT 'posted',
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subledger_entries_source_type_source_id_event_unique` (`source_type`,`source_id`,`event`),
  KEY `subledger_entries_entry_date_index` (`entry_date`),
  KEY `subledger_entries_source_type_source_id_index` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subledger_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `debit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subledger_lines_entry_id_account_id_index` (`entry_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gl_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `generated_at` timestamp NULL DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_batches_period_start_period_end_unique` (`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gl_batch_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `debit_total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `credit_total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_batch_lines_batch_id_account_id_unique` (`batch_id`,`account_id`),
  KEY `gl_batch_lines_batch_id_account_id_index` (`batch_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `subledger_lines`
  ADD CONSTRAINT `subledger_lines_entry_id_foreign` FOREIGN KEY (`entry_id`) REFERENCES `subledger_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subledger_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE RESTRICT;

ALTER TABLE `gl_batch_lines`
  ADD CONSTRAINT `gl_batch_lines_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `gl_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gl_batch_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------
-- 2. Accounting module tables
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `accounting_companies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `base_currency` varchar(10) NOT NULL DEFAULT 'QAR',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `parent_company_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_companies_code_unique` (`code`),
  KEY `accounting_companies_is_active_is_default_index` (`is_active`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `code` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_company_id_code_unique` (`company_id`,`code`),
  KEY `departments_company_id_is_active_index` (`company_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_years_company_id_start_date_end_date_unique` (`company_id`,`start_date`,`end_date`),
  KEY `fiscal_years_company_id_status_index` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `fiscal_year_id` bigint unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `period_number` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_periods_company_year_period_unique` (`company_id`,`fiscal_year_id`,`period_number`),
  KEY `accounting_periods_company_id_status_index` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `period_locks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `lock_type` varchar(30) NOT NULL DEFAULT 'soft',
  `module` varchar(50) NOT NULL DEFAULT 'all',
  `reason` text DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `locked_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `period_locks_company_id_module_index` (`company_id`,`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `closing_checklists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `period_id` bigint unsigned NOT NULL,
  `task_key` varchar(80) DEFAULT NULL,
  `task_name` varchar(150) NOT NULL,
  `task_type` varchar(20) NOT NULL DEFAULT 'manual',
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `result_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `closing_checklists_period_task_key_uq` (`company_id`,`period_id`,`task_key`),
  KEY `closing_checklists_company_id_period_id_status_index` (`company_id`,`period_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `ledger_account_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `code` varchar(50) NOT NULL,
  `account_type` varchar(30) NOT NULL DEFAULT 'checking',
  `bank_name` varchar(120) DEFAULT NULL,
  `account_number_last4` varchar(8) DEFAULT NULL,
  `currency_code` varchar(10) NOT NULL DEFAULT 'QAR',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `opening_balance_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_accounts_company_id_code_unique` (`company_id`,`code`),
  KEY `bank_accounts_company_id_is_default_is_active_index` (`company_id`,`is_default`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_statement_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `storage_path` varchar(255) DEFAULT NULL,
  `imported_rows` int NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'uploaded',
  `processed_at` timestamp NULL DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_statement_imports_bank_account_id_status_index` (`bank_account_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_reconciliation_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `statement_import_id` bigint unsigned DEFAULT NULL,
  `statement_date` date NOT NULL,
  `statement_ending_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `book_ending_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `variance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_reconciliation_runs_bank_account_id_statement_date_index` (`bank_account_id`,`statement_date`),
  KEY `bank_reco_runs_statement_import_idx` (`statement_import_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `bank_account_id` bigint unsigned NOT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `reconciliation_run_id` bigint unsigned DEFAULT NULL,
  `matched_bank_transaction_id` bigint unsigned DEFAULT NULL,
  `transaction_type` varchar(30) NOT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `direction` varchar(10) NOT NULL DEFAULT 'outflow',
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `is_cleared` tinyint(1) NOT NULL DEFAULT 0,
  `cleared_date` date DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `statement_import_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_transactions_bank_account_id_transaction_date_index` (`bank_account_id`,`transaction_date`),
  KEY `bank_transactions_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `bank_transactions_matched_bank_transaction_id_index` (`matched_bank_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `period_id` bigint unsigned DEFAULT NULL,
  `entry_number` varchar(60) NOT NULL,
  `entry_type` varchar(30) NOT NULL DEFAULT 'manual',
  `entry_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_entries_company_id_entry_number_unique` (`company_id`,`entry_number`),
  KEY `journal_entries_company_id_entry_date_status_index` (`company_id`,`entry_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `journal_entry_lines_journal_entry_id_account_id_index` (`journal_entry_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `budget_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `fiscal_year_id` bigint unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `budget_versions_company_year_name_uq` (`company_id`,`fiscal_year_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `budget_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `budget_version_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `period_number` int NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_lines_budget_version_id_period_number_index` (`budget_version_id`,`period_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `estimated_revenue` decimal(14,2) NOT NULL DEFAULT 0.00,
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_jobs_company_id_code_unique` (`company_id`,`code`),
  KEY `accounting_jobs_company_id_status_index` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_job_phases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `code` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_job_phases_job_id_code_unique` (`job_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_job_cost_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `code` varchar(50) NOT NULL,
  `default_account_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounting_job_cost_codes_company_id_code_unique` (`company_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_job_budgets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `job_phase_id` bigint unsigned DEFAULT NULL,
  `job_cost_code_id` bigint unsigned DEFAULT NULL,
  `budget_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_budgets_lookup_idx` (`job_id`,`job_phase_id`,`job_cost_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_job_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `job_phase_id` bigint unsigned DEFAULT NULL,
  `job_cost_code_id` bigint unsigned DEFAULT NULL,
  `company_id` bigint unsigned NOT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `transaction_type` varchar(30) NOT NULL DEFAULT 'cost',
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounting_job_transactions_job_id_transaction_date_index` (`job_id`,`transaction_date`),
  KEY `accounting_job_transactions_source_type_source_id_index` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recurring_bill_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `supplier_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `document_type` varchar(40) NOT NULL DEFAULT 'vendor_bill',
  `frequency` varchar(30) NOT NULL DEFAULT 'monthly',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `default_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `due_day_offset` int NOT NULL DEFAULT 30,
  `next_run_date` date DEFAULT NULL,
  `last_run_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `line_template` json DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rec_bill_templates_run_idx` (`company_id`,`is_active`,`next_run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recurring_bill_template_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recurring_bill_template_id` bigint unsigned NOT NULL,
  `purchase_order_item_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(14,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rec_bill_template_lines_template_sort_idx` (`recurring_bill_template_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_change_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `revision_number` int NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `change_summary` text DEFAULT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `po_change_orders_revision_idx` (`purchase_order_id`,`revision_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workflow_definitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `workflow_type` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `config` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_defs_company_type_idx` (`company_id`,`workflow_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `subject_type` varchar(80) DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `accounting_audit_logs_company_id_action_index` (`company_id`,`action`),
  KEY `accounting_audit_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `accounting_account_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `mapping_key` varchar(60) NOT NULL,
  `ledger_account_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acct_account_mappings_company_key_unique` (`company_id`,`mapping_key`),
  KEY `acct_account_mappings_company_account_index` (`company_id`,`ledger_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_invoice_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `purchase_order_id` bigint unsigned NOT NULL,
  `purchase_order_item_id` bigint unsigned NOT NULL,
  `ap_invoice_id` bigint unsigned NOT NULL,
  `ap_invoice_item_id` bigint unsigned NOT NULL,
  `matched_quantity` decimal(14,3) NOT NULL DEFAULT 0.000,
  `matched_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `invoiced_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `price_variance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `receipt_date` date DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'matched',
  `override_applied` tinyint(1) NOT NULL DEFAULT 0,
  `overridden_by` bigint unsigned DEFAULT NULL,
  `overridden_at` timestamp NULL DEFAULT NULL,
  `override_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `po_invoice_matches_po_item_idx` (`purchase_order_id`,`purchase_order_item_id`),
  KEY `po_invoice_matches_ap_item_idx` (`ap_invoice_id`,`ap_invoice_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Existing-table accounting extensions
-- ---------------------------------------------------------------------------

ALTER TABLE `branches`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`;

ALTER TABLE `suppliers`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `payment_term_id` bigint unsigned DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `default_expense_account_id` bigint unsigned DEFAULT NULL AFTER `payment_term_id`,
  ADD COLUMN IF NOT EXISTS `preferred_payment_method` varchar(30) DEFAULT NULL AFTER `default_expense_account_id`,
  ADD COLUMN IF NOT EXISTS `hold_status` varchar(20) NOT NULL DEFAULT 'open' AFTER `preferred_payment_method`,
  ADD COLUMN IF NOT EXISTS `requires_1099` tinyint(1) NOT NULL DEFAULT 0 AFTER `hold_status`,
  ADD COLUMN IF NOT EXISTS `approval_threshold` decimal(14,2) DEFAULT NULL AFTER `requires_1099`;

ALTER TABLE `ledger_accounts`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `parent_account_id` bigint unsigned DEFAULT NULL AFTER `code`,
  ADD COLUMN IF NOT EXISTS `account_class` varchar(30) DEFAULT NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `detail_type` varchar(50) DEFAULT NULL AFTER `account_class`,
  ADD COLUMN IF NOT EXISTS `default_tax_code` varchar(30) DEFAULT NULL AFTER `detail_type`,
  ADD COLUMN IF NOT EXISTS `allow_direct_posting` tinyint(1) NOT NULL DEFAULT 1 AFTER `is_active`;

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `department_id` bigint unsigned DEFAULT NULL AFTER `supplier_id`,
  ADD COLUMN IF NOT EXISTS `job_id` bigint unsigned DEFAULT NULL AFTER `department_id`,
  ADD COLUMN IF NOT EXISTS `matching_policy` varchar(20) NOT NULL DEFAULT '2_way' AFTER `payment_type`,
  ADD COLUMN IF NOT EXISTS `workflow_state` varchar(30) NOT NULL DEFAULT 'draft' AFTER `matching_policy`,
  ADD COLUMN IF NOT EXISTS `approved_at` timestamp NULL DEFAULT NULL AFTER `received_date`,
  ADD COLUMN IF NOT EXISTS `approved_by` bigint unsigned DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `closed_at` timestamp NULL DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `closed_by` bigint unsigned DEFAULT NULL AFTER `closed_at`;

ALTER TABLE `ap_invoices`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `branch_id` bigint unsigned DEFAULT NULL AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `department_id` bigint unsigned DEFAULT NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `job_id` bigint unsigned DEFAULT NULL AFTER `department_id`,
  ADD COLUMN IF NOT EXISTS `period_id` bigint unsigned DEFAULT NULL AFTER `job_id`,
  ADD COLUMN IF NOT EXISTS `document_type` varchar(40) NOT NULL DEFAULT 'vendor_bill' AFTER `is_expense`,
  ADD COLUMN IF NOT EXISTS `currency_code` varchar(10) NOT NULL DEFAULT 'QAR' AFTER `document_type`,
  ADD COLUMN IF NOT EXISTS `source_document_type` varchar(50) DEFAULT NULL AFTER `currency_code`,
  ADD COLUMN IF NOT EXISTS `source_document_id` bigint unsigned DEFAULT NULL AFTER `source_document_type`,
  ADD COLUMN IF NOT EXISTS `recurring_template_id` bigint unsigned DEFAULT NULL AFTER `source_document_id`;

ALTER TABLE `ap_payments`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `supplier_id`,
  ADD COLUMN IF NOT EXISTS `bank_account_id` bigint unsigned DEFAULT NULL AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `branch_id` bigint unsigned DEFAULT NULL AFTER `bank_account_id`,
  ADD COLUMN IF NOT EXISTS `department_id` bigint unsigned DEFAULT NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `job_id` bigint unsigned DEFAULT NULL AFTER `department_id`,
  ADD COLUMN IF NOT EXISTS `period_id` bigint unsigned DEFAULT NULL AFTER `job_id`,
  ADD COLUMN IF NOT EXISTS `currency_code` varchar(10) NOT NULL DEFAULT 'QAR' AFTER `payment_method`;

ALTER TABLE `subledger_entries`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `source_id`,
  ADD COLUMN IF NOT EXISTS `department_id` bigint unsigned DEFAULT NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `job_id` bigint unsigned DEFAULT NULL AFTER `department_id`,
  ADD COLUMN IF NOT EXISTS `period_id` bigint unsigned DEFAULT NULL AFTER `job_id`,
  ADD COLUMN IF NOT EXISTS `currency_code` varchar(10) NOT NULL DEFAULT 'QAR' AFTER `period_id`,
  ADD COLUMN IF NOT EXISTS `source_document_type` varchar(50) DEFAULT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `source_document_id` bigint unsigned DEFAULT NULL AFTER `source_document_type`;

ALTER TABLE `gl_batches`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `period_id` bigint unsigned DEFAULT NULL AFTER `company_id`;

ALTER TABLE `finance_settings`
  ADD COLUMN IF NOT EXISTS `default_company_id` bigint unsigned DEFAULT NULL AFTER `lock_date`,
  ADD COLUMN IF NOT EXISTS `default_bank_account_id` bigint unsigned DEFAULT NULL AFTER `default_company_id`,
  ADD COLUMN IF NOT EXISTS `po_quantity_tolerance_percent` decimal(8,3) NOT NULL DEFAULT 0.000 AFTER `default_bank_account_id`,
  ADD COLUMN IF NOT EXISTS `po_price_tolerance_percent` decimal(8,3) NOT NULL DEFAULT 0.000 AFTER `po_quantity_tolerance_percent`,
  ADD COLUMN IF NOT EXISTS `purchase_price_variance_account_id` bigint unsigned DEFAULT NULL AFTER `po_price_tolerance_percent`;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `customer_id`,
  ADD COLUMN IF NOT EXISTS `bank_account_id` bigint unsigned DEFAULT NULL AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `period_id` bigint unsigned DEFAULT NULL AFTER `bank_account_id`;

ALTER TABLE `bank_reconciliation_runs`
  ADD COLUMN IF NOT EXISTS `statement_import_id` bigint unsigned DEFAULT NULL AFTER `period_id`;

ALTER TABLE `bank_transactions`
  ADD COLUMN IF NOT EXISTS `matched_bank_transaction_id` bigint unsigned DEFAULT NULL AFTER `reconciliation_run_id`;

ALTER TABLE `ap_invoice_items`
  ADD COLUMN IF NOT EXISTS `purchase_order_item_id` bigint unsigned DEFAULT NULL AFTER `invoice_id`;

ALTER TABLE `ar_invoices`
  ADD COLUMN IF NOT EXISTS `company_id` bigint unsigned DEFAULT NULL AFTER `branch_id`,
  ADD COLUMN IF NOT EXISTS `job_id` bigint unsigned DEFAULT NULL AFTER `company_id`;

-- ---------------------------------------------------------------------------
-- 4. Seed defaults and backfill accounting dimensions
-- ---------------------------------------------------------------------------

INSERT INTO `ledger_accounts` (`code`, `name`, `type`, `is_active`, `created_at`, `updated_at`)
SELECT v.code, v.name, v.type, 1, NOW(), NOW()
FROM (
  SELECT '1000' AS code, 'Cash' AS name, 'asset' AS type UNION ALL
  SELECT '1100', 'Petty Cash', 'asset' UNION ALL
  SELECT '1200', 'Inventory', 'asset' UNION ALL
  SELECT '1300', 'Supplier Advances', 'asset' UNION ALL
  SELECT '1400', 'Input Tax', 'asset' UNION ALL
  SELECT '2000', 'Accounts Payable', 'liability' UNION ALL
  SELECT '2100', 'GRNI Clearing', 'liability' UNION ALL
  SELECT '5000', 'COGS', 'expense' UNION ALL
  SELECT '5100', 'Inventory Adjustments', 'expense' UNION ALL
  SELECT '5200', 'Petty Cash Over/Short', 'expense' UNION ALL
  SELECT '6000', 'General Expense', 'expense'
) v
WHERE NOT EXISTS (SELECT 1 FROM `ledger_accounts` la WHERE la.code = v.code);

INSERT INTO `accounting_companies` (`name`, `code`, `base_currency`, `is_active`, `is_default`, `created_at`, `updated_at`)
SELECT 'Layla Kitchen', 'LAYLA', 'QAR', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `accounting_companies` WHERE `is_default` = 1);

SET @default_company_id := (SELECT `id` FROM `accounting_companies` WHERE `is_default` = 1 ORDER BY `id` LIMIT 1);

INSERT INTO `departments` (`company_id`, `name`, `code`, `is_active`, `created_at`, `updated_at`)
SELECT @default_company_id, 'General', 'GENERAL', 1, NOW(), NOW()
WHERE @default_company_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `departments`
    WHERE `company_id` = @default_company_id AND `code` = 'GENERAL'
  );

SET @general_department_id := (
  SELECT `id` FROM `departments`
  WHERE `company_id` = @default_company_id AND `code` = 'GENERAL'
  ORDER BY `id` LIMIT 1
);

INSERT INTO `fiscal_years` (`company_id`, `name`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`)
SELECT @default_company_id, CONCAT('FY ', y.yr), STR_TO_DATE(CONCAT(y.yr, '-01-01'), '%Y-%m-%d'), STR_TO_DATE(CONCAT(y.yr, '-12-31'), '%Y-%m-%d'), 'open', NOW(), NOW()
FROM (
  SELECT YEAR(CURDATE()) AS yr
  UNION ALL
  SELECT YEAR(CURDATE()) + 1 AS yr
) y
WHERE @default_company_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM `fiscal_years` fy
    WHERE fy.`company_id` = @default_company_id
      AND fy.`start_date` = STR_TO_DATE(CONCAT(y.yr, '-01-01'), '%Y-%m-%d')
  );

WITH RECURSIVE months AS (
  SELECT 1 AS month_no
  UNION ALL
  SELECT month_no + 1 FROM months WHERE month_no < 12
),
years AS (
  SELECT YEAR(CURDATE()) AS yr
  UNION ALL
  SELECT YEAR(CURDATE()) + 1 AS yr
)
INSERT INTO `accounting_periods`
  (`company_id`, `fiscal_year_id`, `name`, `period_number`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`)
SELECT
  @default_company_id,
  fy.`id`,
  DATE_FORMAT(STR_TO_DATE(CONCAT(y.yr, '-', LPAD(m.month_no, 2, '0'), '-01'), '%Y-%m-%d'), '%b %Y'),
  m.month_no,
  STR_TO_DATE(CONCAT(y.yr, '-', LPAD(m.month_no, 2, '0'), '-01'), '%Y-%m-%d'),
  LAST_DAY(STR_TO_DATE(CONCAT(y.yr, '-', LPAD(m.month_no, 2, '0'), '-01'), '%Y-%m-%d')),
  'open',
  NOW(),
  NOW()
FROM years y
JOIN months m
JOIN `fiscal_years` fy
  ON fy.`company_id` = @default_company_id
 AND fy.`start_date` = STR_TO_DATE(CONCAT(y.yr, '-01-01'), '%Y-%m-%d')
WHERE @default_company_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM `accounting_periods` ap
    WHERE ap.`company_id` = @default_company_id
      AND ap.`fiscal_year_id` = fy.`id`
      AND ap.`period_number` = m.month_no
  );

UPDATE `branches`
SET `company_id` = COALESCE(`company_id`, @default_company_id)
WHERE `company_id` IS NULL;

UPDATE `suppliers`
SET `company_id` = COALESCE(`company_id`, @default_company_id)
WHERE `company_id` IS NULL;

UPDATE `ledger_accounts`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `account_class` = COALESCE(`account_class`, `type`)
WHERE `company_id` IS NULL OR `account_class` IS NULL;

UPDATE `purchase_orders`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `department_id` = COALESCE(`department_id`, @general_department_id)
WHERE `company_id` IS NULL OR (`department_id` IS NULL AND @general_department_id IS NOT NULL);

SET @current_period_id := (
  SELECT `id`
  FROM `accounting_periods`
  WHERE `company_id` = @default_company_id
    AND `start_date` <= CURDATE()
    AND `end_date` >= CURDATE()
  ORDER BY `id`
  LIMIT 1
);

UPDATE `ap_invoices`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `department_id` = COALESCE(`department_id`, @general_department_id),
  `period_id` = COALESCE(`period_id`, @current_period_id),
  `document_type` = CASE WHEN `is_expense` = 1 THEN 'expense' ELSE COALESCE(`document_type`, 'vendor_bill') END
WHERE `company_id` IS NULL
   OR (`department_id` IS NULL AND @general_department_id IS NOT NULL)
   OR (`period_id` IS NULL AND @current_period_id IS NOT NULL)
   OR `document_type` IS NULL;

INSERT INTO `ledger_accounts`
  (`company_id`, `code`, `name`, `type`, `account_class`, `is_active`, `allow_direct_posting`, `created_at`, `updated_at`)
SELECT @default_company_id, v.code, v.name, 'asset', 'asset', 1, 1, NOW(), NOW()
FROM (
  SELECT '1010' AS code, 'Operating Bank' AS name UNION ALL
  SELECT '1020', 'Card Clearing' UNION ALL
  SELECT '1030', 'Cheque Clearing' UNION ALL
  SELECT '1040', 'Other Clearing'
) v
WHERE NOT EXISTS (SELECT 1 FROM `ledger_accounts` la WHERE la.code = v.code);

SET @operating_bank_ledger_id := (SELECT `id` FROM `ledger_accounts` WHERE `code` = '1010' LIMIT 1);
SET @cash_ledger_id := (SELECT `id` FROM `ledger_accounts` WHERE `code` = '1000' LIMIT 1);

INSERT INTO `bank_accounts`
  (`company_id`, `ledger_account_id`, `branch_id`, `name`, `code`, `account_type`, `bank_name`, `account_number_last4`, `currency_code`, `is_default`, `is_active`, `opening_balance`, `opening_balance_date`, `created_at`, `updated_at`)
SELECT
  @default_company_id,
  COALESCE(@cash_ledger_id, @operating_bank_ledger_id),
  NULL,
  'Operating Account',
  'OPERATING',
  'checking',
  'Primary Bank',
  '0000',
  'QAR',
  1,
  1,
  0,
  STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-01-01'), '%Y-%m-%d'),
  NOW(),
  NOW()
WHERE @default_company_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `bank_accounts`
    WHERE `company_id` = @default_company_id AND `code` = 'OPERATING'
  );

UPDATE `bank_accounts`
SET `ledger_account_id` = @operating_bank_ledger_id,
    `updated_at` = NOW()
WHERE @operating_bank_ledger_id IS NOT NULL
  AND (`ledger_account_id` IS NULL OR `ledger_account_id` = @cash_ledger_id);

SET @default_bank_account_id := (
  SELECT `id` FROM `bank_accounts`
  WHERE `company_id` = @default_company_id AND `code` = 'OPERATING'
  LIMIT 1
);

UPDATE `finance_settings`
SET
  `default_company_id` = @default_company_id,
  `default_bank_account_id` = @default_bank_account_id,
  `updated_at` = NOW()
WHERE `id` = 1;

UPDATE `ap_payments`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `department_id` = COALESCE(`department_id`, @general_department_id),
  `period_id` = COALESCE(`period_id`, @current_period_id),
  `bank_account_id` = COALESCE(`bank_account_id`, @default_bank_account_id)
WHERE `company_id` IS NULL
   OR (`department_id` IS NULL AND @general_department_id IS NOT NULL)
   OR (`period_id` IS NULL AND @current_period_id IS NOT NULL)
   OR (`bank_account_id` IS NULL AND @default_bank_account_id IS NOT NULL);

UPDATE `subledger_entries`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `department_id` = COALESCE(`department_id`, @general_department_id),
  `period_id` = COALESCE(`period_id`, @current_period_id)
WHERE `company_id` IS NULL
   OR (`department_id` IS NULL AND @general_department_id IS NOT NULL)
   OR (`period_id` IS NULL AND @current_period_id IS NOT NULL);

UPDATE `gl_batches`
SET
  `company_id` = COALESCE(`company_id`, @default_company_id),
  `period_id` = COALESCE(`period_id`, @current_period_id)
WHERE `company_id` IS NULL OR (`period_id` IS NULL AND @current_period_id IS NOT NULL);

-- ---------------------------------------------------------------------------
-- 5. Seed period close checklist rows
-- ---------------------------------------------------------------------------

UPDATE `closing_checklists`
SET
  `task_key` = CASE `task_name`
    WHEN 'All active bank accounts reconciled through period end' THEN 'bank_accounts_reconciled'
    WHEN 'No open bank reconciliation runs for the period' THEN 'no_open_bank_reconciliation_runs'
    WHEN 'No unposted GL batches for the period' THEN 'no_unposted_gl_batches'
    WHEN 'No draft manual journals dated in the period' THEN 'no_draft_manual_journals'
    WHEN 'No draft AP bills dated in the period' THEN 'no_draft_ap_bills'
    WHEN 'No draft expenses or reimbursements dated in the period' THEN 'no_draft_expenses'
    WHEN 'No submitted or partially approved expenses dated in the period' THEN 'no_pending_expense_approvals'
    WHEN 'No AP documents with missing company or period assignment' THEN 'no_ap_documents_missing_dimensions'
    WHEN 'No out-of-balance GL batches for the period' THEN 'no_unbalanced_gl_batches'
    WHEN 'AP aging reviewed' THEN 'ap_aging_reviewed'
    WHEN 'AR aging reviewed' THEN 'ar_aging_reviewed'
    WHEN 'Inventory valuation reviewed' THEN 'inventory_valuation_reviewed'
    WHEN 'Purchase accruals reviewed' THEN 'purchase_accruals_reviewed'
    WHEN 'Payroll journals reviewed and posted' THEN 'payroll_journals_reviewed'
    WHEN 'Trial balance reviewed' THEN 'trial_balance_reviewed'
    WHEN 'P&L reviewed' THEN 'profit_and_loss_reviewed'
    WHEN 'Balance sheet reviewed' THEN 'balance_sheet_reviewed'
    WHEN 'Tax/VAT review completed' THEN 'tax_review_completed'
    WHEN 'Financial statements approved' THEN 'financial_statements_approved'
    ELSE LOWER(REPLACE(REPLACE(REPLACE(`task_name`, '/', ''), '&', ''), ' ', '_'))
  END,
  `task_type` = CASE `task_name`
    WHEN 'All active bank accounts reconciled through period end' THEN 'system'
    WHEN 'No open bank reconciliation runs for the period' THEN 'system'
    WHEN 'No unposted GL batches for the period' THEN 'system'
    WHEN 'No draft manual journals dated in the period' THEN 'system'
    WHEN 'No draft AP bills dated in the period' THEN 'system'
    WHEN 'No draft expenses or reimbursements dated in the period' THEN 'system'
    WHEN 'No submitted or partially approved expenses dated in the period' THEN 'system'
    WHEN 'No AP documents with missing company or period assignment' THEN 'system'
    WHEN 'No out-of-balance GL batches for the period' THEN 'system'
    ELSE 'manual'
  END,
  `is_required` = 1
WHERE `task_key` IS NULL;

WITH tasks AS (
  SELECT 'bank_accounts_reconciled' AS task_key, 'All active bank accounts reconciled through period end' AS task_name, 'system' AS task_type, 1 AS is_required UNION ALL
  SELECT 'no_open_bank_reconciliation_runs', 'No open bank reconciliation runs for the period', 'system', 1 UNION ALL
  SELECT 'no_unposted_gl_batches', 'No unposted GL batches for the period', 'system', 1 UNION ALL
  SELECT 'no_draft_manual_journals', 'No draft manual journals dated in the period', 'system', 1 UNION ALL
  SELECT 'no_draft_ap_bills', 'No draft AP bills dated in the period', 'system', 1 UNION ALL
  SELECT 'no_draft_expenses', 'No draft expenses or reimbursements dated in the period', 'system', 1 UNION ALL
  SELECT 'no_pending_expense_approvals', 'No submitted or partially approved expenses dated in the period', 'system', 1 UNION ALL
  SELECT 'no_ap_documents_missing_dimensions', 'No AP documents with missing company or period assignment', 'system', 1 UNION ALL
  SELECT 'no_unbalanced_gl_batches', 'No out-of-balance GL batches for the period', 'system', 1 UNION ALL
  SELECT 'ap_aging_reviewed', 'AP aging reviewed', 'manual', 1 UNION ALL
  SELECT 'ar_aging_reviewed', 'AR aging reviewed', 'manual', 1 UNION ALL
  SELECT 'inventory_valuation_reviewed', 'Inventory valuation reviewed', 'manual', 1 UNION ALL
  SELECT 'purchase_accruals_reviewed', 'Purchase accruals reviewed', 'manual', 1 UNION ALL
  SELECT 'payroll_journals_reviewed', 'Payroll journals reviewed and posted', 'manual', 1 UNION ALL
  SELECT 'trial_balance_reviewed', 'Trial balance reviewed', 'manual', 1 UNION ALL
  SELECT 'profit_and_loss_reviewed', 'P&L reviewed', 'manual', 1 UNION ALL
  SELECT 'balance_sheet_reviewed', 'Balance sheet reviewed', 'manual', 1 UNION ALL
  SELECT 'tax_review_completed', 'Tax/VAT review completed', 'manual', 1 UNION ALL
  SELECT 'financial_statements_approved', 'Financial statements approved', 'manual', 1
)
INSERT INTO `closing_checklists`
  (`company_id`, `period_id`, `task_key`, `task_name`, `task_type`, `is_required`, `status`, `completed_at`, `completed_by`, `notes`, `result_payload`, `created_at`, `updated_at`)
SELECT
  ap.`company_id`,
  ap.`id`,
  t.`task_key`,
  t.`task_name`,
  t.`task_type`,
  t.`is_required`,
  'pending',
  NULL,
  NULL,
  NULL,
  NULL,
  NOW(),
  NOW()
FROM `accounting_periods` ap
CROSS JOIN tasks t
WHERE NOT EXISTS (
  SELECT 1
  FROM `closing_checklists` cc
  WHERE cc.`company_id` = ap.`company_id`
    AND cc.`period_id` = ap.`id`
    AND cc.`task_key` = t.`task_key`
);

-- ---------------------------------------------------------------------------
-- 6. Migration bookkeeping
-- ---------------------------------------------------------------------------

SET @migration_batch := (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_01_27_000010_create_ledger_tables', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_01_27_000010_create_ledger_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_13_000023_create_accounting_foundation_tables', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_13_000023_create_accounting_foundation_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_13_000024_add_statement_import_id_to_bank_reconciliation_runs', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_13_000024_add_statement_import_id_to_bank_reconciliation_runs');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_13_000025_extend_closing_checklists_for_period_close', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_13_000025_extend_closing_checklists_for_period_close');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_13_000026_create_accounting_account_mappings_and_extend_payments', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_13_000026_create_accounting_account_mappings_and_extend_payments');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_24_000027_add_matched_bank_transaction_id_to_bank_transactions', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_24_000027_add_matched_bank_transaction_id_to_bank_transactions');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_24_000028_complete_accounting_remaining_sections', @migration_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_24_000028_complete_accounting_remaining_sections');

SET FOREIGN_KEY_CHECKS = 1;
