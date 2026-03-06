#!/usr/bin/env python3
"""
Generate deterministic MySQL upsert SQL for AR invoices from sales-entry CSV.

Locked behavior:
- Date-range import only (inclusive).
- Upsert invoices in-range; do not truncate AR tables.
- Match existing invoice by (branch_id, invoice_number), else (branch_id, pos_reference).
- Skip conflicting rows where invoice_number and pos_reference map to different invoice IDs.
- Auto-insert missing customers by normalized name.
- Replace ar_invoice_items for touched invoices only.
- Force utf8mb4_unicode_ci in normalized text joins to avoid collation mismatch errors.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import re
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Dict, Iterable, List, Sequence


COLLATION = "utf8mb4_unicode_ci"
REQUIRED_HEADERS = [
    "Warehouse",
    "Date & Time",
    "Document No",
    "Customer",
    "POS Reference",
    "Total Trade Revenue",
    "Discount",
    "Net Amount",
    "Cash",
    "Card",
    "Credit",
]

DEFAULT_START_DATE = dt.date(2026, 2, 16)
DEFAULT_END_DATE = dt.date(2026, 3, 1)

EXPECTED_FACTS = {
    "filtered_rows": 311,
    "filtered_min_date": "2026-02-16",
    "filtered_max_date": "2026-02-28",
}

TIMESTAMP_RE = re.compile(
    r"^(?P<base>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)"
    r"(?P<sign>[+-])(?P<hh>\d{2})(?::?(?P<mm>\d{2}))?$"
)


def die(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def normalize_spaces(value: str) -> str:
    return " ".join(value.strip().split())


def normalize_name(value: str) -> str:
    return normalize_spaces(value).lower()


def parse_decimal(raw: str, scale: str) -> Decimal:
    text = normalize_spaces(raw).replace(",", "")
    if text == "":
        return Decimal("0").quantize(Decimal(scale))
    try:
        val = Decimal(text)
    except InvalidOperation:
        die(f"Invalid numeric value: {raw!r}")
    return val.quantize(Decimal(scale), rounding=ROUND_HALF_UP)


def parse_timestamp_with_offset(raw: str) -> dt.datetime:
    text = normalize_spaces(raw)
    match = TIMESTAMP_RE.match(text)
    if not match:
        die(f"Invalid Date & Time value: {raw!r}")

    base = match.group("base")
    sign = match.group("sign")
    hh = match.group("hh")
    mm = match.group("mm") or "00"
    iso = f"{base}{sign}{hh}:{mm}"
    try:
        parsed = dt.datetime.fromisoformat(iso)
    except ValueError:
        die(f"Invalid Date & Time value: {raw!r}")
    if parsed.tzinfo is None:
        die(f"Date & Time missing timezone offset: {raw!r}")
    return parsed


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
    chunk_size: int = 200,
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
    warehouse: str
    source_timestamp: str
    business_date: str
    document_no: str
    customer_name: str
    customer_norm: str
    pos_reference: str | None
    subtotal_cents: int
    discount_cents: int
    total_cents: int
    cash_cents: int
    card_cents: int
    credit_cents: int
    payment_type: str
    status: str
    paid_total_cents: int
    balance_cents: int


def map_payment(cash_cents: int, card_cents: int, credit_cents: int) -> tuple[str, str, int, int]:
    # Locked mapping from the implementation plan.
    if cash_cents > 0 and card_cents == 0 and credit_cents == 0:
        payment_type = "cash"
        status = "paid"
    elif card_cents > 0 and cash_cents == 0 and credit_cents == 0:
        payment_type = "card"
        status = "issued"
    elif cash_cents == 0 and credit_cents > 0 and card_cents == 0:
        payment_type = "credit"
        status = "issued"
    elif cash_cents == 0 and card_cents > 0 and credit_cents > 0:
        payment_type = "credit"
        status = "issued"
    elif cash_cents > 0:
        payment_type = "cash"
        status = "issued"
    elif card_cents > 0:
        payment_type = "card"
        status = "issued"
    elif credit_cents > 0:
        payment_type = "credit"
        status = "issued"
    else:
        payment_type = "credit"
        status = "issued"

    return payment_type, status


def money_to_cents(amount: Decimal) -> int:
    return int((amount * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def load_source(
    csv_path: Path,
    start_date: dt.date,
    end_date: dt.date,
    strict_facts: bool,
) -> tuple[List[SourceRow], Dict[str, str | int]]:
    if not csv_path.exists():
        die(f"CSV file not found: {csv_path}")

    rows: List[SourceRow] = []
    total_csv_rows = 0

    with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            die("CSV has no header row.")
        headers = [h.strip() for h in reader.fieldnames]
        missing = [h for h in REQUIRED_HEADERS if h not in headers]
        if missing:
            die(f"Missing required CSV headers: {', '.join(missing)}")

        for source_row_num, row in enumerate(reader, start=2):
            total_csv_rows += 1
            timestamp_raw = normalize_spaces(row["Date & Time"])
            document_no = normalize_spaces(row["Document No"])
            customer_name = normalize_spaces(row["Customer"])
            warehouse = normalize_spaces(row["Warehouse"])
            pos_reference_raw = normalize_spaces(row["POS Reference"])

            if timestamp_raw == "" and document_no == "" and customer_name == "":
                continue

            if timestamp_raw == "" or document_no == "" or customer_name == "":
                die(f"Missing required fields in source row {source_row_num}.")

            ts = parse_timestamp_with_offset(timestamp_raw)
            business_date = ts.date()
            if business_date < start_date or business_date > end_date:
                continue

            subtotal = parse_decimal(row["Total Trade Revenue"], "0.01")
            discount = parse_decimal(row["Discount"], "0.01")
            total = parse_decimal(row["Net Amount"], "0.01")
            cash = parse_decimal(row["Cash"], "0.01")
            card = parse_decimal(row["Card"], "0.01")
            credit = parse_decimal(row["Credit"], "0.01")

            subtotal_cents = money_to_cents(subtotal)
            discount_cents = money_to_cents(discount)
            total_cents = money_to_cents(total)
            cash_cents = money_to_cents(cash)
            card_cents = money_to_cents(card)
            credit_cents = money_to_cents(credit)

            payment_type, status = map_payment(cash_cents, card_cents, credit_cents)
            if status == "paid":
                paid_total_cents = total_cents
                balance_cents = 0
            else:
                paid_total_cents = 0
                balance_cents = total_cents

            rows.append(
                SourceRow(
                    source_row_num=source_row_num,
                    warehouse=warehouse,
                    source_timestamp=ts.isoformat(),
                    business_date=business_date.isoformat(),
                    document_no=document_no,
                    customer_name=customer_name,
                    customer_norm=normalize_name(customer_name),
                    pos_reference=pos_reference_raw if pos_reference_raw else None,
                    subtotal_cents=subtotal_cents,
                    discount_cents=discount_cents,
                    total_cents=total_cents,
                    cash_cents=cash_cents,
                    card_cents=card_cents,
                    credit_cents=credit_cents,
                    payment_type=payment_type,
                    status=status,
                    paid_total_cents=paid_total_cents,
                    balance_cents=balance_cents,
                )
            )

    if not rows:
        die("No in-range rows found in source CSV.")

    duplicate_docs = {}
    duplicate_pos_refs = {}
    doc_counts: Dict[str, int] = {}
    pos_counts: Dict[str, int] = {}
    for row in rows:
        doc_counts[row.document_no] = doc_counts.get(row.document_no, 0) + 1
        if row.pos_reference:
            pos_counts[row.pos_reference] = pos_counts.get(row.pos_reference, 0) + 1

    duplicate_docs = {k: v for k, v in doc_counts.items() if v > 1}
    duplicate_pos_refs = {k: v for k, v in pos_counts.items() if v > 1}

    if duplicate_docs:
        sample = ", ".join(f"{k}({v})" for k, v in list(sorted(duplicate_docs.items()))[:10])
        die(f"Duplicate Document No values in filtered range: {sample}")
    if duplicate_pos_refs:
        sample = ", ".join(f"{k}({v})" for k, v in list(sorted(duplicate_pos_refs.items()))[:10])
        die(f"Duplicate POS Reference values in filtered range: {sample}")

    min_date = min(row.business_date for row in rows)
    max_date = max(row.business_date for row in rows)
    facts: Dict[str, str | int] = {
        "total_csv_rows": total_csv_rows,
        "filtered_rows": len(rows),
        "filtered_min_date": min_date,
        "filtered_max_date": max_date,
        "filtered_distinct_customers": len({row.customer_norm for row in rows}),
        "filtered_distinct_docs": len({row.document_no for row in rows}),
        "filtered_distinct_pos_refs_non_empty": len({row.pos_reference for row in rows if row.pos_reference}),
    }

    if strict_facts:
        for key, expected_value in EXPECTED_FACTS.items():
            actual = facts.get(key)
            if actual != expected_value:
                die(f"Source fact mismatch for {key}: expected {expected_value!r}, got {actual!r}")

    return rows, facts


def render_sql(
    source_csv: Path,
    rows: List[SourceRow],
    facts: Dict[str, str | int],
    start_date: dt.date,
    end_date: dt.date,
    branch_id: int,
) -> str:
    generated_at = dt.datetime.now().replace(microsecond=0).isoformat()
    source_ts_expr = "STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s')"
    out: List[str] = []

    out.extend(
        [
            "-- Generated SQL: AR invoices upsert from sales-entry CSV",
            f"-- Source file: {source_csv}",
            f"-- Generated at: {generated_at}",
            f"-- Date range (inclusive): {start_date.isoformat()} to {end_date.isoformat()}",
            f"-- Branch ID: {branch_id}",
            f"-- Total CSV rows: {facts['total_csv_rows']}",
            f"-- Filtered rows in range: {facts['filtered_rows']}",
            f"-- Filtered min date: {facts['filtered_min_date']}",
            f"-- Filtered max date: {facts['filtered_max_date']}",
            f"-- Distinct documents in range: {facts['filtered_distinct_docs']}",
            f"-- Distinct non-empty POS refs in range: {facts['filtered_distinct_pos_refs_non_empty']}",
            f"-- Distinct normalized customers in range: {facts['filtered_distinct_customers']}",
            "-- Matching rules: invoice by (branch, invoice_number) then (branch, pos_reference); customer by normalized name",
            "-- Rerunnable behavior: upsert invoice headers + replace items for touched invoices only",
            "",
            "START TRANSACTION;",
            "",
            "SET @inserted_customers := 0;",
            "SET @inserted_invoice_rows := 0;",
            "SET @updated_invoice_rows := 0;",
            "SET @deleted_invoice_item_rows := 0;",
            "SET @inserted_invoice_item_rows := 0;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_sales_source;",
            "CREATE TEMPORARY TABLE tmp_sales_source (",
            "  source_row_num INT NOT NULL,",
            "  warehouse VARCHAR(100) NOT NULL,",
            "  source_timestamp VARCHAR(40) NOT NULL,",
            "  business_date DATE NOT NULL,",
            "  document_no VARCHAR(64) NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            f"  customer_norm VARCHAR(191) NOT NULL COLLATE {COLLATION},",
            "  pos_reference VARCHAR(191) DEFAULT NULL,",
            "  subtotal_cents BIGINT NOT NULL,",
            "  discount_cents BIGINT NOT NULL,",
            "  total_cents BIGINT NOT NULL,",
            "  cash_cents BIGINT NOT NULL,",
            "  card_cents BIGINT NOT NULL,",
            "  credit_cents BIGINT NOT NULL,",
            "  payment_type VARCHAR(20) NOT NULL,",
            "  status VARCHAR(20) NOT NULL,",
            "  paid_total_cents BIGINT NOT NULL,",
            "  balance_cents BIGINT NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  UNIQUE KEY uq_tmp_sales_source_document_no (document_no),",
            "  UNIQUE KEY uq_tmp_sales_source_pos_reference (pos_reference),",
            "  KEY idx_tmp_sales_source_customer_norm (customer_norm),",
            "  KEY idx_tmp_sales_source_business_date (business_date)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    source_rows = [
        [
            sql_int(r.source_row_num),
            sql_quote(r.warehouse),
            sql_quote(r.source_timestamp),
            sql_quote(r.business_date),
            sql_quote(r.document_no),
            sql_quote(r.customer_name),
            sql_quote(r.customer_norm),
            sql_quote(r.pos_reference),
            sql_int(r.subtotal_cents),
            sql_int(r.discount_cents),
            sql_int(r.total_cents),
            sql_int(r.cash_cents),
            sql_int(r.card_cents),
            sql_int(r.credit_cents),
            sql_quote(r.payment_type),
            sql_quote(r.status),
            sql_int(r.paid_total_cents),
            sql_int(r.balance_cents),
        ]
        for r in rows
    ]
    append_insert(
        out,
        "tmp_sales_source",
        [
            "source_row_num",
            "warehouse",
            "source_timestamp",
            "business_date",
            "document_no",
            "customer_name",
            "customer_norm",
            "pos_reference",
            "subtotal_cents",
            "discount_cents",
            "total_cents",
            "cash_cents",
            "card_cents",
            "credit_cents",
            "payment_type",
            "status",
            "paid_total_cents",
            "balance_cents",
        ],
        source_rows,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;",
            "CREATE TEMPORARY TABLE tmp_customer_source AS",
            "SELECT",
            "  customer_norm,",
            "  MIN(customer_name) AS customer_name",
            "FROM tmp_sales_source",
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
            "DROP TEMPORARY TABLE IF EXISTS tmp_sales_customer_resolution;",
            "CREATE TEMPORARY TABLE tmp_sales_customer_resolution AS",
            "SELECT",
            "  s.source_row_num,",
            "  s.customer_norm,",
            "  cu.customer_id,",
            "  CASE",
            "    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'",
            "    WHEN cu.customer_id IS NULL THEN 'missing'",
            "    ELSE 'resolved'",
            "  END AS customer_resolution",
            "FROM tmp_sales_source s",
            "LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = s.customer_norm",
            "LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = s.customer_norm;",
            "ALTER TABLE tmp_sales_customer_resolution",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_sales_customer_resolution_state (customer_resolution),",
            "  ADD KEY idx_tmp_sales_customer_resolution_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_invoice_resolution;",
            "CREATE TEMPORARY TABLE tmp_invoice_resolution AS",
            "SELECT",
            "  s.source_row_num,",
            "  s.document_no,",
            "  s.pos_reference,",
            "  cr.customer_id,",
            "  cr.customer_resolution,",
            "  inv_num.id AS invoice_by_number_id,",
            "  inv_pos.id AS invoice_by_pos_id,",
            "  CASE",
            "    WHEN cr.customer_resolution <> 'resolved' THEN 'skip_customer'",
            "    WHEN inv_num.id IS NOT NULL AND inv_pos.id IS NOT NULL AND inv_num.id <> inv_pos.id THEN 'skip_conflict'",
            "    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL THEN 'insert'",
            "    ELSE 'update'",
            "  END AS resolution_status,",
            "  COALESCE(inv_num.id, inv_pos.id) AS resolved_invoice_id",
            "FROM tmp_sales_source s",
            "JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = s.source_row_num",
            "LEFT JOIN ar_invoices inv_num",
            f"  ON inv_num.branch_id = {branch_id}",
            "  AND inv_num.type = 'invoice'",
            f"  AND inv_num.invoice_number COLLATE {COLLATION} = s.document_no COLLATE {COLLATION}",
            "LEFT JOIN ar_invoices inv_pos",
            f"  ON inv_pos.branch_id = {branch_id}",
            "  AND inv_pos.type = 'invoice'",
            "  AND s.pos_reference IS NOT NULL",
            f"  AND inv_pos.pos_reference COLLATE {COLLATION} = s.pos_reference COLLATE {COLLATION};",
            "ALTER TABLE tmp_invoice_resolution",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_invoice_resolution_status (resolution_status),",
            "  ADD KEY idx_tmp_invoice_resolution_invoice_id (resolved_invoice_id);",
            "",
            "UPDATE ar_invoices ai",
            "JOIN tmp_invoice_resolution r",
            "  ON r.resolution_status = 'update'",
            " AND r.resolved_invoice_id = ai.id",
            "JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num",
            "SET",
            "  ai.customer_id = r.customer_id,",
            "  ai.source = 'import',",
            "  ai.type = 'invoice',",
            "  ai.invoice_number = s.document_no,",
            "  ai.status = s.status,",
            "  ai.payment_type = s.payment_type,",
            "  ai.issue_date = s.business_date,",
            "  ai.due_date = s.business_date,",
            "  ai.currency = 'QAR',",
            "  ai.subtotal_cents = s.subtotal_cents,",
            "  ai.discount_total_cents = s.discount_cents,",
            "  ai.invoice_discount_type = 'fixed',",
            "  ai.invoice_discount_value = s.discount_cents,",
            "  ai.invoice_discount_cents = s.discount_cents,",
            "  ai.tax_total_cents = 0,",
            "  ai.total_cents = s.total_cents,",
            "  ai.paid_total_cents = s.paid_total_cents,",
            "  ai.balance_cents = s.balance_cents,",
            "  ai.pos_reference = s.pos_reference,",
            f"  ai.created_at = COALESCE({source_ts_expr}, ai.created_at),",
            f"  ai.updated_at = COALESCE({source_ts_expr}, ai.updated_at);",
            "SET @updated_invoice_rows := ROW_COUNT();",
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
            "  pos_reference,",
            "  notes,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            f"  {branch_id} AS branch_id,",
            "  r.customer_id,",
            "  'import' AS source,",
            "  'invoice' AS type,",
            "  s.document_no AS invoice_number,",
            "  s.status,",
            "  s.payment_type,",
            "  s.business_date AS issue_date,",
            "  s.business_date AS due_date,",
            "  'QAR' AS currency,",
            "  s.subtotal_cents,",
            "  s.discount_cents AS discount_total_cents,",
            "  'fixed' AS invoice_discount_type,",
            "  s.discount_cents AS invoice_discount_value,",
            "  s.discount_cents AS invoice_discount_cents,",
            "  0 AS tax_total_cents,",
            "  s.total_cents,",
            "  s.paid_total_cents,",
            "  s.balance_cents,",
            "  s.pos_reference,",
            "  'Imported from Sales Entry Daily Report' AS notes,",
            f"  COALESCE({source_ts_expr}, NOW()) AS created_at,",
            f"  COALESCE({source_ts_expr}, NOW()) AS updated_at",
            "FROM tmp_invoice_resolution r",
            "JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num",
            "WHERE r.resolution_status = 'insert'",
            "ORDER BY s.source_row_num;",
            "SET @inserted_invoice_rows := ROW_COUNT();",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_invoice_ids;",
            "CREATE TEMPORARY TABLE tmp_target_invoice_ids AS",
            "SELECT",
            "  r.source_row_num,",
            "  CASE",
            "    WHEN r.resolution_status = 'update' THEN r.resolved_invoice_id",
            "    ELSE ai.id",
            "  END AS invoice_id",
            "FROM tmp_invoice_resolution r",
            "JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num",
            "LEFT JOIN ar_invoices ai",
            "  ON r.resolution_status = 'insert'",
            f" AND ai.branch_id = {branch_id}",
            " AND ai.type = 'invoice'",
            f" AND ai.invoice_number COLLATE {COLLATION} = s.document_no COLLATE {COLLATION}",
            "WHERE r.resolution_status IN ('insert', 'update');",
            "ALTER TABLE tmp_target_invoice_ids",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_target_invoice_ids_invoice_id (invoice_id);",
            "",
            "DELETE ii",
            "FROM ar_invoice_items ii",
            "JOIN (SELECT DISTINCT invoice_id FROM tmp_target_invoice_ids) t",
            "  ON t.invoice_id = ii.invoice_id;",
            "SET @deleted_invoice_item_rows := ROW_COUNT();",
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
            f"  COALESCE({source_ts_expr}, NOW()) AS created_at,",
            f"  COALESCE({source_ts_expr}, NOW()) AS updated_at",
            "FROM tmp_target_invoice_ids t",
            "JOIN tmp_sales_source s ON s.source_row_num = t.source_row_num",
            "ORDER BY t.source_row_num;",
            "SET @inserted_invoice_item_rows := ROW_COUNT();",
            "",
            "SET @source_rows_loaded := (SELECT COUNT(*) FROM tmp_sales_source);",
            "SET @source_distinct_customers := (SELECT COUNT(*) FROM tmp_customer_source);",
            "SET @skipped_conflict_rows := (",
            "  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_conflict'",
            ");",
            "SET @skipped_customer_rows := (",
            "  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_customer'",
            ");",
            "",
            "-- Summary",
            "SELECT",
            "  @source_rows_loaded AS source_rows_loaded,",
            "  @source_distinct_customers AS source_distinct_customers,",
            "  @inserted_customers AS inserted_customers,",
            "  @inserted_invoice_rows AS inserted_invoices,",
            "  @updated_invoice_rows AS updated_invoices,",
            "  @skipped_conflict_rows AS skipped_conflict_rows,",
            "  @skipped_customer_rows AS skipped_customer_rows,",
            "  @deleted_invoice_item_rows AS deleted_existing_invoice_items,",
            "  @inserted_invoice_item_rows AS inserted_invoice_items;",
            "",
            "-- Skipped rows due to invoice-number/POS-reference conflicts",
            "SELECT",
            "  source_row_num,",
            "  document_no,",
            "  pos_reference,",
            "  invoice_by_number_id,",
            "  invoice_by_pos_id",
            "FROM tmp_invoice_resolution",
            "WHERE resolution_status = 'skip_conflict'",
            "ORDER BY source_row_num;",
            "",
            "-- Skipped rows due to unresolved customer matching",
            "SELECT",
            "  r.source_row_num,",
            "  s.customer_name,",
            "  s.customer_norm,",
            "  cr.customer_resolution",
            "FROM tmp_invoice_resolution r",
            "JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num",
            "JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = r.source_row_num",
            "WHERE r.resolution_status = 'skip_customer'",
            "ORDER BY r.source_row_num;",
            "",
            "-- Breakdown by mapped status/payment type in the source range",
            "SELECT",
            "  status,",
            "  payment_type,",
            "  COUNT(*) AS row_count",
            "FROM tmp_sales_source",
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
    default_source = Path("docs/csv/Sales_entry_dailyreport_2026-03-01_07_52PM.csv")
    default_output = Path(
        "database/seeders/sql/ar_invoices_upsert_from_sales_entry_dailyreport_2026_03_01_07_52PM.sql"
    )

    parser = argparse.ArgumentParser(
        description="Generate deterministic AR invoices upsert SQL from sales-entry CSV."
    )
    parser.add_argument("--source", type=Path, default=default_source, help="Path to source CSV file")
    parser.add_argument("--output", type=Path, default=default_output, help="Path to write generated SQL")
    parser.add_argument(
        "--start-date",
        type=lambda v: dt.date.fromisoformat(v),
        default=DEFAULT_START_DATE,
        help="Inclusive start date (YYYY-MM-DD)",
    )
    parser.add_argument(
        "--end-date",
        type=lambda v: dt.date.fromisoformat(v),
        default=DEFAULT_END_DATE,
        help="Inclusive end date (YYYY-MM-DD)",
    )
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

    if args.start_date > args.end_date:
        die(f"start-date {args.start_date} must be <= end-date {args.end_date}")

    rows, facts = load_source(
        csv_path=source,
        start_date=args.start_date,
        end_date=args.end_date,
        strict_facts=not args.no_strict_facts,
    )
    sql = render_sql(
        source_csv=source,
        rows=rows,
        facts=facts,
        start_date=args.start_date,
        end_date=args.end_date,
        branch_id=args.branch_id,
    )

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(sql, encoding="utf-8")

    print(f"Generated SQL: {output}")
    print(f"Rows loaded in range: {facts['filtered_rows']}")
    print(f"Range min date: {facts['filtered_min_date']}")
    print(f"Range max date: {facts['filtered_max_date']}")


if __name__ == "__main__":
    main()
