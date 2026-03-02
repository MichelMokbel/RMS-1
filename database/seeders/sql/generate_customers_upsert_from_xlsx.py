#!/usr/bin/env python3
"""
Generate a deterministic MySQL upsert SQL script from a strict OOXML customers XLSX.

Locked behavior:
- Match by normalized name only: LOWER(TRIM(name))
- Skip ambiguous duplicate-name cases (source or target duplicates)
- Preserve existing DB values when source cells are blank
"""

from __future__ import annotations

import argparse
import datetime as dt
import decimal
import os
import re
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple
from xml.etree import ElementTree as ET


REQUIRED_HEADERS = (
    "Name",
    "Phone Number",
    "Email",
    "Country",
    "Credit Status",
    "Credit Limit",
    "Active",
)

OOXML_MAIN_NAMESPACES = (
    "http://purl.oclc.org/ooxml/spreadsheetml/main",  # strict OOXML
    "http://schemas.openxmlformats.org/spreadsheetml/2006/main",  # transitional
)
PKG_REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
OFFICE_REL_NS = "http://purl.oclc.org/ooxml/officeDocument/relationships"


@dataclass(frozen=True)
class SourceRow:
    source_row_num: int
    name: str
    name_norm: str
    phone: Optional[str]
    email: Optional[str]
    country: Optional[str]
    credit_status: Optional[str]
    credit_limit: decimal.Decimal
    credit_limit_is_blank: int
    is_active: int
    is_active_is_blank: int
    customer_type: str = "retail"
    credit_terms_days: int = 0


