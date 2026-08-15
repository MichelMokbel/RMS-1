-- RMS-1 HR Module Phase 1
-- Target: MySQL 8.0+, existing RMS schema
-- Generated from Laravel migrations 2026_08_11_000001 through 000006.
-- Run exactly once after taking a verified database backup.
--
-- Prerequisites:
--   accounting_companies, branches, departments, users
--   roles, permissions, role_has_permissions
--   document_sequences, ledger_accounts, accounting_account_mappings
--   journal_entries, bank_accounts, bank_transactions, migrations
--
-- Accounting behavior:
--   Payroll posting: Dr salary/allowance expense; Cr payroll/deduction liabilities.
--   Payroll payment: Dr payroll payable; Cr selected bank ledger.
--   Existing account mappings are never overwritten.

SET NAMES utf8mb4;
SET time_zone = '+03:00';

create table `hr_employees` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_number` varchar(50) not null, `user_id` bigint unsigned null, `manager_id` bigint unsigned null, `current_branch_id` int unsigned null, `current_department_id` bigint unsigned null, `legal_first_name` varchar(100) not null, `legal_middle_name` varchar(100) null, `legal_last_name` varchar(100) not null, `display_name` varchar(200) not null, `preferred_name` varchar(100) null, `work_email` varchar(255) null, `personal_email` varchar(255) null, `work_phone` varchar(40) null, `personal_phone` varchar(40) null, `date_of_birth` date null, `nationality` varchar(100) null, `gender` varchar(30) null, `qid_number` text null, `passport_number` text null, `address` json null, `emergency_contact` json null, `job_title` varchar(150) null, `employment_type` varchar(40) not null default 'full_time', `employment_status` varchar(30) not null default 'onboarding', `hire_date` date not null, `probation_end_date` date null, `notice_date` date null, `exit_date` date null, `exit_reason` text null, `metadata` json null, `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_employees` add unique `hr_employee_company_number_unique`(`company_id`, `employee_number`);
alter table `hr_employees` add unique `hr_employee_company_user_unique`(`company_id`, `user_id`);
alter table `hr_employees` add index `hr_employee_company_status_idx`(`company_id`, `employment_status`);
alter table `hr_employees` add index `hr_employee_company_branch_idx`(`company_id`, `current_branch_id`);
alter table `hr_employees` add index `hr_employee_company_department_idx`(`company_id`, `current_department_id`);
alter table `hr_employees` add index `hr_employee_manager_idx`(`manager_id`);
create table `hr_employee_assignments` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `branch_id` int unsigned null, `department_id` bigint unsigned null, `manager_id` bigint unsigned null, `job_title` varchar(150) null, `employment_type` varchar(40) not null default 'full_time', `effective_from` date not null, `effective_to` date null, `is_primary` tinyint(1) not null default '1', `notes` text null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_employee_assignments` add constraint `hr_employee_assignments_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_employee_assignments` add unique `hr_assignment_employee_start_unique`(`employee_id`, `effective_from`);
alter table `hr_employee_assignments` add index `hr_assignment_company_branch_end_idx`(`company_id`, `branch_id`, `effective_to`);
alter table `hr_employee_assignments` add index `hr_assignment_company_department_end_idx`(`company_id`, `department_id`, `effective_to`);
alter table `hr_employee_assignments` add index `hr_assignment_manager_end_idx`(`manager_id`, `effective_to`);
create table `hr_compensation_packages` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `effective_from` date not null, `effective_to` date null, `currency` char(3) not null default 'QAR', `pay_frequency` varchar(30) not null default 'monthly', `proration_divisor` decimal(8, 2) not null default '30', `bank_name` varchar(150) null, `beneficiary_name` text null, `bank_account_number` text null, `iban` text null, `swift_code` text null, `is_active` tinyint(1) not null default '1', `notes` text null, `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_compensation_packages` add constraint `hr_compensation_packages_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_compensation_packages` add unique `hr_comp_package_employee_start_unique`(`employee_id`, `effective_from`);
alter table `hr_compensation_packages` add index `hr_comp_package_company_dates_idx`(`company_id`, `effective_from`, `effective_to`);
create table `hr_compensation_components` (`id` bigint unsigned not null auto_increment primary key, `compensation_package_id` bigint unsigned not null, `code` varchar(60) not null, `name` varchar(120) not null, `category` varchar(40) not null, `amount_minor` bigint not null, `is_taxable` tinyint(1) not null default '0', `is_active` tinyint(1) not null default '1', `metadata` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_compensation_components` add constraint `hr_compensation_components_compensation_package_id_foreign` foreign key (`compensation_package_id`) references `hr_compensation_packages` (`id`) on delete cascade;
alter table `hr_compensation_components` add unique `hr_comp_component_package_code_unique`(`compensation_package_id`, `code`);
create table `hr_document_types` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `code` varchar(60) not null, `name` varchar(120) not null, `description` text null, `is_required` tinyint(1) not null default '0', `required_for` json null, `requires_issue_date` tinyint(1) not null default '0', `requires_expiry_date` tinyint(1) not null default '0', `expiry_warning_days` json null, `is_sensitive` tinyint(1) not null default '1', `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_document_types` add unique `hr_document_type_company_code_unique`(`company_id`, `code`);
alter table `hr_document_types` add index `hr_document_type_company_active_idx`(`company_id`, `is_active`);
create table `hr_documents` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `document_type_id` bigint unsigned not null, `document_number` text null, `issue_date` date null, `expiry_date` date null, `issuing_authority` varchar(150) null, `status` varchar(30) not null default 'pending', `current_version_id` bigint unsigned null, `verified_at` timestamp null, `verified_by` bigint unsigned null, `notes` text null, `archived_at` timestamp null, `archived_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_documents` add constraint `hr_documents_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_documents` add constraint `hr_documents_document_type_id_foreign` foreign key (`document_type_id`) references `hr_document_types` (`id`) on delete restrict;
alter table `hr_documents` add index `hr_document_company_status_idx`(`company_id`, `status`);
alter table `hr_documents` add index `hr_document_company_expiry_idx`(`company_id`, `expiry_date`);
alter table `hr_documents` add index `hr_document_employee_type_idx`(`employee_id`, `document_type_id`);
create table `hr_document_versions` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `document_id` bigint unsigned not null, `version_number` int unsigned not null, `storage_disk` varchar(60) not null, `object_key` varchar(512) not null, `original_name` varchar(255) not null, `mime_type` varchar(150) not null, `size_bytes` bigint unsigned not null, `sha256` char(64) not null, `scan_status` varchar(30) not null default 'pending', `scan_metadata` json null, `uploaded_by` bigint unsigned null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_document_versions` add constraint `hr_document_versions_document_id_foreign` foreign key (`document_id`) references `hr_documents` (`id`) on delete restrict;
alter table `hr_document_versions` add unique `hr_document_version_number_unique`(`document_id`, `version_number`);
alter table `hr_document_versions` add unique `hr_document_version_object_key_unique`(`company_id`, `object_key`);
alter table `hr_document_versions` add index `hr_document_version_company_hash_idx`(`company_id`, `sha256`);
create table `hr_leave_types` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `code` varchar(60) not null, `name` varchar(120) not null, `description` text null, `is_paid` tinyint(1) not null default '1', `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_leave_types` add unique `hr_leave_type_company_code_unique`(`company_id`, `code`);
alter table `hr_leave_types` add index `hr_leave_type_company_active_idx`(`company_id`, `is_active`);
create table `hr_leave_policies` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `leave_type_id` bigint unsigned not null, `name` varchar(150) not null, `effective_from` date not null, `effective_to` date null, `entitlement_days` decimal(8, 2) not null default '0', `accrual_rate_days` decimal(8, 4) not null default '0', `accrual_frequency` varchar(30) not null default 'annual', `carryover_limit_days` decimal(8, 2) not null default '0', `max_balance_days` decimal(8, 2) null, `waiting_period_days` int unsigned not null default '0', `allow_negative` tinyint(1) not null default '0', `requires_attachment` tinyint(1) not null default '0', `counts_calendar_days` tinyint(1) not null default '1', `is_active` tinyint(1) not null default '1', `rules` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_leave_policies` add constraint `hr_leave_policies_leave_type_id_foreign` foreign key (`leave_type_id`) references `hr_leave_types` (`id`) on delete restrict;
alter table `hr_leave_policies` add index `hr_leave_policy_company_type_active_idx`(`company_id`, `leave_type_id`, `is_active`);
alter table `hr_leave_policies` add index `hr_leave_policy_company_dates_idx`(`company_id`, `effective_from`, `effective_to`);
create table `hr_leave_requests` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `leave_type_id` bigint unsigned not null, `policy_id` bigint unsigned null, `manager_id` bigint unsigned null, `start_date` date not null, `end_date` date not null, `start_portion` varchar(20) not null default 'full', `end_portion` varchar(20) not null default 'full', `requested_days` decimal(8, 2) not null, `status` varchar(30) not null default 'draft', `reason` text null, `attachment_document_id` bigint unsigned null, `submitted_at` timestamp null, `decided_at` timestamp null, `decided_by` bigint unsigned null, `cancelled_at` timestamp null, `cancelled_by` bigint unsigned null, `decision_reason` text null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_leave_requests` add constraint `hr_leave_requests_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_leave_requests` add constraint `hr_leave_requests_leave_type_id_foreign` foreign key (`leave_type_id`) references `hr_leave_types` (`id`) on delete restrict;
alter table `hr_leave_requests` add index `hr_leave_request_company_status_start_idx`(`company_id`, `status`, `start_date`);
alter table `hr_leave_requests` add index `hr_leave_request_employee_dates_idx`(`employee_id`, `start_date`, `end_date`);
alter table `hr_leave_requests` add index `hr_leave_request_manager_status_idx`(`manager_id`, `status`);
create table `hr_leave_request_events` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `leave_request_id` bigint unsigned not null, `from_status` varchar(30) null, `to_status` varchar(30) not null, `action` varchar(50) not null, `actor_id` bigint unsigned null, `reason` text null, `metadata` json null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_leave_request_events` add constraint `hr_leave_request_events_leave_request_id_foreign` foreign key (`leave_request_id`) references `hr_leave_requests` (`id`) on delete restrict;
alter table `hr_leave_request_events` add index `hr_leave_event_company_created_idx`(`company_id`, `created_at`);
create table `hr_leave_ledger_entries` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `leave_type_id` bigint unsigned not null, `policy_id` bigint unsigned null, `leave_request_id` bigint unsigned null, `entry_type` varchar(30) not null, `days` decimal(8, 2) not null, `effective_date` date not null, `source_type` varchar(100) null, `source_id` varchar(100) null, `idempotency_key` varchar(191) null, `balance_after_days` decimal(10, 2) null, `notes` text null, `created_by` bigint unsigned null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_leave_ledger_entries` add constraint `hr_leave_ledger_entries_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_leave_ledger_entries` add constraint `hr_leave_ledger_entries_leave_type_id_foreign` foreign key (`leave_type_id`) references `hr_leave_types` (`id`) on delete restrict;
alter table `hr_leave_ledger_entries` add unique `hr_leave_ledger_company_idempotency_unique`(`company_id`, `idempotency_key`);
alter table `hr_leave_ledger_entries` add index `hr_leave_ledger_employee_type_date_idx`(`employee_id`, `leave_type_id`, `effective_date`);
alter table `hr_leave_ledger_entries` add index `hr_leave_ledger_request_idx`(`leave_request_id`);
create table `hr_payroll_runs` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `run_number` varchar(60) not null, `period_id` bigint unsigned null, `pay_period_start` date not null, `pay_period_end` date not null, `scheduled_payment_date` date null, `currency` char(3) not null default 'QAR', `origin` varchar(20) not null default 'native', `status` varchar(30) not null default 'draft', `is_postable` tinyint(1) not null default '1', `proration_divisor` decimal(8, 2) not null default '30', `description` text null, `prepared_by` bigint unsigned null, `calculated_at` timestamp null, `calculated_by` bigint unsigned null, `approved_at` timestamp null, `approved_by` bigint unsigned null, `posted_at` timestamp null, `posted_by` bigint unsigned null, `paid_at` timestamp null, `paid_by` bigint unsigned null, `rejected_at` timestamp null, `rejected_by` bigint unsigned null, `reversed_at` timestamp null, `reversed_by` bigint unsigned null, `journal_entry_id` bigint unsigned null, `reversal_journal_entry_id` bigint unsigned null, `lock_version` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_runs` add unique `hr_payroll_run_company_number_unique`(`company_id`, `run_number`);
alter table `hr_payroll_runs` add index `hr_payroll_run_company_status_start_idx`(`company_id`, `status`, `pay_period_start`);
alter table `hr_payroll_runs` add index `hr_payroll_run_company_origin_idx`(`company_id`, `origin`);
create table `hr_payroll_results` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `payroll_run_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `assignment_id` bigint unsigned null, `compensation_package_id` bigint unsigned null, `branch_id` int unsigned null, `department_id` bigint unsigned null, `currency` char(3) not null default 'QAR', `basic_minor` bigint not null default '0', `gross_minor` bigint not null default '0', `earnings_minor` bigint not null default '0', `deductions_minor` bigint not null default '0', `net_minor` bigint not null default '0', `calendar_days` decimal(8, 2) not null default '0', `worked_days` decimal(8, 2) not null default '0', `unpaid_leave_days` decimal(8, 2) not null default '0', `proration_divisor` decimal(8, 2) not null default '30', `snapshot` json null, `payslip_document_id` bigint unsigned null, `is_postable` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_results` add constraint `hr_payroll_results_payroll_run_id_foreign` foreign key (`payroll_run_id`) references `hr_payroll_runs` (`id`) on delete restrict;
alter table `hr_payroll_results` add constraint `hr_payroll_results_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_payroll_results` add unique `hr_payroll_result_run_employee_unique`(`payroll_run_id`, `employee_id`);
alter table `hr_payroll_results` add index `hr_payroll_result_dimensions_idx`(`company_id`, `branch_id`, `department_id`);
create table `hr_payroll_result_components` (`id` bigint unsigned not null auto_increment primary key, `payroll_result_id` bigint unsigned not null, `code` varchar(60) not null, `name` varchar(120) not null, `category` varchar(40) not null, `amount_minor` bigint not null, `source_type` varchar(100) null, `source_id` varchar(100) null, `is_taxable` tinyint(1) not null default '0', `snapshot` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_result_components` add constraint `hr_payroll_result_components_payroll_result_id_foreign` foreign key (`payroll_result_id`) references `hr_payroll_results` (`id`) on delete restrict;
alter table `hr_payroll_result_components` add index `hr_payroll_component_result_category_idx`(`payroll_result_id`, `category`);
create table `hr_payroll_adjustments` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `payroll_run_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `type` varchar(30) not null, `code` varchar(60) not null, `description` varchar(200) not null, `amount_minor` bigint unsigned not null, `source_type` varchar(100) null, `source_id` varchar(100) null, `idempotency_key` varchar(191) null, `notes` text null, `created_by` bigint unsigned null, `approved_at` timestamp null, `approved_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_adjustments` add constraint `hr_payroll_adjustments_payroll_run_id_foreign` foreign key (`payroll_run_id`) references `hr_payroll_runs` (`id`) on delete restrict;
alter table `hr_payroll_adjustments` add constraint `hr_payroll_adjustments_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_payroll_adjustments` add unique `hr_payroll_adjustment_company_idempotency_unique`(`company_id`, `idempotency_key`);
alter table `hr_payroll_adjustments` add index `hr_payroll_adjustment_run_employee_idx`(`payroll_run_id`, `employee_id`);
create table `hr_payroll_status_events` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `payroll_run_id` bigint unsigned not null, `from_status` varchar(30) null, `to_status` varchar(30) not null, `actor_id` bigint unsigned null, `reason` text null, `metadata` json null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_status_events` add constraint `hr_payroll_status_events_payroll_run_id_foreign` foreign key (`payroll_run_id`) references `hr_payroll_runs` (`id`) on delete restrict;
alter table `hr_payroll_status_events` add index `hr_payroll_event_company_created_idx`(`company_id`, `created_at`);
create table `hr_payroll_payment_batches` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `payroll_run_id` bigint unsigned not null, `bank_account_id` bigint unsigned not null, `batch_number` varchar(60) not null, `payment_date` date not null, `currency` char(3) not null default 'QAR', `total_minor` bigint unsigned not null default '0', `item_count` int unsigned not null default '0', `status` varchar(30) not null default 'draft', `reference` varchar(120) null, `journal_entry_id` bigint unsigned null, `reversal_journal_entry_id` bigint unsigned null, `bank_transaction_id` bigint unsigned null, `created_by` bigint unsigned null, `processed_at` timestamp null, `processed_by` bigint unsigned null, `reversed_at` timestamp null, `reversed_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_payment_batches` add constraint `hr_payroll_payment_batches_payroll_run_id_foreign` foreign key (`payroll_run_id`) references `hr_payroll_runs` (`id`) on delete restrict;
alter table `hr_payroll_payment_batches` add unique `hr_payroll_payment_company_number_unique`(`company_id`, `batch_number`);
alter table `hr_payroll_payment_batches` add index `hr_payroll_payment_company_status_date_idx`(`company_id`, `status`, `payment_date`);
create table `hr_payroll_payment_batch_items` (`id` bigint unsigned not null auto_increment primary key, `payment_batch_id` bigint unsigned not null, `payroll_result_id` bigint unsigned not null, `employee_id` bigint unsigned not null, `amount_minor` bigint unsigned not null, `beneficiary_name` text null, `bank_account_number` text null, `iban` text null, `payment_reference` varchar(120) null, `status` varchar(30) not null default 'pending', `failure_reason` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_payroll_payment_batch_items` add constraint `hr_payroll_payment_batch_items_payment_batch_id_foreign` foreign key (`payment_batch_id`) references `hr_payroll_payment_batches` (`id`) on delete restrict;
alter table `hr_payroll_payment_batch_items` add constraint `hr_payroll_payment_batch_items_payroll_result_id_foreign` foreign key (`payroll_result_id`) references `hr_payroll_results` (`id`) on delete restrict;
alter table `hr_payroll_payment_batch_items` add constraint `hr_payroll_payment_batch_items_employee_id_foreign` foreign key (`employee_id`) references `hr_employees` (`id`) on delete restrict;
alter table `hr_payroll_payment_batch_items` add unique `hr_payroll_payment_item_result_unique`(`payment_batch_id`, `payroll_result_id`);
create table `hr_import_batches` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `type` varchar(30) not null, `status` varchar(30) not null default 'uploaded', `source_name` varchar(255) not null, `storage_disk` varchar(60) not null, `object_key` varchar(1024) not null, `archive_object_key` varchar(1024) null, `sha256` char(64) not null, `options` json null, `stats` json null, `initiated_by` bigint unsigned null, `initiated_at` timestamp null, `committed_by` bigint unsigned null, `committed_at` timestamp null, `failed_at` timestamp null, `failure_reason` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_import_batches` add unique `hr_import_company_type_hash_unique`(`company_id`, `type`, `sha256`);
alter table `hr_import_batches` add index `hr_import_company_status_idx`(`company_id`, `status`);
create table `hr_import_rows` (`id` bigint unsigned not null auto_increment primary key, `import_batch_id` bigint unsigned not null, `row_number` int unsigned not null, `source_identifier` varchar(191) null, `status` varchar(30) not null default 'pending', `payload` longtext null, `errors` longtext null, `row_hash` char(64) not null, `target_type` varchar(150) null, `target_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_import_rows` add constraint `hr_import_rows_import_batch_id_foreign` foreign key (`import_batch_id`) references `hr_import_batches` (`id`) on delete restrict;
alter table `hr_import_rows` add unique `hr_import_row_batch_number_unique`(`import_batch_id`, `row_number`);
alter table `hr_import_rows` add index `hr_import_row_batch_status_idx`(`import_batch_id`, `status`);
alter table `hr_import_rows` add index `hr_import_row_target_idx`(`target_type`, `target_id`);
create table `hr_audit_logs` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `actor_id` bigint unsigned null, `action` varchar(100) not null, `subject_type` varchar(150) null, `subject_id` bigint unsigned null, `request_id` char(36) null, `ip_address` varchar(45) null, `user_agent` text null, `payload` json null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_audit_logs` add index `hr_audit_company_created_idx`(`company_id`, `created_at`);
alter table `hr_audit_logs` add index `hr_audit_subject_idx`(`subject_type`, `subject_id`);
alter table `hr_audit_logs` add index `hr_audit_actor_created_idx`(`actor_id`, `created_at`);
alter table `hr_audit_logs` add index `hr_audit_request_idx`(`request_id`);
create table `hr_alerts` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `employee_id` bigint unsigned null, `type` varchar(60) not null, `severity` varchar(20) not null default 'warning', `status` varchar(30) not null default 'open', `subject_type` varchar(150) null, `subject_id` bigint unsigned null, `dedupe_key` varchar(191) not null, `due_at` timestamp null, `message` text not null, `metadata` json null, `acknowledged_at` timestamp null, `acknowledged_by` bigint unsigned null, `resolved_at` timestamp null, `resolved_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `hr_alerts` add unique `hr_alert_company_dedupe_unique`(`company_id`, `dedupe_key`);
alter table `hr_alerts` add index `hr_alert_company_status_due_idx`(`company_id`, `status`, `due_at`);
alter table `hr_alerts` add index `hr_alert_employee_status_idx`(`employee_id`, `status`);
alter table `hr_alerts` add index `hr_alert_subject_idx`(`subject_type`, `subject_id`);


-- HR role and permissions
INSERT IGNORE INTO `roles` (`name`, `guard_name`, `created_at`, `updated_at`)
VALUES ('hr', 'web', NOW(), NOW());

INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
('hr.access','web',NOW(),NOW()),
('hr.employees.view','web',NOW(),NOW()),
('hr.employees.manage','web',NOW(),NOW()),
('hr.employees.export','web',NOW(),NOW()),
('hr.documents.view','web',NOW(),NOW()),
('hr.documents.manage','web',NOW(),NOW()),
('hr.documents.download','web',NOW(),NOW()),
('hr.documents.export','web',NOW(),NOW()),
('hr.leave.view','web',NOW(),NOW()),
('hr.leave.manage','web',NOW(),NOW()),
('hr.leave.approve','web',NOW(),NOW()),
('hr.payroll.view','web',NOW(),NOW()),
('hr.payroll.prepare','web',NOW(),NOW()),
('hr.payroll.approve','web',NOW(),NOW()),
('hr.payroll.post','web',NOW(),NOW()),
('hr.payroll.pay','web',NOW(),NOW()),
('hr.payroll.reverse','web',NOW(),NOW()),
('hr.payroll.export','web',NOW(),NOW()),
('hr.reports.view','web',NOW(),NOW()),
('hr.reports.export','web',NOW(),NOW()),
('hr.settings.manage','web',NOW(),NOW()),
('hr.imports.manage','web',NOW(),NOW()),
('hr.audit.view','web',NOW(),NOW());

-- Admin receives every HR permission.
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id FROM `roles` r
JOIN `permissions` p ON p.guard_name='web' AND p.name LIKE 'hr.%'
WHERE r.guard_name='web' AND r.name='admin';

-- HR receives operational access, excluding posting, payment, and reversal.
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id FROM `roles` r
JOIN `permissions` p ON p.guard_name='web'
WHERE r.guard_name='web' AND r.name='hr' AND p.name IN (
'hr.access','hr.employees.view','hr.employees.manage','hr.employees.export',
'hr.documents.view','hr.documents.manage','hr.documents.download','hr.documents.export',
'hr.leave.view','hr.leave.manage','hr.leave.approve',
'hr.payroll.view','hr.payroll.prepare','hr.payroll.approve','hr.payroll.export',
'hr.reports.view','hr.reports.export','hr.settings.manage','hr.imports.manage','hr.audit.view');

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id FROM `roles` r
JOIN `permissions` p ON p.guard_name='web'
WHERE r.guard_name='web' AND r.name='manager'
AND p.name IN ('hr.access','hr.employees.view','hr.leave.view','hr.leave.approve');

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id FROM `roles` r
JOIN `permissions` p ON p.guard_name='web'
WHERE r.guard_name='web' AND r.name='accounting' AND p.name IN (
'hr.access','hr.payroll.view','hr.payroll.post','hr.payroll.pay',
'hr.payroll.reverse','hr.payroll.export','hr.reports.view','hr.reports.export');

-- Payroll chart-of-account defaults. Existing account codes are preserved.
INSERT INTO `ledger_accounts`
(`company_id`,`code`,`name`,`type`,`account_class`,`is_active`,`allow_direct_posting`,`created_at`,`updated_at`)
SELECT NULL,x.code,x.name,x.type,x.type,1,1,NOW(),NOW()
FROM (
 SELECT '1600' code,'Employee Advances' name,'asset' type
 UNION ALL SELECT '2400','Payroll Payable','liability'
 UNION ALL SELECT '2410','Payroll Deductions Payable','liability'
 UNION ALL SELECT '6100','Basic Salary Expense','expense'
 UNION ALL SELECT '6110','Payroll Allowance Expense','expense'
 UNION ALL SELECT '6120','Payroll Overtime Expense','expense'
 UNION ALL SELECT '6130','Payroll Bonus Expense','expense'
) x
WHERE NOT EXISTS (SELECT 1 FROM `ledger_accounts` a WHERE a.code=x.code);

-- Create per-company mappings without replacing existing finance decisions.
INSERT IGNORE INTO `accounting_account_mappings`
(`company_id`,`mapping_key`,`ledger_account_id`,`created_at`,`updated_at`)
SELECT c.id,m.mapping_key,a.id,NOW(),NOW()
FROM `accounting_companies` c
JOIN (
 SELECT 'payroll_basic_expense' mapping_key,'6100' account_code
 UNION ALL SELECT 'payroll_allowance_expense','6110'
 UNION ALL SELECT 'payroll_overtime_expense','6120'
 UNION ALL SELECT 'payroll_bonus_expense','6130'
 UNION ALL SELECT 'payroll_payable','2400'
 UNION ALL SELECT 'payroll_deductions_payable','2410'
 UNION ALL SELECT 'employee_advances_receivable','1600'
) m
JOIN `ledger_accounts` a ON a.code=m.account_code;

-- Database-level append-only protection for retained HR evidence.
DELIMITER $$

DROP TRIGGER IF EXISTS `hr_document_versions_immutable_update`$$
CREATE TRIGGER `hr_document_versions_immutable_update` BEFORE UPDATE ON `hr_document_versions`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_document_versions_immutable_delete`$$
CREATE TRIGGER `hr_document_versions_immutable_delete` BEFORE DELETE ON `hr_document_versions`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DROP TRIGGER IF EXISTS `hr_leave_request_events_immutable_update`$$
CREATE TRIGGER `hr_leave_request_events_immutable_update` BEFORE UPDATE ON `hr_leave_request_events`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_leave_request_events_immutable_delete`$$
CREATE TRIGGER `hr_leave_request_events_immutable_delete` BEFORE DELETE ON `hr_leave_request_events`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DROP TRIGGER IF EXISTS `hr_leave_ledger_entries_immutable_update`$$
CREATE TRIGGER `hr_leave_ledger_entries_immutable_update` BEFORE UPDATE ON `hr_leave_ledger_entries`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_leave_ledger_entries_immutable_delete`$$
CREATE TRIGGER `hr_leave_ledger_entries_immutable_delete` BEFORE DELETE ON `hr_leave_ledger_entries`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DROP TRIGGER IF EXISTS `hr_payroll_status_events_immutable_update`$$
CREATE TRIGGER `hr_payroll_status_events_immutable_update` BEFORE UPDATE ON `hr_payroll_status_events`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_payroll_status_events_immutable_delete`$$
CREATE TRIGGER `hr_payroll_status_events_immutable_delete` BEFORE DELETE ON `hr_payroll_status_events`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DROP TRIGGER IF EXISTS `hr_payroll_result_components_immutable_update`$$
CREATE TRIGGER `hr_payroll_result_components_immutable_update` BEFORE UPDATE ON `hr_payroll_result_components`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_payroll_result_components_immutable_delete`$$
CREATE TRIGGER `hr_payroll_result_components_immutable_delete` BEFORE DELETE ON `hr_payroll_result_components`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DROP TRIGGER IF EXISTS `hr_audit_logs_immutable_update`$$
CREATE TRIGGER `hr_audit_logs_immutable_update` BEFORE UPDATE ON `hr_audit_logs`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$
DROP TRIGGER IF EXISTS `hr_audit_logs_immutable_delete`$$
CREATE TRIGGER `hr_audit_logs_immutable_delete` BEFORE DELETE ON `hr_audit_logs`
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR audit records are append-only'$$

DELIMITER ;

-- Register equivalent Laravel migrations so artisan does not repeat them.
SET @hr_migration_batch := (SELECT COALESCE(MAX(`batch`),0)+1 FROM `migrations`);

INSERT INTO `migrations` (`migration`,`batch`)
SELECT x.migration,@hr_migration_batch
FROM (
 SELECT '2026_08_11_000001_create_hr_people_and_document_tables' migration
 UNION ALL SELECT '2026_08_11_000002_create_hr_leave_tables'
 UNION ALL SELECT '2026_08_11_000003_create_hr_payroll_tables'
 UNION ALL SELECT '2026_08_11_000004_create_hr_import_audit_and_alert_tables'
 UNION ALL SELECT '2026_08_11_000005_seed_hr_roles_and_permissions'
 UNION ALL SELECT '2026_08_11_000006_enforce_hr_append_only_records'
) x
WHERE NOT EXISTS (SELECT 1 FROM `migrations` old WHERE old.migration=x.migration);

-- Deployment verification: expected counts are 23 and 23.
SELECT COUNT(*) AS hr_table_count
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name LIKE 'hr\_%';

SELECT COUNT(*) AS hr_permission_count
FROM `permissions`
WHERE `guard_name`='web' AND `name` LIKE 'hr.%';

SELECT c.code company_code,m.mapping_key,a.code ledger_code,a.name ledger_name
FROM `accounting_account_mappings` m
JOIN `accounting_companies` c ON c.id=m.company_id
JOIN `ledger_accounts` a ON a.id=m.ledger_account_id
WHERE m.mapping_key LIKE 'payroll_%' OR m.mapping_key='employee_advances_receivable'
ORDER BY c.code,m.mapping_key;
