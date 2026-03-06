#!/usr/bin/env python3
"""
Generate deterministic MySQL reconciliation SQL for AR invoice payments from
an unpaid customer statement CSV.

Locked behavior:
- Scope: branch invoices (type='invoice') with issue_date <= as-of date and status <> 'voided'.
- Invoices listed in statement remain unpaid/partially-paid by statement intent.
- Invoices not listed in statement are considered fully paid.
- Keep invoice total fields untouched; only adjust paid/balance/status/updated_at.
- Report missing docs and amount mismatches; do not abort SQL execution for them.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Dict, Iterable, List, Sequence


COLLATION = "utf8mb4_unicode_ci"
REQUIRED_HEADERS = ["Customer", "Document No", "Date", "Amount", "Paid", "Balance", "Aging"]

DEFAULT_STATEMENT_SOURCE = Path("docs/csv/all_customers_statement_2026-03-01_07_55PM.csv")
DEFAULT_SALES_SOURCE = Path("docs/csv/Sales_entry_dailyreport_2026-03-01_07_52PM.csv")
DEFAULT_OUTPUT = Path(
    "database/seeders/sql/ar_invoice_payments_reconcile_from_all_customers_statement_2026_03_01_07_55PM.sql"
)
DEFAULT_AS_OF_DATE = dt.date(2026, 3, 1)

EXPECTED_FACTS = {
    "statement_rows": 525,
    "statement_distinct_docs": 525,
    "statement_nonzero_paid_rows": 1,
    "overlap_with_sales_docs": 232,
}


def die(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def normalize_spaces(value: str) -> str:
    return " ".join(value.strip().split())


def normalize_doc(value: str) -> str:
    return normalize_spaces(value)


def parse_decimal(raw: str) -> Decimal:
    text = normalize_spaces(raw).replace(",", "")
    if text == "":
        return Decimal("0")
    try:
        return Decimal(text)
    except InvalidOperation:
        die(f"Invalid numeric value: {raw!r}")


def money_to_cents(amount: Decimal) -> int:
    return int((amount * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def parse_statement_datetime(raw: str) -> dt.datetime:
    text = normalize_spaces(raw)
    if text == "":
        die("Statement Date cannot be empty.")
    formats = [
        "%Y-%m-%d %H:%M:%S.%f",
        "%Y-%m-%d %H:%M:%S",
        "%Y-%m-%d",
    ]
    for fmt in formats:
        try:
            return dt.datetime.strptime(text, fmt)
        except ValueError:
            continue
    die(f"Invalid Date value in statement CSV: {raw!r}")


def sql_quote(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = value.replace("\\", "\\\\").replace("'", "''")
    return f"'{escaped}'"


def sql_int(value: int) -> str:
    return str(int(value))


def chunks[T](rows: Sequence[T], size: int) -> Iterable[Sequence[T]]:
    for i in range(0, len(rows), size):
        yield rows[i : i + size]


def append_insert(
    out: List[str],
    table: str,
    columns: Sequence[str],
    values_rows: Sequence[Sequence[str]],
    chunk_size: int = 250,
) -> None:
    if not values_rows:
        return
    cols = ", ".join(columns)
    for group in chunks(values_rows, chunk_size):
        out.append(f"INSERT INTO {table} ({cols}) VALUES")
        out.append(",\n".join(f"({', '.join(row)})" for row in group) + ";")


@dataclass(frozen=True)
class StatementRow:
    source_row_num: int
    customer_name: str
    document_no: str
    document_no_norm: str
    statement_date: str
    statement_amount_cents: int
    statement_paid_cents: int
    statement_balance_cents: int


def load_sales_docs(path: Path) -> set[str]:
    if not path.exists():
        return set()
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None or "Document No" not in reader.fieldnames:
            return set()
        docs = set()
        for row in reader:
            doc = normalize_doc(row.get("Document No", ""))
            if doc:
                docs.add(doc)
        return docs


def load_statement(
    statement_csv: Path,
    sales_csv: Path,
    strict_facts: bool,
) -> tuple[List[StatementRow], Dict[str, int | str]]:
    if not statement_csv.exists():
        die(f"Statement CSV file not found: {statement_csv}")

    rows: List[StatementRow] = []
    skipped_missing_document_no_rows = 0

    with statement_csv.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            die("Statement CSV has no header row.")

        headers = [h.strip() for h in reader.fieldnames]
        missing = [h for h in REQUIRED_HEADERS if h not in headers]
        if missing:
            die(f"Missing required statement CSV headers: {', '.join(missing)}")

        for source_row_num, row in enumerate(reader, start=2):
            document_no = normalize_doc(row["Document No"])
            customer_name = normalize_spaces(row["Customer"])

            if document_no == "" and customer_name == "":
                continue
            if document_no == "":
                skipped_missing_document_no_rows += 1
                continue

            statement_date = parse_statement_datetime(row["Date"]).isoformat(sep=" ")
            amount_cents = money_to_cents(parse_decimal(row["Amount"]))
            paid_cents = money_to_cents(parse_decimal(row["Paid"]))
            balance_cents = money_to_cents(parse_decimal(row["Balance"]))

            rows.append(
                StatementRow(
                    source_row_num=source_row_num,
                    customer_name=customer_name,
                    document_no=document_no,
                    document_no_norm=document_no.lower(),
                    statement_date=statement_date,
                    statement_amount_cents=amount_cents,
                    statement_paid_cents=paid_cents,
                    statement_balance_cents=balance_cents,
                )
            )

    if not rows:
        die("No statement rows found.")

    doc_counts: Dict[str, int] = {}
    for row in rows:
        doc_counts[row.document_no] = doc_counts.get(row.document_no, 0) + 1

    duplicates = {k: v for k, v in doc_counts.items() if v > 1}
    if duplicates:
        sample = ", ".join(f"{k}({v})" for k, v in list(sorted(duplicates.items()))[:10])
        die(f"Duplicate Document No values in statement CSV: {sample}")

    sales_docs = load_sales_docs(sales_csv)
    statement_docs = {r.document_no for r in rows}

    facts: Dict[str, int | str] = {
        "statement_rows": len(rows),
        "statement_distinct_docs": len(statement_docs),
        "statement_nonzero_paid_rows": sum(1 for r in rows if r.statement_paid_cents != 0),
        "overlap_with_sales_docs": len(statement_docs.intersection(sales_docs)),
        "skipped_missing_document_no_rows": skipped_missing_document_no_rows,
    }

    if strict_facts:
        for key, expected_value in EXPECTED_FACTS.items():
            actual = facts.get(key)
            if actual != expected_value:
                die(f"Source fact mismatch for {key}: expected {expected_value!r}, got {actual!r}")

    return rows, facts


def render_sql(
    statement_source: Path,
    rows: List[StatementRow],
    facts: Dict[str, int | str],
    branch_id: int,
    as_of_date: dt.date,
) -> str:
    generated_at = dt.datetime.now().replace(microsecond=0).isoformat()
    out: List[str] = []

    out.extend(
        [
            "-- Generated SQL: AR invoice payments reconciliation from unpaid statement CSV",
            f"-- Source file: {statement_source}",
            f"-- Generated at: {generated_at}",
            f"-- Branch ID: {branch_id}",
            f"-- As-of date (inclusive): {as_of_date.isoformat()}",
            f"-- Statement rows: {facts['statement_rows']}",
            f"-- Distinct statement documents: {facts['statement_distinct_docs']}",
            f"-- Statement rows with non-zero paid: {facts['statement_nonzero_paid_rows']}",
            f"-- Overlap with sales import docs: {facts['overlap_with_sales_docs']}",
            f"-- Skipped rows with missing Document No: {facts['skipped_missing_document_no_rows']}",
            "-- Reconciliation rule: statement-listed invoices stay unpaid/partially-paid by statement intent; all other in-scope invoices become paid",
            "",
            "START TRANSACTION;",
            "",
            "SET @baseline_paid_updates := 0;",
            "SET @statement_override_updates := 0;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_unpaid_statement;",
            "CREATE TEMPORARY TABLE tmp_unpaid_statement (",
            "  source_row_num INT NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            "  document_no VARCHAR(64) NOT NULL,",
            f"  document_no_norm VARCHAR(64) NOT NULL COLLATE {COLLATION},",
            "  statement_date DATETIME NOT NULL,",
            "  statement_amount_cents BIGINT NOT NULL,",
            "  statement_paid_cents BIGINT NOT NULL,",
            "  statement_balance_cents BIGINT NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  UNIQUE KEY uq_tmp_unpaid_statement_document_no (document_no),",
            "  KEY idx_tmp_unpaid_statement_document_no_norm (document_no_norm)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    value_rows = [
        [
            sql_int(r.source_row_num),
            sql_quote(r.customer_name),
            sql_quote(r.document_no),
            sql_quote(r.document_no_norm),
            sql_quote(r.statement_date),
            sql_int(r.statement_amount_cents),
            sql_int(r.statement_paid_cents),
            sql_int(r.statement_balance_cents),
        ]
        for r in rows
    ]
    append_insert(
        out,
        "tmp_unpaid_statement",
        [
            "source_row_num",
            "customer_name",
            "document_no",
            "document_no_norm",
            "statement_date",
            "statement_amount_cents",
            "statement_paid_cents",
            "statement_balance_cents",
        ],
        value_rows,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_scope_invoices;",
            "CREATE TEMPORARY TABLE tmp_scope_invoices AS",
            "SELECT",
            "  ai.id,",
            "  ai.invoice_number,",
            f"  LOWER(TRIM(ai.invoice_number)) COLLATE {COLLATION} AS invoice_number_norm,",
            "  ai.total_cents,",
            "  ai.paid_total_cents,",
            "  ai.balance_cents,",
            "  ai.status,",
            "  ai.issue_date",
            "FROM ar_invoices ai",
            f"WHERE ai.branch_id = {branch_id}",
            "  AND ai.type = 'invoice'",
            "  AND ai.status <> 'voided'",
            "  AND ai.issue_date IS NOT NULL",
            f"  AND ai.issue_date <= {sql_quote(as_of_date.isoformat())};",
            "ALTER TABLE tmp_scope_invoices",
            "  ADD PRIMARY KEY (id),",
            "  ADD KEY idx_tmp_scope_invoices_invoice_number_norm (invoice_number_norm),",
            "  ADD KEY idx_tmp_scope_invoices_status (status);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_unpaid_match;",
            "CREATE TEMPORARY TABLE tmp_unpaid_match AS",
            "SELECT",
            "  s.source_row_num,",
            "  s.customer_name,",
            "  s.document_no,",
            "  s.document_no_norm,",
            "  s.statement_date,",
            "  s.statement_amount_cents,",
            "  s.statement_paid_cents,",
            "  s.statement_balance_cents,",
            "  i.id AS invoice_id,",
            "  i.total_cents AS invoice_total_cents,",
            "  CASE",
            "    WHEN i.id IS NULL THEN 'missing_invoice'",
            "    ELSE 'matched'",
            "  END AS match_status,",
            "  LEAST(GREATEST(s.statement_paid_cents, 0), COALESCE(i.total_cents, 0)) AS normalized_paid_cents,",
            "  COALESCE(i.total_cents, 0) - LEAST(GREATEST(s.statement_paid_cents, 0), COALESCE(i.total_cents, 0)) AS normalized_balance_cents",
            "FROM tmp_unpaid_statement s",
            "LEFT JOIN tmp_scope_invoices i",
            "  ON i.invoice_number_norm = s.document_no_norm;",
            "ALTER TABLE tmp_unpaid_match",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_unpaid_match_status (match_status),",
            "  ADD KEY idx_tmp_unpaid_match_invoice_id (invoice_id);",
            "",
            "-- Baseline: all in-scope invoices are paid unless overridden by statement rows.",
            "UPDATE ar_invoices ai",
            "JOIN tmp_scope_invoices i ON i.id = ai.id",
            "SET",
            "  ai.status = 'paid',",
            "  ai.paid_total_cents = ai.total_cents,",
            "  ai.balance_cents = 0,",
            "  ai.updated_at = NOW();",
            "SET @baseline_paid_updates := ROW_COUNT();",
            "",
            "-- Override with statement-listed invoices.",
            "UPDATE ar_invoices ai",
            "JOIN tmp_unpaid_match m",
            "  ON m.match_status = 'matched'",
            " AND m.invoice_id = ai.id",
            "SET",
            "  ai.paid_total_cents = m.normalized_paid_cents,",
            "  ai.balance_cents = m.normalized_balance_cents,",
            "  ai.status = CASE",
            "    WHEN m.normalized_balance_cents = 0 THEN 'paid'",
            "    WHEN m.normalized_paid_cents > 0 THEN 'partially_paid'",
            "    ELSE 'issued'",
            "  END,",
            "  ai.updated_at = NOW();",
            "SET @statement_override_updates := ROW_COUNT();",
            "",
            "SET @statement_rows_loaded := (SELECT COUNT(*) FROM tmp_unpaid_statement);",
            "SET @statement_distinct_docs := (SELECT COUNT(DISTINCT document_no) FROM tmp_unpaid_statement);",
            "SET @scope_invoices_count := (SELECT COUNT(*) FROM tmp_scope_invoices);",
            "SET @missing_invoice_rows := (",
            "  SELECT COUNT(*) FROM tmp_unpaid_match WHERE match_status = 'missing_invoice'",
            ");",
            "SET @amount_mismatch_rows := (",
            "  SELECT COUNT(*) FROM tmp_unpaid_match",
            "  WHERE match_status = 'matched' AND statement_amount_cents <> invoice_total_cents",
            ");",
            "SET @statement_sum_mismatch_rows := (",
            "  SELECT COUNT(*) FROM tmp_unpaid_match",
            "  WHERE match_status = 'matched' AND (statement_paid_cents + statement_balance_cents) <> invoice_total_cents",
            ");",
            "",
            "-- Summary",
            "SELECT",
            "  @statement_rows_loaded AS statement_rows_loaded,",
            "  @statement_distinct_docs AS statement_distinct_docs,",
            "  @scope_invoices_count AS scope_invoices_count,",
            "  @baseline_paid_updates AS baseline_paid_updates,",
            "  @statement_override_updates AS statement_override_updates,",
            "  @missing_invoice_rows AS missing_invoice_rows,",
            "  @amount_mismatch_rows AS amount_mismatch_rows,",
            "  @statement_sum_mismatch_rows AS statement_sum_mismatch_rows;",
            "",
            "-- Final status distribution inside scope",
            "SELECT",
            "  ai.status,",
            "  COUNT(*) AS invoice_count",
            "FROM ar_invoices ai",
            "JOIN tmp_scope_invoices s ON s.id = ai.id",
            "GROUP BY ai.status",
            "ORDER BY ai.status;",
            "",
            "-- Missing invoice documents from statement (not found in scoped AR invoices)",
            "SELECT",
            "  source_row_num,",
            "  customer_name,",
            "  document_no,",
            "  statement_amount_cents,",
            "  statement_paid_cents,",
            "  statement_balance_cents",
            "FROM tmp_unpaid_match",
            "WHERE match_status = 'missing_invoice'",
            "ORDER BY source_row_num;",
            "",
            "-- Amount mismatches: statement amount vs invoice total",
            "SELECT",
            "  source_row_num,",
            "  document_no,",
            "  statement_amount_cents,",
            "  invoice_total_cents,",
            "  statement_paid_cents,",
            "  statement_balance_cents",
            "FROM tmp_unpaid_match",
            "WHERE match_status = 'matched'",
            "  AND statement_amount_cents <> invoice_total_cents",
            "ORDER BY source_row_num;",
            "",
            "-- Statement sum mismatches: statement paid + balance vs invoice total",
            "SELECT",
            "  source_row_num,",
            "  document_no,",
            "  statement_paid_cents,",
            "  statement_balance_cents,",
            "  invoice_total_cents",
            "FROM tmp_unpaid_match",
            "WHERE match_status = 'matched'",
            "  AND (statement_paid_cents + statement_balance_cents) <> invoice_total_cents",
            "ORDER BY source_row_num;",
            "",
            "-- ROLLBACK; -- Uncomment for dry-run safety.",
            "COMMIT;",
            "",
        ]
    )

    return "\n".join(out)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate deterministic AR invoice payment reconciliation SQL from statement CSV."
    )
    parser.add_argument(
        "--statement-source",
        type=Path,
        default=DEFAULT_STATEMENT_SOURCE,
        help="Path to unpaid customer statement CSV",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT,
        help="Path to write generated SQL",
    )
    parser.add_argument(
        "--branch-id",
        type=int,
        default=1,
        help="Target branch ID",
    )
    parser.add_argument(
        "--as-of-date",
        type=lambda v: dt.date.fromisoformat(v),
        default=DEFAULT_AS_OF_DATE,
        help="Inclusive scope end date (YYYY-MM-DD)",
    )
    parser.add_argument(
        "--no-strict-facts",
        action="store_true",
        help="Allow source facts to differ from expected locked validation counts.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    statement_source = args.statement_source.resolve()
    output = args.output.resolve()

    rows, facts = load_statement(
        statement_csv=statement_source,
        sales_csv=DEFAULT_SALES_SOURCE.resolve(),
        strict_facts=not args.no_strict_facts,
    )
    sql = render_sql(
        statement_source=statement_source,
        rows=rows,
        facts=facts,
        branch_id=args.branch_id,
        as_of_date=args.as_of_date,
    )

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(sql, encoding="utf-8")

    print(f"Generated SQL: {output}")
    print(f"Statement rows: {facts['statement_rows']}")
    print(f"Distinct docs: {facts['statement_distinct_docs']}")
    print(f"Non-zero paid rows: {facts['statement_nonzero_paid_rows']}")
    print(f"Overlap with sales docs: {facts['overlap_with_sales_docs']}")
    print(f"Skipped missing Document No rows: {facts['skipped_missing_document_no_rows']}")


if __name__ == "__main__":
    main()