def die(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def _clean_text(value: Optional[str]) -> str:
    if value is None:
        return ""
    return str(value).strip()


def normalize_name(name: str) -> str:
    return _clean_text(name).lower()


def optional_text(value: Optional[str]) -> Optional[str]:
    text = _clean_text(value)
    return text if text else None


def parse_credit_limit(value: Optional[str]) -> Tuple[decimal.Decimal, int]:
    text = _clean_text(value)
    if text == "":
        return decimal.Decimal("0.000"), 1

    normalized = text.replace(",", "")
    try:
        parsed = decimal.Decimal(normalized)
    except decimal.InvalidOperation:
        return decimal.Decimal("0.000"), 1

    quantized = parsed.quantize(decimal.Decimal("0.001"), rounding=decimal.ROUND_HALF_UP)
    return quantized, 0


def parse_is_active(value: Optional[str]) -> Tuple[int, int]:
    text = _clean_text(value)
    if text == "":
        return 1, 1

    upper = text.upper()
    if upper in {"Y", "YES", "1", "TRUE", "T"}:
        return 1, 0
    if upper in {"N", "NO", "0", "FALSE", "F"}:
        return 0, 0
    return 1, 0


def col_index_from_ref(cell_ref: str) -> int:
    match = re.match(r"([A-Z]+)", cell_ref)
    if not match:
        return 0
    letters = match.group(1)
    idx = 0
    for ch in letters:
        idx = (idx * 26) + (ord(ch) - ord("A") + 1)
    return idx - 1


def find_main_namespace(root: ET.Element) -> str:
    for ns in OOXML_MAIN_NAMESPACES:
        if root.tag.startswith("{%s}" % ns):
            return ns
    # Fallback: extract namespace from tag like {ns}workbook
    if root.tag.startswith("{") and "}" in root.tag:
        return root.tag[1 : root.tag.index("}")]
    die("Unable to determine workbook XML namespace.")
    return ""


def read_shared_strings(zf: zipfile.ZipFile) -> List[str]:
    if "xl/sharedStrings.xml" not in zf.namelist():
        return []

    root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
    ns = find_main_namespace(root)
    q = {"s": ns}
    values: List[str] = []
    for si in root.findall("s:si", q):
        texts = [t.text or "" for t in si.findall(".//s:t", q)]
        values.append("".join(texts))
    return values


def read_first_sheet_xml_path(zf: zipfile.ZipFile) -> str:
    workbook = ET.fromstring(zf.read("xl/workbook.xml"))
    main_ns = find_main_namespace(workbook)
    q = {"s": main_ns, "r": OFFICE_REL_NS}

    sheets = workbook.findall("s:sheets/s:sheet", q)
    if not sheets:
        die("Workbook has no sheets.")

    first_sheet = sheets[0]
    rel_id = first_sheet.attrib.get("{%s}id" % OFFICE_REL_NS) or first_sheet.attrib.get("r:id")
    if not rel_id:
        die("Unable to locate sheet relationship id for first sheet.")

    rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
    rq = {"r": PKG_REL_NS}
    rid_to_target: Dict[str, str] = {}
    for rel in rels.findall("r:Relationship", rq):
        rid = rel.attrib.get("Id")
        target = rel.attrib.get("Target")
        if rid and target:
            rid_to_target[rid] = target

    target = rid_to_target.get(rel_id)
    if not target:
        die(f"Could not resolve worksheet target from relationship id {rel_id}.")
    return f"xl/{target.lstrip('/')}"


def cell_value(cell: ET.Element, namespaces: Dict[str, str], shared_strings: List[str]) -> str:
    cell_type = cell.attrib.get("t")
    if cell_type == "inlineStr":
        texts = [t.text or "" for t in cell.findall("s:is//s:t", namespaces)]
        return "".join(texts)

    value_node = cell.find("s:v", namespaces)
    if value_node is None:
        return ""
    value_text = value_node.text or ""

    if cell_type == "s":
        if value_text.isdigit():
            idx = int(value_text)
            if 0 <= idx < len(shared_strings):
                return shared_strings[idx]
        return value_text

    return value_text


def read_rows_from_xlsx(xlsx_path: Path) -> List[Tuple[int, List[str]]]:
    with zipfile.ZipFile(xlsx_path) as zf:
        shared_strings = read_shared_strings(zf)
        sheet_xml_path = read_first_sheet_xml_path(zf)
        sheet = ET.fromstring(zf.read(sheet_xml_path))
        main_ns = find_main_namespace(sheet)
        q = {"s": main_ns}

        rows: List[Tuple[int, List[str]]] = []
        sheet_data_rows = sheet.findall(".//s:sheetData/s:row", q)
        max_col = 0
        sparse_rows: List[Tuple[int, Dict[int, str]]] = []

        for row in sheet_data_rows:
            row_num = int(row.attrib.get("r", "0")) or 0
            sparse: Dict[int, str] = {}
            for cell in row.findall("s:c", q):
                ref = cell.attrib.get("r", "A1")
                idx = col_index_from_ref(ref)
                sparse[idx] = cell_value(cell, q, shared_strings)
                max_col = max(max_col, idx)
            sparse_rows.append((row_num, sparse))

        for row_num, sparse in sparse_rows:
            dense = [""] * (max_col + 1)
            for idx, val in sparse.items():
                dense[idx] = val
            rows.append((row_num, dense))

        return rows


def map_rows(xlsx_path: Path) -> Tuple[List[SourceRow], int]:
    raw_rows = read_rows_from_xlsx(xlsx_path)
    if not raw_rows:
        die("XLSX appears empty.")

    header = [h.strip() for h in raw_rows[0][1]]
    header_index = {name: idx for idx, name in enumerate(header)}

    missing = [h for h in REQUIRED_HEADERS if h not in header_index]
    if missing:
        die(f"Missing required headers: {', '.join(missing)}")

    mapped: List[SourceRow] = []
    skipped_empty_name = 0
    for fallback_idx, (sheet_row_num, values) in enumerate(raw_rows[1:], start=2):
        source_row_num = sheet_row_num or fallback_idx
        name = _clean_text(values[header_index["Name"]] if header_index["Name"] < len(values) else "")
        if name == "":
            skipped_empty_name += 1
            continue

        phone = optional_text(values[header_index["Phone Number"]] if header_index["Phone Number"] < len(values) else "")
        email = optional_text(values[header_index["Email"]] if header_index["Email"] < len(values) else "")
        country = optional_text(values[header_index["Country"]] if header_index["Country"] < len(values) else "")
        credit_status = optional_text(values[header_index["Credit Status"]] if header_index["Credit Status"] < len(values) else "")
        credit_limit, credit_limit_is_blank = parse_credit_limit(
            values[header_index["Credit Limit"]] if header_index["Credit Limit"] < len(values) else ""
        )
        is_active, is_active_is_blank = parse_is_active(
            values[header_index["Active"]] if header_index["Active"] < len(values) else ""
        )

        mapped.append(
            SourceRow(
                source_row_num=source_row_num,
                name=name,
                name_norm=normalize_name(name),
                phone=phone,
                email=email,
                country=country,
                credit_status=credit_status,
                credit_limit=credit_limit,
                credit_limit_is_blank=credit_limit_is_blank,
                is_active=is_active,
                is_active_is_blank=is_active_is_blank,
            )
        )

    mapped.sort(key=lambda r: r.source_row_num)
    return mapped, skipped_empty_name


def sql_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "''")


