-- 2026_04_19_000005_add_journal_entries_immutability_trigger

DROP TRIGGER IF EXISTS journal_entries_immutable_posted;

DELIMITER $$
CREATE TRIGGER journal_entries_immutable_posted
BEFORE UPDATE ON journal_entries
FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Posted journal entries are immutable.';
    END IF;
END$$
DELIMITER ;
