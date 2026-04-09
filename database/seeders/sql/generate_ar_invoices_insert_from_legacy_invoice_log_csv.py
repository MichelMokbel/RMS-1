#!/usr/bin/env python3
"""
Generate deterministic MySQL insert SQL for AR invoices from a legacy invoice-log CSV.

Locked behavior:
- Parse a title row + header row legacy sheet layout.
- Insert only missing invoices by (branch_id, invoice_number); existing ones are skipped and reported.
- Auto-create missing customers by normalized name.
- Skip ambiguous customer matches and rows with no amount.
- Create one placeholder invoice item row per inserted invoice.
- Avoid #1137 temp-table reopen issues in summaries by precomputing @vars.
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
DEFAULT_SOURCE = Path("docs/csv/import-01-01-2025.csv")
DEFAULT_OUTPUT = Path(
    "database/seeders/sql/ar_invoices_insert_from_legacy_invoice_log_2025_01_01.sql"
)
EXPECTED_FACTS = {
    "raw_rows": 343,
    "blank_separator_rows": 9,
    "total_rows_skipped": 2,
    "source_rows_considered": 330,
    "distinct_invoices": 328,
    "defaulted_missing_date_rows": 4,
    "missing_amount_rows": 2,
    "min_date": "2025-01-02",
    "max_date": "2025-02-20",
}
REQUIRED_HEADERS = ["Date", "Invoice No.", "Name", "Amount Paid", "AmountUnpaid"]


def die(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def normalize_spaces(value: str) -> str:
    return " ".join(value.strip().split())


def normalize_name(value: str) -> str:
    return normalize_spaces(value).lower()


def parse_date(raw: str, row_num: int) -> dt.date:
    text = normalize_spaces(raw)
    if text == "":
        die(f"Missing Date in source row {row_num}.")
    parts = text.split("/")
    if len(parts) != 3:
        die(f"Invalid Date value in source row {row_num}: {raw!r}")
    try:
        first, second, year = (int(part) for part in parts)
    except ValueError:
        die(f"Invalid Date value in source row {row_num}: {raw!r}")

    try:
        # The file mixes styles: ambiguous rows are month/day, while rows with first > 12 are day/month.
        return dt.date(year, second, first) if first > 12 else dt.date(year, first, second)
    except ValueError:
        die(f"Invalid Date value in source row {row_num}: {raw!r}")


def parse_money_or_none(raw: str, row_num: int, field_name: str) -> int | None:
    text = normalize_spaces(raw)
    if text == "":
        return None
    cleaned = text.replace("QAR", "").replace(",", "").strip()
    try:
        value = Decimal(cleaned)
    except InvalidOperation:
        die(f"Invalid {field_name} value in source row {row_num}: {raw!r}")
    cents = (value * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
    return int(cents)


def sql_quote(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = value.replace("\\", "\\\\").replace("'", "''")
    return f"'{escaped}'"


def sql_int(value: int) -> str:
    return str(int(value))


def chunks(rows: Sequence[Sequence[str]], size: int) -> Iterable[Sequence[Sequence[str]]]:
    for i in range(0, len(rows), size):
        yield rows[i : i + size]


def append_insert(
    out: List[str],
    table: str,
    columns: Sequence[str],
    values_rows: Sequence[Sequence[str]],
    chunk_size: int = 300,
) -> None:
    if not values_rows:
        return
    cols = ", ".join(columns)
    for group in chunks(values_rows, chunk_size):
        out.append(f"INSERT INTO {table} ({cols}) VALUES")
        out.append(",\n".join(f"({', '.join(row)})" for row in group) + ";")


@dataclass(frozen=True)
class SourceRow:
    source_row_num: int
    source_timestamp: str
    business_date: str
    invoice_number: str
    customer_name: str
    customer_norm: str
    payment_type: str
    status: str
    paid_input_cents: int | None
    unpaid_input_cents: int | None
    total_cents: int
    paid_total_cents: int
    balance_cents: int


@dataclass(frozen=True)
class SkippedAmountRow:
    source_row_num: int
    business_date: str
    invoice_number: str
    customer_name: str


@dataclass(frozen=True)
class SkippedDateRow:
    source_row_num: int
    invoice_number: str
    customer_name: str


def load_source(
    source_csv: Path,
    strict_facts: bool,
) -> tuple[List[SourceRow], List[SkippedAmountRow], List[SkippedDateRow], Dict[str, int | str]]:
    if not source_csv.exists():
        die(f"CSV file not found: {source_csv}")

    with source_csv.open("r", encoding="utf-8-sig", newline="") as handle:
        raw_rows = list(csv.reader(handle))

    if len(raw_rows) < 2:
        die("Legacy CSV must contain a title row and a header row.")

    title_row = [normalize_spaces(col) for col in raw_rows[0]]
    header = [normalize_spaces(col) for col in raw_rows[1]]
    missing_headers = [col for col in REQUIRED_HEADERS if col not in header]
    if missing_headers:
        die(f"Missing required CSV headers: {', '.join(missing_headers)}")

    header_map = {name: header.index(name) for name in REQUIRED_HEADERS}

    title_text = " ".join(value for value in title_row if value)
    title_upper = title_text.upper()
    month_lookup = {
        "JANUARY": 1,
        "FEBRUARY": 2,
        "MARCH": 3,
        "APRIL": 4,
        "MAY": 5,
        "JUNE": 6,
        "JULY": 7,
        "AUGUST": 8,
        "SEPTEMBER": 9,
        "OCTOBER": 10,
        "NOVEMBER": 11,
        "DECEMBER": 12,
    }
    sheet_month = next((month for name, month in month_lookup.items() if name in title_upper), None)
    if sheet_month is None:
        die(f"Could not infer sheet month from title row: {title_text!r}")

    blank_separator_rows = 0
    total_rows_skipped = 0
    considered_rows = 0
    source_rows: List[SourceRow] = []
    skipped_missing_amount: List[SkippedAmountRow] = []
    defaulted_missing_date: List[SkippedDateRow] = []
    seen_years: List[int] = []

    for row_num, row in enumerate(raw_rows[2:], start=3):
        normalized = [normalize_spaces(col) for col in row]
        if not any(normalized):
            blank_separator_rows += 1
            continue

        lowered = [value.lower() for value in normalized if value]
        invoice_number = normalized[header_map["Invoice No."]]
        customer_name = normalized[header_map["Name"]]
        if "total" in lowered and invoice_number == "":
            total_rows_skipped += 1
            continue

        considered_rows += 1

        if invoice_number == "":
            die(f"Missing Invoice No. in source row {row_num}.")
        if customer_name == "":
            die(f"Missing Name in source row {row_num}.")

        date_raw = normalized[header_map["Date"]]
        if date_raw == "":
            date_value = None
        else:
            date_value = parse_date(date_raw, row_num)
            seen_years.append(date_value.year)

        if date_value is None:
            defaulted_missing_date.append(
                SkippedDateRow(
                    source_row_num=row_num,
                    invoice_number=invoice_number,
                    customer_name=customer_name,
                )
            )
            continue

        paid_input_cents = parse_money_or_none(
            normalized[header_map["Amount Paid"]], row_num, "Amount Paid"
        )
        unpaid_input_cents = parse_money_or_none(
            normalized[header_map["AmountUnpaid"]], row_num, "AmountUnpaid"
        )

        if paid_input_cents is None and unpaid_input_cents is None:
            skipped_missing_amount.append(
                SkippedAmountRow(
                    source_row_num=row_num,
                    business_date=date_value.isoformat(),
                    invoice_number=invoice_number,
                    customer_name=customer_name,
                )
            )
            continue

        paid_value = paid_input_cents or 0
        unpaid_value = unpaid_input_cents or 0
        total_cents = paid_value + unpaid_value

        if total_cents <= 0:
            skipped_missing_amount.append(
                SkippedAmountRow(
                    source_row_num=row_num,
                    business_date=date_value.isoformat(),
                    invoice_number=invoice_number,
                    customer_name=customer_name,
                )
            )
            continue

        if paid_value > 0 and unpaid_value > 0:
            status = "partially_paid"
            paid_total_cents = paid_value
            balance_cents = unpaid_value
        elif paid_value > 0:
            status = "paid"
            paid_total_cents = total_cents
            balance_cents = 0
        else:
            status = "issued"
            paid_total_cents = 0
            balance_cents = total_cents

        source_rows.append(
            SourceRow(
                source_row_num=row_num,
                source_timestamp=f"{date_value.isoformat()} 00:00:00",
                business_date=date_value.isoformat(),
                invoice_number=invoice_number,
                customer_name=customer_name,
                customer_norm=normalize_name(customer_name),
                payment_type="credit",
                status=status,
                paid_input_cents=paid_input_cents,
                unpaid_input_cents=unpaid_input_cents,
                total_cents=total_cents,
                paid_total_cents=paid_total_cents,
                balance_cents=balance_cents,
            )
        )

    if not source_rows and not defaulted_missing_date:
        die("No importable invoice rows found in source CSV.")

    inferred_year = max(set(seen_years), key=seen_years.count) if seen_years else dt.date.today().year
    if defaulted_missing_date:
        next_month = dt.date(inferred_year + (1 if sheet_month == 12 else 0), 1 if sheet_month == 12 else sheet_month + 1, 1)
        fallback_date = next_month - dt.timedelta(days=1)
        fallback_timestamp = f"{fallback_date.isoformat()} 00:00:00"

        defaulted_lookup = {row.source_row_num for row in defaulted_missing_date}
        for row_num, row in enumerate(raw_rows[2:], start=3):
            if row_num not in defaulted_lookup:
                continue
            normalized = [normalize_spaces(col) for col in row]
            invoice_number = normalized[header_map["Invoice No."]]
            customer_name = normalized[header_map["Name"]]
            paid_input_cents = parse_money_or_none(
                normalized[header_map["Amount Paid"]], row_num, "Amount Paid"
            )
            unpaid_input_cents = parse_money_or_none(
                normalized[header_map["AmountUnpaid"]], row_num, "AmountUnpaid"
            )

            if paid_input_cents is None and unpaid_input_cents is None:
                continue

            paid_value = paid_input_cents or 0
            unpaid_value = unpaid_input_cents or 0
            total_cents = paid_value + unpaid_value
            if total_cents <= 0:
                continue

            if paid_value > 0 and unpaid_value > 0:
                status = "partially_paid"
                paid_total_cents = paid_value
                balance_cents = unpaid_value
            elif paid_value > 0:
                status = "paid"
                paid_total_cents = total_cents
                balance_cents = 0
            else:
                status = "issued"
                paid_total_cents = 0
                balance_cents = total_cents

            source_rows.append(
                SourceRow(
                    source_row_num=row_num,
                    source_timestamp=fallback_timestamp,
                    business_date=fallback_date.isoformat(),
                    invoice_number=invoice_number,
                    customer_name=customer_name,
                    customer_norm=normalize_name(customer_name),
                    payment_type="credit",
                    status=status,
                    paid_input_cents=paid_input_cents,
                    unpaid_input_cents=unpaid_input_cents,
                    total_cents=total_cents,
                    paid_total_cents=paid_total_cents,
                    balance_cents=balance_cents,
                )
            )

    if not source_rows:
        die("No importable invoice rows found in source CSV.")

    invoice_counts: Dict[str, int] = {}
    for row in source_rows:
        invoice_counts[row.invoice_number] = invoice_counts.get(row.invoice_number, 0) + 1
    duplicate_invoices = {k: v for k, v in invoice_counts.items() if v > 1}
    if duplicate_invoices:
        sample = ", ".join(f"{k}({v})" for k, v in sorted(duplicate_invoices.items())[:10])
        die(f"Duplicate Invoice No. values in legacy CSV: {sample}")

    facts: Dict[str, int | str] = {
        "raw_rows": len(raw_rows),
        "blank_separator_rows": blank_separator_rows,
        "total_rows_skipped": total_rows_skipped,
        "source_rows_considered": considered_rows,
        "defaulted_missing_date_rows": len(defaulted_missing_date),
        "distinct_invoices": len({row.invoice_number for row in source_rows}),
        "missing_amount_rows": len(skipped_missing_amount),
        "min_date": min(row.business_date for row in source_rows),
        "max_date": max(row.business_date for row in source_rows),
    }

    if strict_facts:
        for key, expected in EXPECTED_FACTS.items():
            actual = facts.get(key)
            if actual != expected:
                die(f"Source fact mismatch for {key}: expected {expected!r}, got {actual!r}")

    defaulted_missing_date.sort(key=lambda row: row.source_row_num)
    source_rows.sort(key=lambda row: row.source_row_num)
    return source_rows, skipped_missing_amount, defaulted_missing_date, facts


def render_sql(
    source_csv: Path,
    rows: List[SourceRow],
    skipped_missing_amount: List[SkippedAmountRow],
    defaulted_missing_date: List[SkippedDateRow],
    facts: Dict[str, int | str],
    branch_id: int,
) -> str:
    generated_at = dt.datetime.now().replace(microsecond=0).isoformat()
    out: List[str] = []

    out.extend(
        [
            "-- Generated SQL: AR invoices insert from legacy invoice log CSV",
            f"-- Source file: {source_csv}",
            f"-- Generated at: {generated_at}",
            f"-- Branch ID: {branch_id}",
            f"-- Raw CSV rows: {facts['raw_rows']}",
            f"-- Blank separator rows skipped: {facts['blank_separator_rows']}",
            f"-- Total rows skipped: {facts['total_rows_skipped']}",
            f"-- Source rows considered: {facts['source_rows_considered']}",
            f"-- Importable invoice rows: {len(rows)}",
            f"-- Distinct invoice numbers: {facts['distinct_invoices']}",
            f"-- Rows defaulted to month-end for missing date: {facts['defaulted_missing_date_rows']}",
            f"-- Rows skipped for missing amount: {facts['missing_amount_rows']}",
            f"-- Min invoice date: {facts['min_date']}",
            f"-- Max invoice date: {facts['max_date']}",
            "-- Matching rule: insert only when (branch_id, invoice_number) does not already exist",
            "-- Customer rule: customer by normalized name, auto-create when missing, skip when ambiguous",
            "-- Item rule: create one placeholder item line with description Legacy import",
            "",
            "START TRANSACTION;",
            "",
            f"SET @raw_rows := {sql_int(int(facts['raw_rows']))};",
            f"SET @blank_separator_rows_skipped := {sql_int(int(facts['blank_separator_rows']))};",
            f"SET @total_rows_skipped := {sql_int(int(facts['total_rows_skipped']))};",
            f"SET @source_rows_considered := {sql_int(int(facts['source_rows_considered']))};",
            f"SET @source_distinct_invoice_numbers := {sql_int(int(facts['distinct_invoices']))};",
            f"SET @source_defaulted_missing_date_rows := {sql_int(int(facts['defaulted_missing_date_rows']))};",
            f"SET @source_missing_amount_rows := {sql_int(int(facts['missing_amount_rows']))};",
            "SET @inserted_customers := 0;",
            "SET @inserted_invoice_rows := 0;",
            "SET @inserted_invoice_item_rows := 0;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_invoice_source;",
            "CREATE TEMPORARY TABLE tmp_legacy_invoice_source (",
            "  source_row_num INT NOT NULL,",
            "  source_timestamp DATETIME NOT NULL,",
            "  business_date DATE NOT NULL,",
            "  invoice_number VARCHAR(64) NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            f"  customer_norm VARCHAR(191) NOT NULL COLLATE {COLLATION},",
            "  payment_type VARCHAR(20) NOT NULL,",
            "  status VARCHAR(20) NOT NULL,",
            "  paid_input_cents BIGINT DEFAULT NULL,",
            "  unpaid_input_cents BIGINT DEFAULT NULL,",
            "  total_cents BIGINT NOT NULL,",
            "  paid_total_cents BIGINT NOT NULL,",
            "  balance_cents BIGINT NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  UNIQUE KEY uq_tmp_legacy_invoice_source_invoice_number (invoice_number),",
            "  KEY idx_tmp_legacy_invoice_source_customer_norm (customer_norm),",
            "  KEY idx_tmp_legacy_invoice_source_business_date (business_date)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    source_values = [
        [
            sql_int(row.source_row_num),
            sql_quote(row.source_timestamp),
            sql_quote(row.business_date),
            sql_quote(row.invoice_number),
            sql_quote(row.customer_name),
            sql_quote(row.customer_norm),
            sql_quote(row.payment_type),
            sql_quote(row.status),
            "NULL" if row.paid_input_cents is None else sql_int(row.paid_input_cents),
            "NULL" if row.unpaid_input_cents is None else sql_int(row.unpaid_input_cents),
            sql_int(row.total_cents),
            sql_int(row.paid_total_cents),
            sql_int(row.balance_cents),
        ]
        for row in rows
    ]
    append_insert(
        out,
        "tmp_legacy_invoice_source",
        [
            "source_row_num",
            "source_timestamp",
            "business_date",
            "invoice_number",
            "customer_name",
            "customer_norm",
            "payment_type",
            "status",
            "paid_input_cents",
            "unpaid_input_cents",
            "total_cents",
            "paid_total_cents",
            "balance_cents",
        ],
        source_values,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_skipped_missing_date;",
            "CREATE TEMPORARY TABLE tmp_legacy_skipped_missing_date (",
            "  source_row_num INT NOT NULL,",
            "  invoice_number VARCHAR(64) NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  KEY idx_tmp_legacy_skipped_missing_date_invoice_number (invoice_number)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    skipped_date_values = [
        [
            sql_int(row.source_row_num),
            sql_quote(row.invoice_number),
            sql_quote(row.customer_name),
        ]
        for row in defaulted_missing_date
    ]
    append_insert(
        out,
        "tmp_legacy_skipped_missing_date",
        ["source_row_num", "invoice_number", "customer_name"],
        skipped_date_values,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_skipped_missing_amount;",
            "CREATE TEMPORARY TABLE tmp_legacy_skipped_missing_amount (",
            "  source_row_num INT NOT NULL,",
            "  business_date DATE NOT NULL,",
            "  invoice_number VARCHAR(64) NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  KEY idx_tmp_legacy_skipped_missing_amount_invoice_number (invoice_number)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    skipped_values = [
        [
            sql_int(row.source_row_num),
            sql_quote(row.business_date),
            sql_quote(row.invoice_number),
            sql_quote(row.customer_name),
        ]
        for row in skipped_missing_amount
    ]
    append_insert(
        out,
        "tmp_legacy_skipped_missing_amount",
        ["source_row_num", "business_date", "invoice_number", "customer_name"],
        skipped_values,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;",
            "CREATE TEMPORARY TABLE tmp_customer_source AS",
            "SELECT",
            "  customer_norm,",
            "  MIN(customer_name) AS customer_name",
            "FROM tmp_legacy_invoice_source",
            "GROUP BY customer_norm;",
            "ALTER TABLE tmp_customer_source",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts;",
            "CREATE TEMPORARY TABLE tmp_customer_name_counts AS",
            "SELECT",
            f"  LOWER(TRIM(c.name)) COLLATE {COLLATION} AS customer_norm,",
            "  COUNT(*) AS target_count",
            "FROM customers c",
            f"GROUP BY LOWER(TRIM(c.name)) COLLATE {COLLATION};",
            "ALTER TABLE tmp_customer_name_counts",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names;",
            "CREATE TEMPORARY TABLE tmp_customer_unique_names AS",
            "SELECT",
            "  cc.customer_norm,",
            "  c.id AS customer_id",
            "FROM tmp_customer_name_counts cc",
            "JOIN customers c",
            f"  ON LOWER(TRIM(c.name)) COLLATE {COLLATION} = cc.customer_norm",
            "WHERE cc.target_count = 1;",
            "ALTER TABLE tmp_customer_unique_names",
            "  ADD PRIMARY KEY (customer_norm),",
            "  ADD KEY idx_tmp_customer_unique_names_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names;",
            "CREATE TEMPORARY TABLE tmp_customer_ambiguous_names AS",
            "SELECT customer_norm, target_count",
            "FROM tmp_customer_name_counts",
            "WHERE target_count > 1;",
            "ALTER TABLE tmp_customer_ambiguous_names",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_missing_customers;",
            "CREATE TEMPORARY TABLE tmp_missing_customers AS",
            "SELECT",
            "  s.customer_norm,",
            "  s.customer_name",
            "FROM tmp_customer_source s",
            "LEFT JOIN tmp_customer_name_counts c ON c.customer_norm = s.customer_norm",
            "WHERE c.customer_norm IS NULL;",
            "ALTER TABLE tmp_missing_customers",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "SET @next_customer_num := (",
            "  SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) + 1",
            "  FROM customers",
            "  WHERE customer_code REGEXP '^CUST-[0-9]+$'",
            ");",
            "SET @customer_row_num := 0;",
            "INSERT INTO customers (",
            "  customer_code,",
            "  name,",
            "  customer_type,",
            "  credit_limit,",
            "  is_active,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            "  CONCAT('CUST-', LPAD(CAST(@next_customer_num + (@customer_row_num := @customer_row_num + 1) - 1 AS CHAR), 4, '0')) AS customer_code,",
            "  m.customer_name AS name,",
            "  'retail' AS customer_type,",
            "  0.000 AS credit_limit,",
            "  1 AS is_active,",
            "  NOW() AS created_at,",
            "  NOW() AS updated_at",
            "FROM tmp_missing_customers m",
            "ORDER BY m.customer_norm;",
            "SET @inserted_customers := ROW_COUNT();",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts_final;",
            "CREATE TEMPORARY TABLE tmp_customer_name_counts_final AS",
            "SELECT",
            f"  LOWER(TRIM(c.name)) COLLATE {COLLATION} AS customer_norm,",
            "  COUNT(*) AS target_count",
            "FROM customers c",
            f"GROUP BY LOWER(TRIM(c.name)) COLLATE {COLLATION};",
            "ALTER TABLE tmp_customer_name_counts_final",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names_final;",
            "CREATE TEMPORARY TABLE tmp_customer_unique_names_final AS",
            "SELECT",
            "  cc.customer_norm,",
            "  c.id AS customer_id",
            "FROM tmp_customer_name_counts_final cc",
            "JOIN customers c",
            f"  ON LOWER(TRIM(c.name)) COLLATE {COLLATION} = cc.customer_norm",
            "WHERE cc.target_count = 1;",
            "ALTER TABLE tmp_customer_unique_names_final",
            "  ADD PRIMARY KEY (customer_norm),",
            "  ADD KEY idx_tmp_customer_unique_names_final_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names_final;",
            "CREATE TEMPORARY TABLE tmp_customer_ambiguous_names_final AS",
            "SELECT customer_norm, target_count",
            "FROM tmp_customer_name_counts_final",
            "WHERE target_count > 1;",
            "ALTER TABLE tmp_customer_ambiguous_names_final",
            "  ADD PRIMARY KEY (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_customer_resolution;",
            "CREATE TEMPORARY TABLE tmp_legacy_customer_resolution AS",
            "SELECT",
            "  s.source_row_num,",
            "  s.customer_norm,",
            "  cu.customer_id,",
            "  CASE",
            "    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'",
            "    WHEN cu.customer_id IS NULL THEN 'missing'",
            "    ELSE 'resolved'",
            "  END AS customer_resolution",
            "FROM tmp_legacy_invoice_source s",
            "LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = s.customer_norm",
            "LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = s.customer_norm;",
            "ALTER TABLE tmp_legacy_customer_resolution",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_legacy_customer_resolution_state (customer_resolution),",
            "  ADD KEY idx_tmp_legacy_customer_resolution_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_invoice_resolution;",
            "CREATE TEMPORARY TABLE tmp_legacy_invoice_resolution AS",
            "SELECT",
            "  s.source_row_num,",
            "  s.invoice_number,",
            "  cr.customer_id,",
            "  cr.customer_resolution,",
            "  ai.id AS existing_invoice_id,",
            "  CASE",
            "    WHEN cr.customer_resolution <> 'resolved' THEN 'skip_customer'",
            "    WHEN ai.id IS NOT NULL THEN 'skip_existing'",
            "    ELSE 'insert'",
            "  END AS resolution_status",
            "FROM tmp_legacy_invoice_source s",
            "JOIN tmp_legacy_customer_resolution cr ON cr.source_row_num = s.source_row_num",
            "LEFT JOIN ar_invoices ai",
            f"  ON ai.branch_id = {branch_id}",
            "  AND ai.type = 'invoice'",
            f"  AND ai.invoice_number COLLATE {COLLATION} = s.invoice_number COLLATE {COLLATION};",
            "ALTER TABLE tmp_legacy_invoice_resolution",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_legacy_invoice_resolution_status (resolution_status),",
            "  ADD KEY idx_tmp_legacy_invoice_resolution_existing_invoice_id (existing_invoice_id);",
            "",
            "INSERT INTO ar_invoices (",
            "  branch_id,",
            "  customer_id,",
            "  source,",
            "  type,",
            "  invoice_number,",
            "  status,",
            "  payment_type,",
            "  issue_date,",
            "  due_date,",
            "  currency,",
            "  subtotal_cents,",
            "  discount_total_cents,",
            "  invoice_discount_type,",
            "  invoice_discount_value,",
            "  invoice_discount_cents,",
            "  tax_total_cents,",
            "  total_cents,",
            "  paid_total_cents,",
            "  balance_cents,",
            "  notes,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            f"  {branch_id} AS branch_id,",
            "  r.customer_id,",
            "  'import' AS source,",
            "  'invoice' AS type,",
            "  s.invoice_number,",
            "  s.status,",
            "  s.payment_type,",
            "  s.business_date AS issue_date,",
            "  s.business_date AS due_date,",
            "  'QAR' AS currency,",
            "  s.total_cents AS subtotal_cents,",
            "  0 AS discount_total_cents,",
            "  'fixed' AS invoice_discount_type,",
            "  0 AS invoice_discount_value,",
            "  0 AS invoice_discount_cents,",
            "  0 AS tax_total_cents,",
            "  s.total_cents,",
            "  s.paid_total_cents,",
            "  s.balance_cents,",
            "  'Imported from legacy invoice log' AS notes,",
            "  s.source_timestamp AS created_at,",
            "  s.source_timestamp AS updated_at",
            "FROM tmp_legacy_invoice_resolution r",
            "JOIN tmp_legacy_invoice_source s ON s.source_row_num = r.source_row_num",
            "WHERE r.resolution_status = 'insert'",
            "ORDER BY s.source_row_num;",
            "SET @inserted_invoice_rows := ROW_COUNT();",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_legacy_inserted_invoice_ids;",
            "CREATE TEMPORARY TABLE tmp_legacy_inserted_invoice_ids AS",
            "SELECT",
            "  s.source_row_num,",
            "  ai.id AS invoice_id",
            "FROM tmp_legacy_invoice_resolution r",
            "JOIN tmp_legacy_invoice_source s ON s.source_row_num = r.source_row_num",
            "JOIN ar_invoices ai",
            f"  ON ai.branch_id = {branch_id}",
            "  AND ai.type = 'invoice'",
            f"  AND ai.invoice_number COLLATE {COLLATION} = s.invoice_number COLLATE {COLLATION}",
            "WHERE r.resolution_status = 'insert';",
            "ALTER TABLE tmp_legacy_inserted_invoice_ids",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_legacy_inserted_invoice_ids_invoice_id (invoice_id);",
            "",
            "INSERT INTO ar_invoice_items (",
            "  invoice_id,",
            "  description,",
            "  qty,",
            "  unit_price_cents,",
            "  discount_cents,",
            "  tax_cents,",
            "  line_total_cents,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            "  t.invoice_id,",
            "  'Legacy import' AS description,",
            "  1.000 AS qty,",
            "  s.total_cents AS unit_price_cents,",
            "  0 AS discount_cents,",
            "  0 AS tax_cents,",
            "  s.total_cents AS line_total_cents,",
            "  s.source_timestamp AS created_at,",
            "  s.source_timestamp AS updated_at",
            "FROM tmp_legacy_inserted_invoice_ids t",
            "JOIN tmp_legacy_invoice_source s ON s.source_row_num = t.source_row_num",
            "ORDER BY t.source_row_num;",
            "SET @inserted_invoice_item_rows := ROW_COUNT();",
            "",
            "SET @source_distinct_customers := (SELECT COUNT(*) FROM tmp_customer_source);",
            "SET @skipped_existing_invoice_rows := (",
            "  SELECT COUNT(*) FROM tmp_legacy_invoice_resolution WHERE resolution_status = 'skip_existing'",
            ");",
            "SET @skipped_customer_rows := (",
            "  SELECT COUNT(*) FROM tmp_legacy_invoice_resolution WHERE resolution_status = 'skip_customer'",
            ");",
            "",
            "-- Summary",
            "SELECT",
            "  @raw_rows AS raw_rows,",
            "  @blank_separator_rows_skipped AS blank_separator_rows_skipped,",
            "  @total_rows_skipped AS total_rows_skipped,",
            "  @source_rows_considered AS source_rows_considered,",
            "  @source_distinct_invoice_numbers AS source_distinct_invoice_numbers,",
            "  @source_distinct_customers AS source_distinct_customers,",
            "  @source_defaulted_missing_date_rows AS defaulted_missing_date_rows,",
            "  @source_missing_amount_rows AS source_missing_amount_rows,",
            "  @skipped_existing_invoice_rows AS skipped_existing_invoice_rows,",
            "  @skipped_customer_rows AS skipped_customer_rows,",
            "  @inserted_customers AS inserted_customers,",
            "  @inserted_invoice_rows AS inserted_invoices,",
            "  @inserted_invoice_item_rows AS inserted_invoice_items;",
            "",
            "-- Rows defaulted to sheet month-end due to missing date",
            "SELECT",
            "  source_row_num,",
            "  invoice_number,",
            "  customer_name",
            "FROM tmp_legacy_skipped_missing_date",
            "ORDER BY source_row_num;",
            "",
            "-- Skipped rows due to missing amount",
            "SELECT",
            "  source_row_num,",
            "  business_date,",
            "  invoice_number,",
            "  customer_name",
            "FROM tmp_legacy_skipped_missing_amount",
            "ORDER BY source_row_num;",
            "",
            "-- Skipped rows because invoice already exists in branch",
            "SELECT",
            "  r.source_row_num,",
            "  s.invoice_number,",
            "  s.customer_name,",
            "  r.existing_invoice_id",
            "FROM tmp_legacy_invoice_resolution r",
            "JOIN tmp_legacy_invoice_source s ON s.source_row_num = r.source_row_num",
            "WHERE r.resolution_status = 'skip_existing'",
            "ORDER BY r.source_row_num;",
            "",
            "-- Skipped rows due to unresolved customer matching",
            "SELECT",
            "  r.source_row_num,",
            "  s.invoice_number,",
            "  s.customer_name,",
            "  cr.customer_resolution",
            "FROM tmp_legacy_invoice_resolution r",
            "JOIN tmp_legacy_invoice_source s ON s.source_row_num = r.source_row_num",
            "JOIN tmp_legacy_customer_resolution cr ON cr.source_row_num = r.source_row_num",
            "WHERE r.resolution_status = 'skip_customer'",
            "ORDER BY r.source_row_num;",
            "",
            "-- Breakdown by imported status",
            "SELECT",
            "  status,",
            "  payment_type,",
            "  COUNT(*) AS row_count",
            "FROM tmp_legacy_invoice_source",
            "GROUP BY status, payment_type",
            "ORDER BY status, payment_type;",
            "",
            "-- ROLLBACK; -- Uncomment for dry-run safety.",
            "COMMIT;",
            "",
        ]
    )

    return "\n".join(out)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate deterministic AR invoice insert SQL from a legacy invoice-log CSV."
    )
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE, help="Path to source CSV file")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="Path to write generated SQL")
    parser.add_argument("--branch-id", type=int, default=1, help="Target branch ID")
    parser.add_argument(
        "--no-strict-facts",
        action="store_true",
        help="Allow source facts to differ from expected locked validation counts.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    source = args.source.resolve()
    output = args.output.resolve()

    rows, skipped_missing_amount, defaulted_missing_date, facts = load_source(
        source_csv=source,
        strict_facts=not args.no_strict_facts,
    )
    sql = render_sql(
        source_csv=source,
        rows=rows,
        skipped_missing_amount=skipped_missing_amount,
        defaulted_missing_date=defaulted_missing_date,
        facts=facts,
        branch_id=args.branch_id,
    )

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(sql, encoding="utf-8")

    print(f"Generated SQL: {output}")
    print(f"Importable invoice rows: {len(rows)}")
    print(f"Distinct invoice numbers: {facts['distinct_invoices']}")
    print(f"Rows defaulted for missing date: {len(defaulted_missing_date)}")
    print(f"Rows skipped for missing amount: {len(skipped_missing_amount)}")
    print(f"Min date: {facts['min_date']}")
    print(f"Max date: {facts['max_date']}")


if __name__ == "__main__":
    main()