def sql_literal(value: Optional[object]) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, decimal.Decimal):
        return f"{value:.3f}"
    if isinstance(value, (int, float)):
        return str(value)
    return f"'{sql_escape(str(value))}'"


def chunked(items: Iterable[SourceRow], size: int) -> Iterable[List[SourceRow]]:
    batch: List[SourceRow] = []
    for item in items:
        batch.append(item)
        if len(batch) >= size:
            yield batch
            batch = []
    if batch:
        yield batch


def render_insert_batches(rows: List[SourceRow], chunk_size: int = 250) -> List[str]:
    statements: List[str] = []
    cols = (
        "source_row_num",
        "name",
        "name_norm",
        "phone",
        "email",
        "country",
        "credit_status",
        "credit_limit",
        "credit_limit_is_blank",
        "is_active",
        "is_active_is_blank",
        "customer_type",
        "credit_terms_days",
    )

    for batch in chunked(rows, chunk_size):
        lines = [
            "INSERT INTO tmp_customers_source ("
            + ", ".join(cols)
            + ") VALUES"
        ]
        values_sql = []
        for r in batch:
            row_sql = "(" + ", ".join(
                [
                    sql_literal(r.source_row_num),
                    sql_literal(r.name),
                    sql_literal(r.name_norm),
                    sql_literal(r.phone),
                    sql_literal(r.email),
                    sql_literal(r.country),
                    sql_literal(r.credit_status),
                    sql_literal(r.credit_limit),
                    sql_literal(r.credit_limit_is_blank),
                    sql_literal(r.is_active),
                    sql_literal(r.is_active_is_blank),
                    sql_literal(r.customer_type),
                    sql_literal(r.credit_terms_days),
                ]
            ) + ")"
            values_sql.append(row_sql)
        lines.append(",\n".join(values_sql) + ";")
        statements.append("\n".join(lines))
    return statements


