-- 2026_04_19_000002_add_subledger_entries_source_event_unique

ALTER TABLE subledger_entries
  ADD UNIQUE INDEX subledger_entries_source_event_unique (source_type, source_id, event);
