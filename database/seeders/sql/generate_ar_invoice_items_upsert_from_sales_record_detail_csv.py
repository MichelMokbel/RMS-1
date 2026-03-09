#!/usr/bin/env python3
"""
Generate deterministic MySQL upsert SQL for AR invoice item lines from sales-record-detail CSV.

Locked behavior:
- Match existing invoice by (branch_id, invoice_number), then fallback to (branch_id, pos_reference).
- Skip matched invoices in voided status.
- For matched non-voided invoices, replace items only when grouped CSV total matches existing invoice total.
- If invoice is missing, create invoice (and auto-create customer if missing) then insert item lines.
- Preserve negative quantity/amount lines.
- Keep existing invoice totals unchanged.
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
REQUIRED_HEADERS = [
    "Invoice No",
    "POS Ref.No",
    "Date",
    "Customer Name",
    "Warehouse",
    "Payment Type",
    "Item Name",
    "Quantity",
    "Unit Price",
    "Total Sales",
    "Discount",
]

DEFAULT_SOURCE = Path("docs/csv/data/Sales_record_detail_csv.csv")
DEFAULT_OUTPUT = Path(
    "database/seeders/sql/ar_invoice_items_upsert_from_sales_record_detail_2026_03_06_04_37PM.sql"
)

EXPECTED_FACTS = {
    "total_csv_rows": 10274,
    "distinct_invoices": 7332,
    "missing_invoice_no_rows": 1,
    "negative_qty_rows": 2,
    "negative_total_rows": 2,
    "warehouse_distinct_count": 1,
    "warehouse_only_branch_1": 1,
}


def die(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def normalize_spaces(value: str) -> str:
    return " ".join(value.strip().split())


def normalize_name(value: str) -> str:
    return normalize_spaces(value).lower()


def normalize_doc(value: str) -> str:
    return normalize_spaces(value)


def parse_timestamp(raw: str) -> dt.datetime:
    text = normalize_spaces(raw)
    if text == "":
        die("Date cannot be empty.")
    try:
        return dt.datetime.fromisoformat(text)
    except ValueError:
        pass

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
    die(f"Invalid Date value: {raw!r}")


def parse_decimal(raw: str, field_name: str) -> Decimal:
    text = normalize_spaces(raw).replace(",", "")
    if text == "":
        die(f"Missing numeric value for {field_name}.")
    try:
        return Decimal(text)
    except InvalidOperation:
        die(f"Invalid numeric value for {field_name}: {raw!r}")


def parse_money(raw: str, field_name: str) -> Decimal:
    return parse_decimal(raw, field_name).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def parse_qty(raw: str) -> Decimal:
    return parse_decimal(raw, "Quantity").quantize(Decimal("0.001"), rounding=ROUND_HALF_UP)


def money_to_cents(amount: Decimal) -> int:
    return int((amount * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def normalize_payment_type(raw: str) -> str:
    text = normalize_spaces(raw).lower()
    if "cash" in text:
        return "cash"
    if "card" in text:
        return "card"
    return "credit"


def sql_quote(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = value.replace("\\", "\\\\").replace("'", "''")
    return f"'{escaped}'"


def sql_int(value: int) -> str:
    return str(int(value))


def sql_decimal(value: Decimal, scale: int = 3) -> str:
    quant = Decimal("1").scaleb(-scale)
    val = value.quantize(quant, rounding=ROUND_HALF_UP)
    return format(val, f".{scale}f")


def chunks[T](rows: Sequence[T], size: int) -> Iterable[Sequence[T]]:
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
class LineRow:
    source_row_num: int
    invoice_number: str
    invoice_number_norm: str
    pos_reference: str | None
    source_timestamp: str
    business_date: str
    customer_name: str
    customer_norm: str
    warehouse: str
    payment_type: str
    item_name: str
    unit: str | None
    qty: Decimal
    unit_price_cents: int
    line_discount_cents: int
    line_total_cents: int


@dataclass
class InvoiceGroupCheck:
    invoice_number_norm: str
    invoice_number_display: str
    timestamps: set[str]
    pos_refs: set[str]
    customer_norms: set[str]
    payment_types: set[str]


def load_source(
    source_csv: Path,
    start_date: dt.date | None,
    end_date: dt.date | None,
    strict_facts: bool,
) -> tuple[List[LineRow], Dict[str, int | str]]:
    if not source_csv.exists():
        die(f"CSV file not found: {source_csv}")

    rows: List[LineRow] = []
    total_csv_rows = 0
    missing_invoice_no_rows = 0
    negative_qty_rows = 0
    negative_total_rows = 0
    warehouses: set[str] = set()

    groups: Dict[str, InvoiceGroupCheck] = {}

    with source_csv.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            die("CSV has no header row.")

        headers = [h.strip() for h in reader.fieldnames]
        missing_headers = [h for h in REQUIRED_HEADERS if h not in headers]
        if missing_headers:
            die(f"Missing required CSV headers: {', '.join(missing_headers)}")

        for source_row_num, row in enumerate(reader, start=2):
            total_csv_rows += 1

            invoice_number = normalize_doc(row["Invoice No"])
            if invoice_number == "":
                missing_invoice_no_rows += 1
                continue

            timestamp = parse_timestamp(row["Date"])
            business_date = timestamp.date()
            if start_date and business_date < start_date:
                continue
            if end_date and business_date > end_date:
                continue

            customer_name = normalize_spaces(row["Customer Name"])
            if customer_name == "":
                die(f"Missing Customer Name at row {source_row_num}.")

            item_name = normalize_spaces(row["Item Name"])
            if item_name == "":
                die(f"Missing Item Name at row {source_row_num}.")

            qty = parse_qty(row["Quantity"])
            if qty < 0:
                negative_qty_rows += 1

            total_sales = parse_money(row["Total Sales"], "Total Sales")
            if total_sales < 0:
                negative_total_rows += 1

            unit_price = parse_money(row["Unit Price"], "Unit Price")
            discount = parse_money(row["Discount"], "Discount")

            pos_reference = normalize_spaces(row["POS Ref.No"]) or None
            payment_type = normalize_payment_type(row["Payment Type"])
            warehouse = normalize_spaces(row["Warehouse"])
            if warehouse:
                warehouses.add(warehouse.lower())

            invoice_norm = invoice_number.lower()
            source_ts = timestamp.isoformat(sep="T", timespec="microseconds")

            rows.append(
                LineRow(
                    source_row_num=source_row_num,
                    invoice_number=invoice_number,
                    invoice_number_norm=invoice_norm,
                    pos_reference=pos_reference,
                    source_timestamp=source_ts,
                    business_date=business_date.isoformat(),
                    customer_name=customer_name,
                    customer_norm=normalize_name(customer_name),
                    warehouse=warehouse,
                    payment_type=payment_type,
                    item_name=item_name,
                    unit=normalize_spaces(row.get("Unit", "")) or None,
                    qty=qty,
                    unit_price_cents=money_to_cents(unit_price),
                    line_discount_cents=money_to_cents(discount),
                    line_total_cents=money_to_cents(total_sales),
                )
            )

            group = groups.get(invoice_norm)
            if group is None:
                group = InvoiceGroupCheck(
                    invoice_number_norm=invoice_norm,
                    invoice_number_display=invoice_number,
                    timestamps=set(),
                    pos_refs=set(),
                    customer_norms=set(),
                    payment_types=set(),
                )
                groups[invoice_norm] = group

            group.timestamps.add(source_ts)
            if pos_reference:
                group.pos_refs.add(pos_reference)
            group.customer_norms.add(normalize_name(customer_name))
            group.payment_types.add(payment_type)

    if not rows:
        die("No eligible rows found in source CSV.")

    date_conflicts = [g.invoice_number_display for g in groups.values() if len(g.timestamps) > 1]
    pos_conflicts = [g.invoice_number_display for g in groups.values() if len(g.pos_refs) > 1]
    customer_conflicts = [g.invoice_number_display for g in groups.values() if len(g.customer_norms) > 1]
    payment_conflicts = [g.invoice_number_display for g in groups.values() if len(g.payment_types) > 1]

    if date_conflicts or pos_conflicts or customer_conflicts or payment_conflicts:
        samples = []
        if date_conflicts:
            samples.append(f"date={', '.join(date_conflicts[:5])}")
        if pos_conflicts:
            samples.append(f"pos={', '.join(pos_conflicts[:5])}")
        if customer_conflicts:
            samples.append(f"customer={', '.join(customer_conflicts[:5])}")
        if payment_conflicts:
            samples.append(f"payment={', '.join(payment_conflicts[:5])}")
        die("Invoice group conflicts detected: " + " | ".join(samples))

    facts: Dict[str, int | str] = {
        "total_csv_rows": total_csv_rows,
        "line_rows_loaded": len(rows),
        "distinct_invoices": len(groups),
        "missing_invoice_no_rows": missing_invoice_no_rows,
        "negative_qty_rows": negative_qty_rows,
        "negative_total_rows": negative_total_rows,
        "warehouse_distinct_count": len(warehouses),
        "warehouse_only_branch_1": 1 if warehouses == {"branch 1"} else 0,
        "min_business_date": min(r.business_date for r in rows),
        "max_business_date": max(r.business_date for r in rows),
    }

    if strict_facts:
        for key, expected in EXPECTED_FACTS.items():
            actual = facts.get(key)
            if actual != expected:
                die(f"Source fact mismatch for {key}: expected {expected!r}, got {actual!r}")

    return rows, facts


def render_sql(
    source_csv: Path,
    rows: List[LineRow],
    facts: Dict[str, int | str],
    branch_id: int,
    start_date: dt.date | None,
    end_date: dt.date | None,
) -> str:
    generated_at = dt.datetime.now().replace(microsecond=0).isoformat()
    line_ts_expr = "STR_TO_DATE(LEFT(REPLACE(l.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s')"
    group_ts_expr = "STR_TO_DATE(LEFT(REPLACE(m.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s')"

    out: List[str] = []
    out.extend(
        [
            "-- Generated SQL: AR invoice item-lines upsert from sales-record-detail CSV",
            f"-- Source file: {source_csv}",
            f"-- Generated at: {generated_at}",
            f"-- Branch ID: {branch_id}",
            f"-- Date filter (inclusive): {start_date.isoformat() if start_date else 'ALL'} to {end_date.isoformat() if end_date else 'ALL'}",
            f"-- Total CSV rows: {facts['total_csv_rows']}",
            f"-- Loaded line rows: {facts['line_rows_loaded']}",
            f"-- Distinct invoices: {facts['distinct_invoices']}",
            f"-- Skipped rows with missing Invoice No: {facts['missing_invoice_no_rows']}",
            f"-- Negative-qty rows: {facts['negative_qty_rows']}",
            f"-- Negative-total rows: {facts['negative_total_rows']}",
            f"-- Loaded min date: {facts['min_business_date']}",
            f"-- Loaded max date: {facts['max_business_date']}",
            "-- Matching: existing invoice by (branch, invoice_number), fallback (branch, pos_reference)",
            "-- Existing non-voided invoice lines replaced only when grouped CSV invoice total equals existing invoice total",
            "-- Missing invoices are created; missing customers are auto-created when unique by normalized name",
            "",
            "START TRANSACTION;",
            "",
            "SET @inserted_customers := 0;",
            "SET @created_invoice_rows := 0;",
            "SET @deleted_invoice_item_rows := 0;",
            "SET @inserted_invoice_item_rows := 0;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_sales_record_detail_lines;",
            "CREATE TEMPORARY TABLE tmp_sales_record_detail_lines (",
            "  source_row_num INT NOT NULL,",
            "  invoice_number VARCHAR(64) NOT NULL,",
            f"  invoice_number_norm VARCHAR(64) NOT NULL COLLATE {COLLATION},",
            "  pos_reference VARCHAR(191) DEFAULT NULL,",
            "  source_timestamp VARCHAR(40) NOT NULL,",
            "  business_date DATE NOT NULL,",
            "  customer_name VARCHAR(191) NOT NULL,",
            f"  customer_norm VARCHAR(191) NOT NULL COLLATE {COLLATION},",
            "  warehouse VARCHAR(100) NOT NULL,",
            "  payment_type VARCHAR(20) NOT NULL,",
            "  item_name VARCHAR(255) NOT NULL,",
            "  unit VARCHAR(40) DEFAULT NULL,",
            "  qty DECIMAL(12,3) NOT NULL,",
            "  unit_price_cents BIGINT NOT NULL,",
            "  line_discount_cents BIGINT NOT NULL,",
            "  line_total_cents BIGINT NOT NULL,",
            "  PRIMARY KEY (source_row_num),",
            "  KEY idx_tmp_srdl_invoice_norm (invoice_number_norm),",
            "  KEY idx_tmp_srdl_customer_norm (customer_norm),",
            "  KEY idx_tmp_srdl_pos_reference (pos_reference),",
            "  KEY idx_tmp_srdl_business_date (business_date)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    line_values = [
        [
            sql_int(r.source_row_num),
            sql_quote(r.invoice_number),
            sql_quote(r.invoice_number_norm),
            sql_quote(r.pos_reference),
            sql_quote(r.source_timestamp),
            sql_quote(r.business_date),
            sql_quote(r.customer_name),
            sql_quote(r.customer_norm),
            sql_quote(r.warehouse),
            sql_quote(r.payment_type),
            sql_quote(r.item_name),
            sql_quote(r.unit),
            sql_decimal(r.qty, 3),
            sql_int(r.unit_price_cents),
            sql_int(r.line_discount_cents),
            sql_int(r.line_total_cents),
        ]
        for r in rows
    ]

    append_insert(
        out,
        "tmp_sales_record_detail_lines",
        [
            "source_row_num",
            "invoice_number",
            "invoice_number_norm",
            "pos_reference",
            "source_timestamp",
            "business_date",
            "customer_name",
            "customer_norm",
            "warehouse",
            "payment_type",
            "item_name",
            "unit",
            "qty",
            "unit_price_cents",
            "line_discount_cents",
            "line_total_cents",
        ],
        line_values,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_sales_record_detail_invoice_groups;",
            "CREATE TEMPORARY TABLE tmp_sales_record_detail_invoice_groups AS",
            "SELECT",
            "  MIN(source_row_num) AS group_row_num,",
            "  MIN(invoice_number) AS invoice_number,",
            "  invoice_number_norm,",
            "  MIN(pos_reference) AS pos_reference,",
            "  MIN(source_timestamp) AS source_timestamp,",
            "  MIN(business_date) AS business_date,",
            "  MIN(customer_name) AS customer_name,",
            "  MIN(customer_norm) AS customer_norm,",
            "  MIN(payment_type) AS payment_type,",
            "  SUM(line_total_cents + line_discount_cents) AS subtotal_cents,",
            "  SUM(line_discount_cents) AS discount_cents,",
            "  SUM(line_total_cents) AS total_cents,",
            "  COUNT(*) AS line_count",
            "FROM tmp_sales_record_detail_lines",
            "GROUP BY invoice_number_norm;",
            "ALTER TABLE tmp_sales_record_detail_invoice_groups",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD UNIQUE KEY uq_tmp_srdig_invoice_norm (invoice_number_norm),",
            "  ADD KEY idx_tmp_srdig_invoice_number (invoice_number),",
            "  ADD KEY idx_tmp_srdig_customer_norm (customer_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;",
            "CREATE TEMPORARY TABLE tmp_customer_source AS",
            "SELECT customer_norm, MIN(customer_name) AS customer_name",
            "FROM tmp_sales_record_detail_invoice_groups",
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
            "DROP TEMPORARY TABLE IF EXISTS tmp_missing_customers;",
            "CREATE TEMPORARY TABLE tmp_missing_customers AS",
            "SELECT s.customer_norm, s.customer_name",
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
            "DROP TEMPORARY TABLE IF EXISTS tmp_group_customer_resolution;",
            "CREATE TEMPORARY TABLE tmp_group_customer_resolution AS",
            "SELECT",
            "  g.group_row_num,",
            "  g.invoice_number_norm,",
            "  cu.customer_id,",
            "  CASE",
            "    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'",
            "    WHEN cu.customer_id IS NULL THEN 'missing'",
            "    ELSE 'resolved'",
            "  END AS customer_resolution",
            "FROM tmp_sales_record_detail_invoice_groups g",
            "LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = g.customer_norm",
            "LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = g.customer_norm;",
            "ALTER TABLE tmp_group_customer_resolution",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_group_customer_resolution_state (customer_resolution),",
            "  ADD KEY idx_tmp_group_customer_resolution_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_invoice_match;",
            "CREATE TEMPORARY TABLE tmp_invoice_match AS",
            "SELECT",
            "  g.group_row_num,",
            "  g.invoice_number,",
            "  g.invoice_number_norm,",
            "  g.pos_reference,",
            "  g.source_timestamp,",
            "  g.business_date,",
            "  g.customer_name,",
            "  g.customer_norm,",
            "  g.payment_type,",
            "  g.subtotal_cents,",
            "  g.discount_cents,",
            "  g.total_cents AS grouped_total_cents,",
            "  g.line_count,",
            "  cr.customer_id,",
            "  cr.customer_resolution,",
            "  inv_num.id AS invoice_by_number_id,",
            "  inv_num.status AS invoice_by_number_status,",
            "  inv_num.total_cents AS invoice_by_number_total_cents,",
            "  inv_pos.id AS invoice_by_pos_id,",
            "  inv_pos.status AS invoice_by_pos_status,",
            "  inv_pos.total_cents AS invoice_by_pos_total_cents,",
            "  COALESCE(inv_num.id, inv_pos.id) AS resolved_invoice_id,",
            "  COALESCE(inv_num.status, inv_pos.status) AS resolved_invoice_status,",
            "  COALESCE(inv_num.total_cents, inv_pos.total_cents) AS resolved_invoice_total_cents,",
            "  CASE",
            "    WHEN inv_num.id IS NOT NULL AND inv_pos.id IS NOT NULL AND inv_num.id <> inv_pos.id THEN 'skip_conflict'",
            "    WHEN COALESCE(inv_num.status, inv_pos.status) = 'voided' THEN 'skip_voided'",
            "    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL AND cr.customer_resolution <> 'resolved' THEN 'skip_customer'",
            "    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL THEN 'create_new'",
            "    ELSE 'matched'",
            "  END AS resolution_status",
            "FROM tmp_sales_record_detail_invoice_groups g",
            "LEFT JOIN tmp_group_customer_resolution cr ON cr.group_row_num = g.group_row_num",
            "LEFT JOIN ar_invoices inv_num",
            f"  ON inv_num.branch_id = {branch_id}",
            "  AND inv_num.type = 'invoice'",
            f"  AND inv_num.invoice_number COLLATE {COLLATION} = g.invoice_number COLLATE {COLLATION}",
            "LEFT JOIN ar_invoices inv_pos",
            f"  ON inv_pos.branch_id = {branch_id}",
            "  AND inv_pos.type = 'invoice'",
            "  AND g.pos_reference IS NOT NULL",
            f"  AND inv_pos.pos_reference COLLATE {COLLATION} = g.pos_reference COLLATE {COLLATION};",
            "ALTER TABLE tmp_invoice_match",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_invoice_match_status (resolution_status),",
            "  ADD KEY idx_tmp_invoice_match_invoice_id (resolved_invoice_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_matched_total_check;",
            "CREATE TEMPORARY TABLE tmp_matched_total_check AS",
            "SELECT",
            "  m.group_row_num,",
            "  m.invoice_number,",
            "  m.resolved_invoice_id,",
            "  m.grouped_total_cents,",
            "  m.resolved_invoice_total_cents,",
            "  CASE",
            "    WHEN m.grouped_total_cents = m.resolved_invoice_total_cents THEN 'ok'",
            "    ELSE 'skip_total_mismatch'",
            "  END AS total_status",
            "FROM tmp_invoice_match m",
            "WHERE m.resolution_status = 'matched';",
            "ALTER TABLE tmp_matched_total_check",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_matched_total_check_status (total_status),",
            "  ADD KEY idx_tmp_matched_total_check_invoice_id (resolved_invoice_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_replace_targets;",
            "CREATE TEMPORARY TABLE tmp_replace_targets AS",
            "SELECT",
            "  m.group_row_num,",
            "  m.invoice_number_norm,",
            "  m.invoice_number,",
            "  m.resolved_invoice_id AS invoice_id",
            "FROM tmp_invoice_match m",
            "LEFT JOIN tmp_matched_total_check tc ON tc.group_row_num = m.group_row_num",
            "WHERE m.resolution_status = 'matched'",
            "  AND COALESCE(tc.total_status, 'skip_total_mismatch') = 'ok';",
            "ALTER TABLE tmp_replace_targets",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_replace_targets_invoice_id (invoice_id);",
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
            "  m.customer_id,",
            "  'import' AS source,",
            "  'invoice' AS type,",
            "  m.invoice_number AS invoice_number,",
            "  CASE WHEN m.payment_type IN ('cash','card') THEN 'paid' ELSE 'issued' END AS status,",
            "  m.payment_type,",
            "  m.business_date AS issue_date,",
            "  m.business_date AS due_date,",
            "  'QAR' AS currency,",
            "  m.subtotal_cents,",
            "  m.discount_cents AS discount_total_cents,",
            "  'fixed' AS invoice_discount_type,",
            "  m.discount_cents AS invoice_discount_value,",
            "  m.discount_cents AS invoice_discount_cents,",
            "  0 AS tax_total_cents,",
            "  m.grouped_total_cents AS total_cents,",
            "  CASE WHEN m.payment_type IN ('cash','card') THEN m.grouped_total_cents ELSE 0 END AS paid_total_cents,",
            "  CASE WHEN m.payment_type IN ('cash','card') THEN 0 ELSE m.grouped_total_cents END AS balance_cents,",
            "  m.pos_reference,",
            "  'Imported from Sales Record Detail CSV' AS notes,",
            f"  COALESCE({group_ts_expr}, NOW()) AS created_at,",
            f"  COALESCE({group_ts_expr}, NOW()) AS updated_at",
            "FROM tmp_invoice_match m",
            "WHERE m.resolution_status = 'create_new'",
            "ORDER BY m.group_row_num;",
            "SET @created_invoice_rows := ROW_COUNT();",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_created_targets;",
            "CREATE TEMPORARY TABLE tmp_created_targets AS",
            "SELECT",
            "  m.group_row_num,",
            "  m.invoice_number_norm,",
            "  m.invoice_number,",
            "  ai.id AS invoice_id",
            "FROM tmp_invoice_match m",
            "JOIN ar_invoices ai",
            f"  ON ai.branch_id = {branch_id}",
            "  AND ai.type = 'invoice'",
            f"  AND ai.invoice_number COLLATE {COLLATION} = m.invoice_number COLLATE {COLLATION}",
            "WHERE m.resolution_status = 'create_new';",
            "ALTER TABLE tmp_created_targets",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_created_targets_invoice_id (invoice_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_invoice_ids;",
            "CREATE TEMPORARY TABLE tmp_target_invoice_ids AS",
            "SELECT",
            "  t.group_row_num,",
            "  t.invoice_number_norm,",
            "  t.invoice_number,",
            "  t.invoice_id,",
            "  'replace' AS target_mode",
            "FROM tmp_replace_targets t",
            "UNION ALL",
            "SELECT",
            "  t.group_row_num,",
            "  t.invoice_number_norm,",
            "  t.invoice_number,",
            "  t.invoice_id,",
            "  'create' AS target_mode",
            "FROM tmp_created_targets t;",
            "ALTER TABLE tmp_target_invoice_ids",
            "  ADD PRIMARY KEY (group_row_num),",
            "  ADD KEY idx_tmp_target_invoice_ids_invoice_id (invoice_id),",
            "  ADD KEY idx_tmp_target_invoice_ids_mode (target_mode);",
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
            "  l.item_name AS description,",
            "  l.qty,",
            "  l.unit_price_cents,",
            "  l.line_discount_cents AS discount_cents,",
            "  0 AS tax_cents,",
            "  l.line_total_cents,",
            f"  COALESCE({line_ts_expr}, NOW()) AS created_at,",
            f"  COALESCE({line_ts_expr}, NOW()) AS updated_at",
            "FROM tmp_target_invoice_ids t",
            "JOIN tmp_sales_record_detail_lines l",
            "  ON l.invoice_number_norm = t.invoice_number_norm",
            "ORDER BY l.source_row_num;",
            "SET @inserted_invoice_item_rows := ROW_COUNT();",
            "",
            f"SET @source_total_csv_rows := {int(facts['total_csv_rows'])};",
            f"SET @source_line_rows_loaded := {int(facts['line_rows_loaded'])};",
            f"SET @source_distinct_invoices := {int(facts['distinct_invoices'])};",
            f"SET @source_missing_invoice_no_rows := {int(facts['missing_invoice_no_rows'])};",
            f"SET @source_negative_qty_rows := {int(facts['negative_qty_rows'])};",
            f"SET @source_negative_total_rows := {int(facts['negative_total_rows'])};",
            "",
            "SET @invoice_group_rows := (SELECT COUNT(*) FROM tmp_sales_record_detail_invoice_groups);",
            "SET @replaced_invoice_rows := (SELECT COUNT(DISTINCT invoice_id) FROM tmp_replace_targets);",
            "SET @target_invoice_rows := (SELECT COUNT(DISTINCT invoice_id) FROM tmp_target_invoice_ids);",
            "SET @skip_conflict_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_conflict');",
            "SET @skip_voided_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_voided');",
            "SET @skip_customer_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_customer');",
            "SET @skip_total_mismatch_rows := (SELECT COUNT(*) FROM tmp_matched_total_check WHERE total_status = 'skip_total_mismatch');",
            "",
            "-- Summary",
            "SELECT",
            "  @source_total_csv_rows AS source_total_csv_rows,",
            "  @source_line_rows_loaded AS source_line_rows_loaded,",
            "  @source_distinct_invoices AS source_distinct_invoices,",
            "  @source_missing_invoice_no_rows AS source_missing_invoice_no_rows,",
            "  @source_negative_qty_rows AS source_negative_qty_rows,",
            "  @source_negative_total_rows AS source_negative_total_rows,",
            "  @invoice_group_rows AS invoice_group_rows,",
            "  @inserted_customers AS inserted_customers,",
            "  @created_invoice_rows AS created_invoices,",
            "  @replaced_invoice_rows AS replaced_existing_invoices,",
            "  @target_invoice_rows AS total_target_invoices,",
            "  @skip_conflict_rows AS skipped_conflict_rows,",
            "  @skip_voided_rows AS skipped_voided_rows,",
            "  @skip_customer_rows AS skipped_customer_rows,",
            "  @skip_total_mismatch_rows AS skipped_total_mismatch_rows,",
            "  @deleted_invoice_item_rows AS deleted_existing_invoice_items,",
            "  @inserted_invoice_item_rows AS inserted_invoice_items;",
            "",
            "-- Resolution-status breakdown",
            "SELECT resolution_status, COUNT(*) AS invoice_count",
            "FROM tmp_invoice_match",
            "GROUP BY resolution_status",
            "ORDER BY resolution_status;",
            "",
            "-- Skipped rows due to invoice-number/POS conflicts",
            "SELECT",
            "  group_row_num,",
            "  invoice_number,",
            "  pos_reference,",
            "  invoice_by_number_id,",
            "  invoice_by_pos_id",
            "FROM tmp_invoice_match",
            "WHERE resolution_status = 'skip_conflict'",
            "ORDER BY group_row_num;",
            "",
            "-- Skipped rows due to matched voided invoices",
            "SELECT",
            "  group_row_num,",
            "  invoice_number,",
            "  resolved_invoice_id,",
            "  resolved_invoice_status",
            "FROM tmp_invoice_match",
            "WHERE resolution_status = 'skip_voided'",
            "ORDER BY group_row_num;",
            "",
            "-- Skipped rows due to unresolved customer for new invoices",
            "SELECT",
            "  m.group_row_num,",
            "  m.invoice_number,",
            "  m.customer_name,",
            "  m.customer_norm,",
            "  m.customer_resolution",
            "FROM tmp_invoice_match m",
            "WHERE m.resolution_status = 'skip_customer'",
            "ORDER BY m.group_row_num;",
            "",
            "-- Skipped rows due to existing-total mismatch",
            "SELECT",
            "  m.group_row_num,",
            "  m.invoice_number,",
            "  m.resolved_invoice_id,",
            "  m.grouped_total_cents AS csv_group_total_cents,",
            "  m.resolved_invoice_total_cents AS existing_invoice_total_cents,",
            "  m.line_count",
            "FROM tmp_invoice_match m",
            "JOIN tmp_matched_total_check tc ON tc.group_row_num = m.group_row_num",
            "WHERE tc.total_status = 'skip_total_mismatch'",
            "ORDER BY m.group_row_num;",
            "",
            "-- ROLLBACK; -- Uncomment for dry-run safety.",
            "COMMIT;",
            "",
        ]
    )

    return "\n".join(out)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate deterministic AR invoice-item upsert SQL from sales-record-detail CSV."
    )
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE, help="Path to source CSV file")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="Path to write generated SQL")
    parser.add_argument("--branch-id", type=int, default=1, help="Target branch ID")
    parser.add_argument(
        "--start-date",
        type=lambda v: dt.date.fromisoformat(v),
        default=None,
        help="Optional inclusive start date filter (YYYY-MM-DD)",
    )
    parser.add_argument(
        "--end-date",
        type=lambda v: dt.date.fromisoformat(v),
        default=None,
        help="Optional inclusive end date filter (YYYY-MM-DD)",
    )
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

    if args.start_date and args.end_date and args.start_date > args.end_date:
        die(f"start-date {args.start_date} must be <= end-date {args.end_date}")

    rows, facts = load_source(
        source_csv=source,
        start_date=args.start_date,
        end_date=args.end_date,
        strict_facts=not args.no_strict_facts,
    )

    sql = render_sql(
        source_csv=source,
        rows=rows,
        facts=facts,
        branch_id=args.branch_id,
        start_date=args.start_date,
        end_date=args.end_date,
    )

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(sql, encoding="utf-8")

    print(f"Generated SQL: {output}")
    print(f"Loaded line rows: {facts['line_rows_loaded']}")
    print(f"Distinct invoices: {facts['distinct_invoices']}")
    print(f"Skipped missing Invoice No rows: {facts['missing_invoice_no_rows']}")
    print(f"Negative qty rows: {facts['negative_qty_rows']}")
    print(f"Negative total rows: {facts['negative_total_rows']}")


if __name__ == "__main__":
    main()