def render_sql(source_path: Path, rows: List[SourceRow], skipped_empty_name: int) -> str:
    now = dt.datetime.now().isoformat(timespec="seconds")
    header = [
        "-- Generated SQL: customers upsert from strict OOXML XLSX",
        f"-- Source file: {source_path}",
        f"-- Generated at: {now}",
        f"-- Source rows loaded (non-empty name): {len(rows)}",
        f"-- Source rows skipped (empty name): {skipped_empty_name}",
        "-- Matching key: LOWER(TRIM(name))",
        "-- Rules: skip ambiguous duplicates; skip blank updates; rerunnable transaction",
        "",
        "START TRANSACTION;",
        "",
        "DROP TEMPORARY TABLE IF EXISTS tmp_customers_source;",
        "CREATE TEMPORARY TABLE tmp_customers_source (",
        "  source_row_num INT NOT NULL,",
        "  name VARCHAR(255) NOT NULL,",
        "  name_norm VARCHAR(255) NOT NULL,",
        "  phone VARCHAR(50) DEFAULT NULL,",
        "  email VARCHAR(255) DEFAULT NULL,",
        "  country VARCHAR(100) DEFAULT NULL,",
        "  credit_status VARCHAR(100) DEFAULT NULL,",
        "  credit_limit DECIMAL(12,3) NOT NULL DEFAULT 0.000,",
        "  credit_limit_is_blank TINYINT(1) NOT NULL DEFAULT 0,",
        "  is_active TINYINT(1) NOT NULL DEFAULT 1,",
        "  is_active_is_blank TINYINT(1) NOT NULL DEFAULT 0,",
        "  customer_type ENUM('retail','corporate','subscription') NOT NULL DEFAULT 'retail',",
        "  credit_terms_days INT NOT NULL DEFAULT 0,",
        "  PRIMARY KEY (source_row_num),",
        "  KEY idx_tmp_customers_source_name_norm (name_norm)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
    ]

    body: List[str] = []
    body.extend(render_insert_batches(rows))
    body.extend(
        [
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_source_unique_names;",
            "CREATE TEMPORARY TABLE tmp_source_unique_names AS",
            "SELECT",
            "  name_norm,",
            "  MIN(source_row_num) AS source_row_num",
            "FROM tmp_customers_source",
            "GROUP BY name_norm",
            "HAVING COUNT(*) = 1;",
            "ALTER TABLE tmp_source_unique_names",
            "  ADD PRIMARY KEY (name_norm),",
            "  ADD KEY idx_tmp_source_unique_row (source_row_num);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_source_ambiguous_names;",
            "CREATE TEMPORARY TABLE tmp_source_ambiguous_names AS",
            "SELECT",
            "  name_norm,",
            "  COUNT(*) AS source_count",
            "FROM tmp_customers_source",
            "GROUP BY name_norm",
            "HAVING COUNT(*) > 1;",
            "ALTER TABLE tmp_source_ambiguous_names",
            "  ADD PRIMARY KEY (name_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_name_counts;",
            "CREATE TEMPORARY TABLE tmp_target_name_counts AS",
            "SELECT",
            "  LOWER(TRIM(name)) COLLATE utf8mb4_unicode_ci AS name_norm,",
            "  COUNT(*) AS target_count",
            "FROM customers",
            "GROUP BY LOWER(TRIM(name)) COLLATE utf8mb4_unicode_ci;",
            "ALTER TABLE tmp_target_name_counts",
            "  ADD PRIMARY KEY (name_norm);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_unique_names;",
            "CREATE TEMPORARY TABLE tmp_target_unique_names AS",
            "SELECT",
            "  tnc.name_norm,",
            "  c.id AS customer_id",
            "FROM tmp_target_name_counts tnc",
            "JOIN customers c",
            "  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = tnc.name_norm",
            "WHERE tnc.target_count = 1;",
            "ALTER TABLE tmp_target_unique_names",
            "  ADD PRIMARY KEY (name_norm),",
            "  ADD KEY idx_tmp_target_unique_customer_id (customer_id);",
            "",
            "DROP TEMPORARY TABLE IF EXISTS tmp_target_ambiguous_names;",
            "CREATE TEMPORARY TABLE tmp_target_ambiguous_names AS",
            "SELECT",
            "  tnc.name_norm,",
            "  tnc.target_count,",
            "  GROUP_CONCAT(c.id ORDER BY c.id) AS customer_ids",
            "FROM tmp_target_name_counts tnc",
            "JOIN customers c",
            "  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = tnc.name_norm",
            "WHERE tnc.target_count > 1",
            "GROUP BY tnc.name_norm, tnc.target_count;",
            "ALTER TABLE tmp_target_ambiguous_names",
            "  ADD PRIMARY KEY (name_norm);",
            "",
            "-- Update existing rows only where name is unique in source and target.",
            "UPDATE customers c",
            "JOIN tmp_target_unique_names tu ON tu.customer_id = c.id",
            "JOIN tmp_source_unique_names su ON su.name_norm = tu.name_norm",
            "JOIN tmp_customers_source s ON s.source_row_num = su.source_row_num",
            "SET",
            "  c.name = s.name,",
            "  c.customer_type = s.customer_type,",
            "  c.phone = COALESCE(NULLIF(s.phone, ''), c.phone),",
            "  c.email = COALESCE(NULLIF(s.email, ''), c.email),",
            "  c.country = COALESCE(NULLIF(s.country, ''), c.country),",
            "  c.credit_status = COALESCE(NULLIF(s.credit_status, ''), c.credit_status),",
            "  c.credit_limit = CASE",
            "    WHEN s.credit_limit_is_blank = 1 THEN c.credit_limit",
            "    ELSE s.credit_limit",
            "  END,",
            "  c.credit_terms_days = s.credit_terms_days,",
            "  c.is_active = CASE",
            "    WHEN s.is_active_is_blank = 1 THEN c.is_active",
            "    ELSE s.is_active",
            "  END,",
            "  c.updated_at = NOW();",
            "SET @updated_rows := ROW_COUNT();",
            "",
            "-- Insert new rows only where source name is unique and absent in target.",
            "INSERT INTO customers (",
            "  customer_code,",
            "  name,",
            "  customer_type,",
            "  phone,",
            "  email,",
            "  country,",
            "  credit_status,",
            "  credit_limit,",
            "  credit_terms_days,",
            "  is_active,",
            "  created_at,",
            "  updated_at",
            ")",
            "SELECT",
            "  NULL AS customer_code,",
            "  s.name,",
            "  s.customer_type,",
            "  s.phone,",
            "  s.email,",
            "  s.country,",
            "  s.credit_status,",
            "  COALESCE(s.credit_limit, 0.000) AS credit_limit,",
            "  s.credit_terms_days,",
            "  CASE",
            "    WHEN s.is_active_is_blank = 1 THEN 1",
            "    ELSE s.is_active",
            "  END AS is_active,",
            "  NOW() AS created_at,",
            "  NOW() AS updated_at",
            "FROM tmp_source_unique_names su",
            "JOIN tmp_customers_source s ON s.source_row_num = su.source_row_num",
            "LEFT JOIN tmp_target_unique_names tu ON tu.name_norm = su.name_norm",
            "LEFT JOIN tmp_target_ambiguous_names ta ON ta.name_norm = su.name_norm",
            "WHERE tu.name_norm IS NULL",
            "  AND ta.name_norm IS NULL;",
            "SET @inserted_rows := ROW_COUNT();",
            "",
            "-- Ambiguity + execution summary",
            "SELECT",
            "  (SELECT COUNT(*) FROM tmp_customers_source) AS source_rows_loaded,",
            "  (SELECT COUNT(*) FROM tmp_source_unique_names) AS source_unique_name_count,",
            "  (SELECT COUNT(*) FROM tmp_source_ambiguous_names) AS source_ambiguous_name_count,",
            "  (SELECT COUNT(*) FROM tmp_target_ambiguous_names) AS target_ambiguous_name_count,",
            "  (SELECT COUNT(*)",
            "     FROM tmp_customers_source s",
            "     JOIN tmp_source_ambiguous_names a ON a.name_norm = s.name_norm) AS skipped_source_ambiguous_rows,",
            "  (SELECT COUNT(*)",
            "     FROM tmp_source_unique_names su",
            "     JOIN tmp_target_ambiguous_names ta ON ta.name_norm = su.name_norm) AS skipped_target_ambiguous_rows,",
            "  @updated_rows AS updated_rows,",
            "  @inserted_rows AS inserted_rows;",
            "",
            "SELECT",
            "  a.name_norm,",
            "  a.source_count,",
            "  GROUP_CONCAT(s.source_row_num ORDER BY s.source_row_num) AS source_row_nums",
            "FROM tmp_source_ambiguous_names a",
            "JOIN tmp_customers_source s ON s.name_norm = a.name_norm",
            "GROUP BY a.name_norm, a.source_count",
            "ORDER BY a.name_norm;",
            "",
            "SELECT",
            "  name_norm,",
            "  target_count,",
            "  customer_ids",
            "FROM tmp_target_ambiguous_names",
            "ORDER BY name_norm;",
            "",
            "-- ROLLBACK; -- Uncomment for first-run dry test.",
            "COMMIT;",
            "",
        ]
    )
    return "\n".join(header + body)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate customers upsert SQL from a strict OOXML XLSX file."
    )
    parser.add_argument(
        "--input",
        default="docs/csv/Customers (2).xlsx",
        help="Path to source XLSX file.",
    )
    parser.add_argument(
        "--output",
        default="database/seeders/sql/customers_upsert_from_customers_2_xlsx.sql",
        help="Path to output SQL file.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()

    if not input_path.exists():
        die(f"Input XLSX not found: {input_path}")
    if input_path.suffix.lower() != ".xlsx":
        die("Input file must be an .xlsx workbook.")

    rows, skipped_empty_name = map_rows(input_path)
    if not rows:
        die("No usable rows found after parsing and validation.")

    sql = render_sql(input_path, rows, skipped_empty_name)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(sql, encoding="utf-8")

    print(f"Source rows loaded: {len(rows)}")
    print(f"Source rows skipped (empty name): {skipped_empty_name}")
    print(f"SQL generated: {output_path}")


if __name__ == "__main__":
    main()
