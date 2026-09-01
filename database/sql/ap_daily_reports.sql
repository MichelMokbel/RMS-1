-- Daily AP journals, scheduled-email settings, and sequential document numbers.
-- Matches the two 2026_08_31 AP report migrations.
-- MySQL 8+ / MariaDB 10.4+. Run the entire file in the application database.
-- Back up first. Pause application writes and the Laravel scheduler until complete.
-- Requires CREATE/ALTER ROUTINE and CREATE, ALTER, INDEX, REFERENCES, SELECT, INSERT, UPDATE.
-- Stop on the first error; do not use the mysql client's --force option.
-- DDL auto-commits. The script can resume after interruption, but is not one atomic transaction.
-- Existing report data, email settings and delivery history are preserved.
-- This does not enable email, send messages, or modify accounting postings.

DELIMITER $$

DROP PROCEDURE IF EXISTS rms_install_ap_daily_reports_20260831$$
CREATE PROCEDURE rms_install_ap_daily_reports_20260831()
BEGIN
    DECLARE v_company_id BIGINT UNSIGNED;
    DECLARE v_locked_company_id BIGINT UNSIGNED;
    DECLARE v_report_id BIGINT UNSIGNED;
    DECLARE v_year CHAR(4);
    DECLARE v_next BIGINT UNSIGNED;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_batch INT;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_report_id = NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Select the RMS application database before running this script.';
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('accounting_companies', 'finance_settings', 'subledger_entries', 'document_sequences', 'migrations')) <> 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Required RMS tables are missing. Apply earlier application migrations first.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subledger_entries' AND INDEX_NAME = 'ap_journal_daily_lookup') THEN
        ALTER TABLE subledger_entries ADD INDEX ap_journal_daily_lookup (company_id, entry_date, source_type, status);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_settings' AND COLUMN_NAME = 'ap_report_enabled') THEN
        ALTER TABLE finance_settings ADD COLUMN ap_report_enabled TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_settings' AND COLUMN_NAME = 'ap_report_email') THEN
        ALTER TABLE finance_settings ADD COLUMN ap_report_email VARCHAR(255) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_settings' AND COLUMN_NAME = 'ap_report_company_id') THEN
        ALTER TABLE finance_settings ADD COLUMN ap_report_company_id BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_settings'
          AND CONSTRAINT_NAME = 'finance_settings_ap_report_company_id_foreign') THEN
        ALTER TABLE finance_settings ADD CONSTRAINT finance_settings_ap_report_company_id_foreign
            FOREIGN KEY (ap_report_company_id) REFERENCES accounting_companies (id) ON DELETE RESTRICT;
    END IF;

    CREATE TABLE IF NOT EXISTS ap_daily_journal_reports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        report_date DATE NOT NULL,
        snapshot JSON NOT NULL,
        entry_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        revision INT UNSIGNED NOT NULL DEFAULT 1,
        generated_at DATETIME NOT NULL,
        email_status VARCHAR(255) NOT NULL DEFAULT 'pending',
        recipient VARCHAR(255) NULL,
        sent_at TIMESTAMP NULL DEFAULT NULL,
        emailed_revision INT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        CONSTRAINT ap_daily_journal_reports_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES accounting_companies (id) ON DELETE RESTRICT,
        UNIQUE KEY ap_daily_journal_company_date_unique (company_id, report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_daily_journal_reports' AND COLUMN_NAME = 'document_number') THEN
        ALTER TABLE ap_daily_journal_reports ADD COLUMN document_number VARCHAR(32) NULL;
    END IF;

    -- Reject unexpected existing identifiers instead of silently renumbering them.
    IF EXISTS (SELECT 1 FROM ap_daily_journal_reports
        WHERE document_number IS NOT NULL
          AND (document_number NOT REGEXP '^APJ-[0-9]{4}-[0-9]{4,}$'
            OR SUBSTRING(document_number, 5, 4) <> YEAR(report_date)
            OR CAST(SUBSTRING(document_number, 10) AS UNSIGNED) NOT BETWEEN 1 AND 4294967294)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unexpected existing AP document numbers. Review them before continuing.';
    END IF;
    IF EXISTS (SELECT 1 FROM ap_daily_journal_reports WHERE document_number IS NOT NULL
        GROUP BY company_id, document_number HAVING COUNT(*) > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate existing AP document numbers. No reports have been renumbered.';
    END IF;

    -- Never lower a counter, including after an interrupted deployment or a rollback.
    -- The shared sequence uses branch_id as its namespace; AP journals namespace by company.
    INSERT INTO document_sequences (branch_id, type, year, next_number, created_at, updated_at)
    SELECT company_id, 'ap_daily_journal', CAST(YEAR(report_date) AS CHAR),
           MAX(CAST(SUBSTRING(document_number, 10) AS UNSIGNED)) + 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
      FROM ap_daily_journal_reports
     WHERE document_number IS NOT NULL
     GROUP BY company_id, CAST(YEAR(report_date) AS CHAR)
    ON DUPLICATE KEY UPDATE
        updated_at = IF(document_sequences.next_number < VALUES(next_number), VALUES(updated_at), document_sequences.updated_at),
        next_number = GREATEST(document_sequences.next_number, VALUES(next_number));

    -- Backfill only unnumbered reports, in date order per company/year, 200 per transaction.
    WHILE EXISTS (SELECT 1 FROM ap_daily_journal_reports WHERE document_number IS NULL) DO
        SELECT company_id INTO v_company_id
          FROM ap_daily_journal_reports WHERE document_number IS NULL
         ORDER BY company_id, report_date, id LIMIT 1;

        START TRANSACTION;
        SELECT id INTO v_locked_company_id FROM accounting_companies WHERE id = v_company_id FOR UPDATE;
        SET v_count = 0;

        report_batch: LOOP
            SET v_report_id = NULL;
            SELECT id, CAST(YEAR(report_date) AS CHAR) INTO v_report_id, v_year
              FROM ap_daily_journal_reports
             WHERE company_id = v_company_id AND document_number IS NULL
             ORDER BY report_date, id LIMIT 1 FOR UPDATE;
            IF v_report_id IS NULL THEN
                LEAVE report_batch;
            END IF;

            INSERT INTO document_sequences (branch_id, type, year, next_number, created_at, updated_at)
            VALUES (v_company_id, 'ap_daily_journal', v_year, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE next_number = document_sequences.next_number;

            SELECT GREATEST(next_number, 1) INTO v_next FROM document_sequences
             WHERE branch_id = v_company_id AND type = 'ap_daily_journal' AND year = v_year FOR UPDATE;
            IF v_next >= 4294967295 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AP document sequence exhausted. Report batch rolled back.';
            END IF;

            UPDATE ap_daily_journal_reports
               SET document_number = CONCAT('APJ-', v_year, '-', LPAD(CAST(v_next AS CHAR), GREATEST(4, CHAR_LENGTH(v_next)), '0'))
             WHERE id = v_report_id;
            UPDATE document_sequences SET next_number = v_next + 1, updated_at = CURRENT_TIMESTAMP
             WHERE branch_id = v_company_id AND type = 'ap_daily_journal' AND year = v_year;

            SET v_count = v_count + 1;
            IF v_count >= 200 THEN
                LEAVE report_batch;
            END IF;
        END LOOP;
        COMMIT;
    END WHILE;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_daily_journal_reports'
          AND COLUMN_NAME = 'document_number' AND IS_NULLABLE = 'YES') THEN
        ALTER TABLE ap_daily_journal_reports MODIFY COLUMN document_number VARCHAR(32) NOT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_daily_journal_reports'
          AND INDEX_NAME = 'ap_daily_journal_company_number_unique') THEN
        ALTER TABLE ap_daily_journal_reports ADD UNIQUE KEY ap_daily_journal_company_number_unique (company_id, document_number);
    END IF;

    -- Record only these migrations, after every schema and data step has succeeded.
    -- This prevents a later `php artisan migrate` from reapplying the first migration.
    START TRANSACTION;
    SELECT COALESCE(MAX(batch), 0) + 1 INTO v_batch FROM migrations;
    INSERT INTO migrations (migration, batch)
    SELECT '2026_08_31_170000_add_daily_ap_journal_reports', v_batch
     WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_31_170000_add_daily_ap_journal_reports');
    INSERT INTO migrations (migration, batch)
    SELECT '2026_08_31_180000_number_daily_ap_journal_reports', v_batch
     WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_31_180000_number_daily_ap_journal_reports');
    COMMIT;

    SELECT 'AP daily report SQL completed successfully' AS result,
           (SELECT COUNT(*) FROM ap_daily_journal_reports) AS numbered_reports;
END$$

CALL rms_install_ap_daily_reports_20260831()$$
DROP PROCEDURE rms_install_ap_daily_reports_20260831$$

DELIMITER ;

-- After success, deploy the matching application code and resume the scheduler.
-- Configure company, recipient and enable delivery in Settings > Finance.
-- The scheduler must already call `php artisan schedule:run` every minute.
