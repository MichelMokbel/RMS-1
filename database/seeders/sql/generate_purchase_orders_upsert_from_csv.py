#!/usr/bin/env python3
"""
Generate deterministic MySQL upsert SQL for purchase orders + PO lines from CSV.

Locked behavior:
- Upsert purchase_orders by po_number (rerunnable).
- Replace line items for eligible purchase orders on each run.
- Insert missing inventory_items using name-only matching.
- Strict supplier matching by normalized name (skip missing/ambiguous).
- Skip whole PO when any line cannot resolve to a unique inventory item.
- Force utf8mb4_unicode_ci on normalized joins to avoid collation errors.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
from collections import Counter
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Dict, Iterable, List, Sequence, Tuple


REQUIRED_HEADERS = [
    "Document no",
    "Date Ordered",
    "Supplier",
    "Product",
    "UOM",
    "Unit Price",
    "Qty Ordered",
    "Line Amt",
    "Discount",
    "Document Status",
]

EXPECTED_FACTS = {
    "total_rows": 972,
    "valid_line_rows": 971,
    "footer_rows": 1,
    "distinct_pos": 319,
    "distinct_products": 303,
    "distinct_item_name_norm": 302,
    "duplicate_doc_product_keys": 2,
    "nonzero_discount_rows": 0,
    "status_counts": {"Completed": 971},
}

COLLATION = "utf8mb4_unicode_ci"


def die(message: str) -> None:
    raise SystemExit(f"ERROR: {message}")


def normalize_spaces(value: str) -> str:
    return " ".join(value.strip().split())


def normalize_name(value: str) -> str:
    return normalize_spaces(value).lower()


def parse_decimal(raw: str, scale: str) -> Decimal:
    text = raw.strip().replace(",", "")
    if text == "":
        return Decimal("0").quantize(Decimal(scale))
    try:
        val = Decimal(text)
    except InvalidOperation:
        die(f"Invalid numeric value: {raw!r}")
    return val.quantize(Decimal(scale), rounding=ROUND_HALF_UP)


def parse_datetime_to_date(raw: str) -> str:
    text = raw.strip()
    try:
        parsed = dt.datetime.strptime(text, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        die(f"Invalid Date Ordered value: {raw!r} (expected YYYY-MM-DD HH:MM:SS)")
    return parsed.date().isoformat()


def normalize_uom(raw: str) -> str:
    text = normalize_spaces(raw)
    if text.lower() == "eachch":
        return "Each"
    return text


def split_product(raw: str) -> Tuple[str, str]:
    text = normalize_spaces(raw)
    if "_" not in text:
        die(f"Invalid Product format (missing underscore suffix): {raw!r}")
    name, code = text.rsplit("_", 1)
    name = normalize_spaces(name)
    code = normalize_spaces(code)
    if not name:
        die(f"Invalid Product format (empty name): {raw!r}")
    if not code:
        die(f"Invalid Product format (empty code suffix): {raw!r}")
    return name, code


def sql_quote(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = value.replace("\\", "\\\\").replace("'", "''")
    return f"'{escaped}'"


def sql_decimal(value: Decimal) -> str:
    return format(value, "f")


@dataclass(frozen=True)
class SourceLine:
    source_row_num: int
    po_number: str
    order_date: str
    supplier_name: str
    supplier_norm: str
    document_status: str
    product_raw: str
    product_name: str
    item_name_norm: str
    source_product_code: str
    uom: str
    unit_price: Decimal
    qty_ordered: Decimal
    line_amt: Decimal
    discount: Decimal


@dataclass
class SourceHeader:
    po_number: str
    order_date: str
    supplier_name: str
    supplier_norm: str
    document_status: str
    source_line_count: int
    source_total_amount: Decimal


@dataclass(frozen=True)
class DistinctSourceItem:
    item_name_norm: str
    source_row_num: int
    product_name: str
    source_product_code: str
    uom: str
    representative_cost: Decimal


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


def load_source(csv_path: Path, strict_facts: bool = True) -> Tuple[List[SourceLine], List[SourceHeader], List[DistinctSourceItem], Dict[str, int], Dict[str, str]]:
    if not csv_path.exists():
        die(f"CSV file not found: {csv_path}")

    with csv_path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        if reader.fieldnames is None:
            die("CSV has no header row.")
        headers = [h.strip() for h in reader.fieldnames]
        missing = [h for h in REQUIRED_HEADERS if h not in headers]
        if missing:
            die(f"Missing required CSV headers: {', '.join(missing)}")

        total_rows = 0
        footer_rows = 0
        valid_lines: List[SourceLine] = []
        invalid_doc_rows: List[Tuple[int, str]] = []
        product_keys: Counter[Tuple[str, str]] = Counter()
        status_counts: Counter[str] = Counter()
        nonzero_discount_rows = 0
        product_norm_to_codes: Dict[str, set[str]] = {}

        for excel_row_num, row in enumerate(reader, start=2):
            total_rows += 1
            doc_no = normalize_spaces(row["Document no"])

            if doc_no.isdigit():
                supplier_name = normalize_spaces(row["Supplier"])
                if supplier_name == "":
                    die(f"Empty supplier in data row {excel_row_num}.")
                supplier_norm = normalize_name(supplier_name)

                status = normalize_spaces(row["Document Status"])
                if status == "":
                    die(f"Empty Document Status in data row {excel_row_num}.")
                status_counts[status] += 1

                product_raw = normalize_spaces(row["Product"])
                product_name, source_product_code = split_product(product_raw)
                item_name_norm = normalize_name(product_name)

                unit_price = parse_decimal(row["Unit Price"], "0.0001")
                qty_ordered = parse_decimal(row["Qty Ordered"], "0.001")
                line_amt = parse_decimal(row["Line Amt"], "0.01")
                discount = parse_decimal(row["Discount"], "0.01")
                if discount != Decimal("0.00"):
                    nonzero_discount_rows += 1

                uom = normalize_uom(row["UOM"])
                order_date = parse_datetime_to_date(row["Date Ordered"])

                product_keys[(doc_no, product_raw)] += 1
                product_norm_to_codes.setdefault(item_name_norm, set()).add(source_product_code)

                valid_lines.append(
                    SourceLine(
                        source_row_num=excel_row_num,
                        po_number=doc_no,
                        order_date=order_date,
                        supplier_name=supplier_name,
                        supplier_norm=supplier_norm,
                        document_status=status,
                        product_raw=product_raw,
                        product_name=product_name,
                        item_name_norm=item_name_norm,
                        source_product_code=source_product_code,
                        uom=uom,
                        unit_price=unit_price,
                        qty_ordered=qty_ordered,
                        line_amt=line_amt,
                        discount=discount,
                    )
                )
                continue

            if doc_no.lower() == "grand total":
                footer_rows += 1
                continue

            if doc_no == "" and all(normalize_spaces(v or "") == "" for v in row.values()):
                continue

            invalid_doc_rows.append((excel_row_num, row["Document no"]))

        if invalid_doc_rows:
            sample = ", ".join(f"row {r} doc={v!r}" for r, v in invalid_doc_rows[:10])
            die(f"Found non-data rows with invalid Document no values: {sample}")

    headers_map: Dict[str, SourceHeader] = {}
    for line in valid_lines:
        existing = headers_map.get(line.po_number)
        if existing is None:
            headers_map[line.po_number] = SourceHeader(
                po_number=line.po_number,
                order_date=line.order_date,
                supplier_name=line.supplier_name,
                supplier_norm=line.supplier_norm,
                document_status=line.document_status,
                source_line_count=1,
                source_total_amount=line.line_amt,
            )
            continue

        if existing.order_date != line.order_date:
            die(f"PO {line.po_number} has multiple Date Ordered values.")
        if existing.supplier_norm != line.supplier_norm:
            die(f"PO {line.po_number} has multiple Supplier values.")
        if existing.document_status != line.document_status:
            die(f"PO {line.po_number} has multiple Document Status values.")
        existing.source_line_count += 1
        existing.source_total_amount = (existing.source_total_amount + line.line_amt).quantize(Decimal("0.01"))

    source_headers = sorted(headers_map.values(), key=lambda h: (int(h.po_number), h.po_number))
    valid_lines_sorted = sorted(valid_lines, key=lambda l: l.source_row_num)

    item_first_row: Dict[str, SourceLine] = {}
    for line in valid_lines_sorted:
        item_first_row.setdefault(line.item_name_norm, line)

    distinct_items = sorted(
        (
            DistinctSourceItem(
                item_name_norm=k,
                source_row_num=v.source_row_num,
                product_name=v.product_name,
                source_product_code=v.source_product_code,
                uom=v.uom,
                representative_cost=v.unit_price,
            )
            for k, v in item_first_row.items()
        ),
        key=lambda x: (x.item_name_norm, x.source_row_num),
    )

    duplicate_doc_product_keys = sum(1 for cnt in product_keys.values() if cnt > 1)
    distinct_products = len({l.product_raw for l in valid_lines_sorted})
    distinct_item_name_norm = len({l.item_name_norm for l in valid_lines_sorted})
    distinct_pos = len(source_headers)

    anomalies = {
        k: ",".join(sorted(v))
        for k, v in sorted(product_norm_to_codes.items())
        if len(v) > 1
    }

    facts = {
        "total_rows": total_rows,
        "valid_line_rows": len(valid_lines_sorted),
        "footer_rows": footer_rows,
        "distinct_pos": distinct_pos,
        "distinct_products": distinct_products,
        "distinct_item_name_norm": distinct_item_name_norm,
        "duplicate_doc_product_keys": duplicate_doc_product_keys,
        "nonzero_discount_rows": nonzero_discount_rows,
        "status_counts": dict(status_counts),
    }

    if strict_facts:
        for key, expected in EXPECTED_FACTS.items():
            actual = facts.get(key)
            if actual != expected:
                die(f"Source fact mismatch for {key}: expected {expected!r}, got {actual!r}")

    return valid_lines_sorted, source_headers, distinct_items, facts, anomalies


def render_sql(
    source_csv: Path,
    lines: List[SourceLine],
    headers: List[SourceHeader],
    distinct_items: List[DistinctSourceItem],
    facts: Dict[str, int | Dict[str, int]],
    anomalies: Dict[str, str],
) -> str:
    generated_at = dt.datetime.now().replace(microsecond=0).isoformat()
    out: List[str] = []

    out.extend(
        [
            "-- Generated SQL: purchase orders upsert from CSV",
            f"-- Source file: {source_csv}",
            f"-- Generated at: {generated_at}",
            f"-- Total CSV rows: {facts['total_rows']}",
            f"-- Valid PO line rows: {facts['valid_line_rows']}",
            f"-- Footer rows ignored: {facts['footer_rows']}",
            f"-- Distinct POs: {facts['distinct_pos']}",
            f"-- Distinct source products: {facts['distinct_products']}",
            f"-- Distinct normalized item names: {facts['distinct_item_name_norm']}",
            f"-- Duplicate PO+Product keys: {facts['duplicate_doc_product_keys']}",
            f"-- Non-zero discount rows: {facts['nonzero_discount_rows']}",
            "-- Matching rules: supplier by normalized name (strict), item by normalized name (insert-missing only)",
            "-- Status mapping: Completed -> received",
            "-- Rerunnable behavior: upsert PO header + replace eligible PO lines",
            "",
            "START TRANSACTION;",
            "",
            "SET @inserted_items := 0;",
            "SET @inserted_po_rows := 0;",
            "SET @updated_po_rows := 0;",
            "SET @deleted_po_line_rows := 0;",
            "SET @inserted_po_line_rows := 0;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_source_lines;",
            "CREATE TEMPORARY TABLE tmp_po_source_lines (",
            "  source_row_num INT NOT NULL,",
            "  po_number VARCHAR(50) NOT NULL,",
            "  order_date DATE NOT NULL,",
            "  supplier_name VARCHAR(150) NOT NULL,",
            f"  supplier_norm VARCHAR(150) NOT NULL COLLATE {COLLATION},",
            "  document_status VARCHAR(50) NOT NULL,",
            "  product_raw VARCHAR(255) NOT NULL,",
            "  product_name VARCHAR(200) NOT NULL,",
            f"  item_name_norm VARCHAR(200) NOT NULL COLLATE {COLLATION},",
            "  source_product_code VARCHAR(50) DEFAULT NULL,",
            "  uom VARCHAR(50) DEFAULT NULL,",
            "  unit_price DECIMAL(12,4) NOT NULL,",
            "  qty_ordered DECIMAL(12,3) NOT NULL,",
            "  line_amt DECIMAL(12,2) NOT NULL,",
            "  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,",
            "  PRIMARY KEY (source_row_num),",
            "  KEY idx_tmp_po_source_lines_po_number (po_number),",
            "  KEY idx_tmp_po_source_lines_supplier_norm (supplier_norm),",
            "  KEY idx_tmp_po_source_lines_item_name_norm (item_name_norm)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_source_headers;",
            "CREATE TEMPORARY TABLE tmp_po_source_headers (",
            "  po_number VARCHAR(50) NOT NULL,",
            "  order_date DATE NOT NULL,",
            "  supplier_name VARCHAR(150) NOT NULL,",
            f"  supplier_norm VARCHAR(150) NOT NULL COLLATE {COLLATION},",
            "  document_status VARCHAR(50) NOT NULL,",
            "  source_line_count INT NOT NULL,",
            "  source_total_amount DECIMAL(12,2) NOT NULL,",
            "  PRIMARY KEY (po_number),",
            "  KEY idx_tmp_po_source_headers_supplier_norm (supplier_norm)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_source_distinct_items;",
            "CREATE TEMPORARY TABLE tmp_source_distinct_items (",
            f"  item_name_norm VARCHAR(200) NOT NULL COLLATE {COLLATION},",
            "  source_row_num INT NOT NULL,",
            "  product_name VARCHAR(200) NOT NULL,",
            "  source_product_code VARCHAR(50) DEFAULT NULL,",
            "  uom VARCHAR(50) DEFAULT NULL,",
            "  representative_cost DECIMAL(12,4) NOT NULL,",
            "  PRIMARY KEY (item_name_norm),",
            "  KEY idx_tmp_source_distinct_items_source_row_num (source_row_num)",
            f") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={COLLATION};",
            "",
        ]
    )

    line_rows = [
        [
            str(l.source_row_num),
            sql_quote(l.po_number),
            sql_quote(l.order_date),
            sql_quote(l.supplier_name),
            sql_quote(l.supplier_norm),
            sql_quote(l.document_status),
            sql_quote(l.product_raw),
            sql_quote(l.product_name),
            sql_quote(l.item_name_norm),
            sql_quote(l.source_product_code),
            sql_quote(l.uom if l.uom else None),
            sql_decimal(l.unit_price),
            sql_decimal(l.qty_ordered),
            sql_decimal(l.line_amt),
            sql_decimal(l.discount),
        ]
        for l in lines
    ]
    append_insert(
        out,
        "tmp_po_source_lines",
        [
            "source_row_num",
            "po_number",
            "order_date",
            "supplier_name",
            "supplier_norm",
            "document_status",
            "product_raw",
            "product_name",
            "item_name_norm",
            "source_product_code",
            "uom",
            "unit_price",
            "qty_ordered",
            "line_amt",
            "discount",
        ],
        line_rows,
    )
    out.append("")

    header_rows = [
        [
            sql_quote(h.po_number),
            sql_quote(h.order_date),
            sql_quote(h.supplier_name),
            sql_quote(h.supplier_norm),
            sql_quote(h.document_status),
            str(h.source_line_count),
            sql_decimal(h.source_total_amount),
        ]
        for h in headers
    ]
    append_insert(
        out,
        "tmp_po_source_headers",
        [
            "po_number",
            "order_date",
            "supplier_name",
            "supplier_norm",
            "document_status",
            "source_line_count",
            "source_total_amount",
        ],
        header_rows,
    )
    out.append("")

    distinct_rows = [
        [
            sql_quote(i.item_name_norm),
            str(i.source_row_num),
            sql_quote(i.product_name),
            sql_quote(i.source_product_code),
            sql_quote(i.uom if i.uom else None),
            sql_decimal(i.representative_cost),
        ]
        for i in distinct_items
    ]
    append_insert(
        out,
        "tmp_source_distinct_items",
        [
            "item_name_norm",
            "source_row_num",
            "product_name",
            "source_product_code",
            "uom",
            "representative_cost",
        ],
        distinct_rows,
    )
    out.append("")

    out.extend(
        [
            "DROP TEMPORARY TABLE IF EXISTS tmp_supplier_name_counts;",
            "CREATE TEMPORARY TABLE tmp_supplier_name_counts AS",
            "SELECT",
            f"  LOWER(TRIM(name)) COLLATE {COLLATION} AS supplier_norm,",
            "  COUNT(*) AS target_count",
            "FROM suppliers",
            f"GROUP BY LOWER(TRIM(name)) COLLATE {COLLATION};",
            "ALTER TABLE tmp_supplier_name_counts",
            "  ADD PRIMARY KEY (supplier_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_supplier_unique_names;",
            "CREATE TEMPORARY TABLE tmp_supplier_unique_names AS",
            "SELECT",
            "  snc.supplier_norm,",
            "  s.id AS supplier_id",
            "FROM tmp_supplier_name_counts snc",
            "JOIN suppliers s",
            f"  ON LOWER(TRIM(s.name)) COLLATE {COLLATION} = snc.supplier_norm",
            "WHERE snc.target_count = 1;",
            "ALTER TABLE tmp_supplier_unique_names",
            "  ADD PRIMARY KEY (supplier_norm),",
            "  ADD KEY idx_tmp_supplier_unique_names_supplier_id (supplier_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_supplier_resolution;",
            "CREATE TEMPORARY TABLE tmp_po_supplier_resolution AS",
            "SELECT",
            "  h.po_number,",
            "  h.supplier_name,",
            "  h.supplier_norm,",
            "  su.supplier_id,",
            "  CASE",
            "    WHEN snc.target_count IS NULL THEN 'missing'",
            "    WHEN snc.target_count > 1 THEN 'ambiguous'",
            "    ELSE 'resolved'",
            "  END AS supplier_resolution",
            "FROM tmp_po_source_headers h",
            "LEFT JOIN tmp_supplier_name_counts snc ON snc.supplier_norm = h.supplier_norm",
            "LEFT JOIN tmp_supplier_unique_names su ON su.supplier_norm = h.supplier_norm;",
            "ALTER TABLE tmp_po_supplier_resolution",
            "  ADD PRIMARY KEY (po_number),",
            "  ADD KEY idx_tmp_po_supplier_resolution_status (supplier_resolution),",
            "  ADD KEY idx_tmp_po_supplier_resolution_supplier_norm (supplier_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_item_name_counts;",
            "CREATE TEMPORARY TABLE tmp_target_item_name_counts AS",
            "SELECT",
            f"  LOWER(TRIM(name)) COLLATE {COLLATION} AS item_name_norm,",
            "  COUNT(*) AS target_count",
            "FROM inventory_items",
            f"GROUP BY LOWER(TRIM(name)) COLLATE {COLLATION};",
            "ALTER TABLE tmp_target_item_name_counts",
            "  ADD PRIMARY KEY (item_name_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_item_unique_names;",
            "CREATE TEMPORARY TABLE tmp_target_item_unique_names AS",
            "SELECT",
            "  c.item_name_norm,",
            "  i.id AS item_id",
            "FROM tmp_target_item_name_counts c",
            "JOIN inventory_items i",
            f"  ON LOWER(TRIM(i.name)) COLLATE {COLLATION} = c.item_name_norm",
            "WHERE c.target_count = 1;",
            "ALTER TABLE tmp_target_item_unique_names",
            "  ADD PRIMARY KEY (item_name_norm),",
            "  ADD KEY idx_tmp_target_item_unique_names_item_id (item_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_source_missing_items;",
            "CREATE TEMPORARY TABLE tmp_source_missing_items AS",
            "SELECT",
            "  s.item_name_norm,",
            "  s.source_row_num,",
            "  s.product_name,",
            "  s.source_product_code,",
            "  s.uom,",
            "  s.representative_cost",
            "FROM tmp_source_distinct_items s",
            "LEFT JOIN tmp_target_item_name_counts t ON t.item_name_norm = s.item_name_norm",
            "WHERE t.item_name_norm IS NULL;",
            "ALTER TABLE tmp_source_missing_items",
            "  ADD PRIMARY KEY (item_name_norm),",
            "  ADD KEY idx_tmp_source_missing_items_source_row_num (source_row_num);",
            "",
            "SET @next_item_num := (",
            "  SELECT COALESCE(MAX(CAST(SUBSTRING(item_code, 6) AS UNSIGNED)), 0) + 1",
            "  FROM inventory_items",
            "  WHERE item_code REGEXP '^ITEM-[0-9]+$'",
            ");",
            "SET @item_row_num := 0;",
            "INSERT INTO inventory_items (",
            "  item_code,",
            "  name,",
            "  unit_of_measure,",
            "  cost_per_unit,",
            "  status,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            "  CONCAT('ITEM-', LPAD(CAST(@next_item_num + (@item_row_num := @item_row_num + 1) - 1 AS CHAR), 3, '0')) AS item_code,",
            "  s.product_name AS name,",
            "  NULLIF(s.uom, '') AS unit_of_measure,",
            "  s.representative_cost AS cost_per_unit,",
            "  'active' AS status,",
            "  NOW() AS created_at,",
            "  NOW() AS updated_at",
            "FROM tmp_source_missing_items s",
            "ORDER BY s.item_name_norm;",
            "SET @inserted_items := ROW_COUNT();",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_item_name_counts_final;",
            "CREATE TEMPORARY TABLE tmp_target_item_name_counts_final AS",
            "SELECT",
            f"  LOWER(TRIM(name)) COLLATE {COLLATION} AS item_name_norm,",
            "  COUNT(*) AS target_count",
            "FROM inventory_items",
            f"GROUP BY LOWER(TRIM(name)) COLLATE {COLLATION};",
            "ALTER TABLE tmp_target_item_name_counts_final",
            "  ADD PRIMARY KEY (item_name_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_item_unique_names_final;",
            "CREATE TEMPORARY TABLE tmp_target_item_unique_names_final AS",
            "SELECT",
            "  c.item_name_norm,",
            "  i.id AS item_id",
            "FROM tmp_target_item_name_counts_final c",
            "JOIN inventory_items i",
            f"  ON LOWER(TRIM(i.name)) COLLATE {COLLATION} = c.item_name_norm",
            "WHERE c.target_count = 1;",
            "ALTER TABLE tmp_target_item_unique_names_final",
            "  ADD PRIMARY KEY (item_name_norm),",
            "  ADD KEY idx_tmp_target_item_unique_names_final_item_id (item_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_line_resolution;",
            "CREATE TEMPORARY TABLE tmp_po_line_resolution AS",
            "SELECT",
            "  l.source_row_num,",
            "  l.po_number,",
            "  l.item_name_norm,",
            "  iu.item_id,",
            "  CASE",
            "    WHEN iu.item_id IS NOT NULL THEN 'resolved'",
            "    WHEN ic.target_count > 1 THEN 'ambiguous'",
            "    ELSE 'missing'",
            "  END AS item_resolution",
            "FROM tmp_po_source_lines l",
            "LEFT JOIN tmp_target_item_name_counts_final ic ON ic.item_name_norm = l.item_name_norm",
            "LEFT JOIN tmp_target_item_unique_names_final iu ON iu.item_name_norm = l.item_name_norm;",
            "ALTER TABLE tmp_po_line_resolution",
            "  ADD PRIMARY KEY (source_row_num),",
            "  ADD KEY idx_tmp_po_line_resolution_po_number (po_number),",
            "  ADD KEY idx_tmp_po_line_resolution_item_resolution (item_resolution);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_item_unresolved;",
            "CREATE TEMPORARY TABLE tmp_po_item_unresolved AS",
            "SELECT",
            "  lr.po_number,",
            "  COUNT(*) AS unresolved_line_count",
            "FROM tmp_po_line_resolution lr",
            "WHERE lr.item_resolution <> 'resolved'",
            "GROUP BY lr.po_number;",
            "ALTER TABLE tmp_po_item_unresolved",
            "  ADD PRIMARY KEY (po_number);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_eligible;",
            "CREATE TEMPORARY TABLE tmp_po_eligible AS",
            "SELECT h.po_number",
            "FROM tmp_po_source_headers h",
            "JOIN tmp_po_supplier_resolution sr",
            "  ON sr.po_number = h.po_number",
            "  AND sr.supplier_resolution = 'resolved'",
            "LEFT JOIN tmp_po_item_unresolved iu ON iu.po_number = h.po_number",
            "WHERE iu.po_number IS NULL;",
            "ALTER TABLE tmp_po_eligible",
            "  ADD PRIMARY KEY (po_number);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_existing_po_before;",
            "CREATE TEMPORARY TABLE tmp_existing_po_before AS",
            "SELECT p.po_number",
            "FROM purchase_orders p",
            f"JOIN tmp_po_eligible e ON e.po_number COLLATE {COLLATION} = p.po_number COLLATE {COLLATION};",
            "ALTER TABLE tmp_existing_po_before",
            "  ADD PRIMARY KEY (po_number);",
            "",
            "INSERT INTO purchase_orders (",
            "  po_number,",
            "  supplier_id,",
            "  order_date,",
            "  status,",
            "  total_amount,",
            "  received_date,",
            "  created_by,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            "  h.po_number,",
            "  sr.supplier_id,",
            "  h.order_date,",
            "  'received' AS status,",
            "  h.source_total_amount AS total_amount,",
            "  h.order_date AS received_date,",
            "  NULL AS created_by,",
            "  NOW() AS created_at,",
            "  NOW() AS updated_at",
            "FROM tmp_po_source_headers h",
            "JOIN tmp_po_eligible e ON e.po_number = h.po_number",
            "JOIN tmp_po_supplier_resolution sr ON sr.po_number = h.po_number",
            "ON DUPLICATE KEY UPDATE",
            "  supplier_id = VALUES(supplier_id),",
            "  order_date = VALUES(order_date),",
            "  status = 'received',",
            "  received_date = VALUES(received_date),",
            "  total_amount = VALUES(total_amount),",
            "  updated_at = NOW();",
            "",
            "SET @eligible_po_rows := (SELECT COUNT(*) FROM tmp_po_eligible);",
            "SET @updated_po_rows := (SELECT COUNT(*) FROM tmp_existing_po_before);",
            "SET @inserted_po_rows := @eligible_po_rows - @updated_po_rows;",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_po_target_ids;",
            "CREATE TEMPORARY TABLE tmp_po_target_ids AS",
            f"SELECT p.id, p.po_number COLLATE {COLLATION} AS po_number",
            "FROM purchase_orders p",
            f"JOIN tmp_po_eligible e ON e.po_number COLLATE {COLLATION} = p.po_number COLLATE {COLLATION};",
            "ALTER TABLE tmp_po_target_ids",
            "  ADD PRIMARY KEY (id),",
            "  ADD UNIQUE KEY uq_tmp_po_target_ids_po_number (po_number);",
            "",
            "DELETE poi",
            "FROM purchase_order_items poi",
            "JOIN tmp_po_target_ids t ON t.id = poi.purchase_order_id;",
            "SET @deleted_po_line_rows := ROW_COUNT();",
            "",
            "INSERT INTO purchase_order_items (",
            "  purchase_order_id,",
            "  item_id,",
            "  quantity,",
            "  unit_price,",
            "  total_price,",
            "  received_quantity,",
            "  created_at",
            ")",
            "SELECT",
            "  t.id AS purchase_order_id,",
            "  lr.item_id,",
            "  l.qty_ordered AS quantity,",
            "  ROUND(l.unit_price, 2) AS unit_price,",
            "  ROUND(l.line_amt, 2) AS total_price,",
            "  l.qty_ordered AS received_quantity,",
            "  NOW() AS created_at",
            "FROM tmp_po_source_lines l",
            "JOIN tmp_po_line_resolution lr",
            "  ON lr.source_row_num = l.source_row_num",
            "  AND lr.item_resolution = 'resolved'",
            f"JOIN tmp_po_target_ids t ON t.po_number COLLATE {COLLATION} = l.po_number COLLATE {COLLATION}",
            "ORDER BY l.source_row_num;",
            "SET @inserted_po_line_rows := ROW_COUNT();",
            "",
            "-- Summary",
            "SELECT",
            "  (SELECT COUNT(*) FROM tmp_po_source_lines) AS source_line_rows,",
            "  (SELECT COUNT(*) FROM tmp_po_source_headers) AS source_po_count,",
            "  (SELECT COUNT(*) FROM tmp_po_eligible) AS eligible_po_count,",
            "  (SELECT COUNT(*) FROM tmp_po_supplier_resolution WHERE supplier_resolution = 'missing') AS skipped_po_supplier_missing,",
            "  (SELECT COUNT(*) FROM tmp_po_supplier_resolution WHERE supplier_resolution = 'ambiguous') AS skipped_po_supplier_ambiguous,",
            "  (SELECT COUNT(*) FROM tmp_po_item_unresolved) AS skipped_po_item_unresolved,",
            "  @inserted_items AS inserted_items,",
            "  @inserted_po_rows AS inserted_purchase_orders,",
            "  @updated_po_rows AS updated_purchase_orders,",
            "  @deleted_po_line_rows AS deleted_existing_po_lines,",
            "  @inserted_po_line_rows AS inserted_purchase_order_lines;",
            "",
            "-- Skipped POs by supplier (missing/ambiguous)",
            "SELECT",
            "  po_number,",
            "  supplier_name,",
            "  supplier_norm,",
            "  supplier_resolution",
            "FROM tmp_po_supplier_resolution",
            "WHERE supplier_resolution <> 'resolved'",
            "ORDER BY supplier_resolution, po_number;",
            "",
            "-- Skipped POs due to unresolved/ambiguous items",
            "SELECT",
            "  u.po_number,",
            "  u.unresolved_line_count",
            "FROM tmp_po_item_unresolved u",
            "ORDER BY u.po_number;",
            "",
            "-- Unresolved item details",
            "SELECT",
            "  lr.po_number,",
            "  l.item_name_norm,",
            "  MIN(l.product_name) AS product_name,",
            "  lr.item_resolution,",
            "  COUNT(*) AS affected_lines,",
            "  GROUP_CONCAT(l.source_row_num ORDER BY l.source_row_num) AS source_row_nums",
            "FROM tmp_po_line_resolution lr",
            "JOIN tmp_po_source_lines l ON l.source_row_num = lr.source_row_num",
            "WHERE lr.item_resolution <> 'resolved'",
            "GROUP BY lr.po_number, l.item_name_norm, lr.item_resolution",
            "ORDER BY lr.po_number, l.item_name_norm;",
            "",
            "-- Inserted missing source item candidates (name-only matching mode)",
            "SELECT",
            "  s.item_name_norm,",
            "  s.product_name,",
            "  s.source_product_code,",
            "  s.uom,",
            "  s.representative_cost",
            "FROM tmp_source_missing_items s",
            "ORDER BY s.item_name_norm;",
            "",
            "-- Source item-name to code anomalies",
            "DROP TEMPORARY TABLE IF EXISTS tmp_source_item_code_anomalies;",
            "CREATE TEMPORARY TABLE tmp_source_item_code_anomalies AS",
            "SELECT",
            "  item_name_norm,",
            "  COUNT(DISTINCT source_product_code) AS source_code_count,",
            "  GROUP_CONCAT(DISTINCT source_product_code ORDER BY source_product_code) AS source_codes",
            "FROM tmp_po_source_lines",
            "GROUP BY item_name_norm",
            "HAVING COUNT(DISTINCT source_product_code) > 1;",
            "",
            "SELECT * FROM tmp_source_item_code_anomalies ORDER BY item_name_norm;",
            "",
        ]
    )

    if anomalies:
        out.append("-- Generator-detected source anomalies (item_name_norm with multiple source codes):")
        for item_name_norm, codes in anomalies.items():
            out.append(f"--   {item_name_norm} => {codes}")
        out.append("")

    out.extend(
        [
            "-- ROLLBACK; -- Uncomment for dry-run safety.",
            "COMMIT;",
            "",
        ]
    )

    return "\n".join(out)


def parse_args() -> argparse.Namespace:
    default_source = Path("docs/csv/purchase_order_detail2026_03_01_19_59_16.csv")
    default_output = Path(
        "database/seeders/sql/purchase_orders_upsert_from_purchase_order_detail_2026_03_01_19_59_16.sql"
    )
    parser = argparse.ArgumentParser(description="Generate deterministic purchase-order upsert SQL from CSV.")
    parser.add_argument("--source", type=Path, default=default_source, help="Path to source CSV file")
    parser.add_argument("--output", type=Path, default=default_output, help="Path to write generated SQL")
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

    lines, headers, distinct_items, facts, anomalies = load_source(source, strict_facts=not args.no_strict_facts)
    sql = render_sql(source, lines, headers, distinct_items, facts, anomalies)

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(sql, encoding="utf-8")

    print(f"Generated SQL: {output}")
    print(f"Source line rows: {len(lines)}")
    print(f"Distinct POs: {len(headers)}")
    print(f"Distinct item names: {len(distinct_items)}")


if __name__ == "__main__":
    main()
