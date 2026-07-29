#!/usr/bin/env python3
"""Read a mahadasha-lord worksheet from an Excel file and print JSON to stdout."""

from __future__ import annotations

import json
import re
import sys


def cell_to_text(value) -> str:
    if value is None:
        return ""
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return str(value).strip()


def normalize_lord_key(value: str) -> str:
    return re.sub(r"[^A-Za-z]", "", value or "").upper()


def resolve_sheet_name_from_names(
    sheet_names,
    dasha_lord: str,
    first_cell_for,
) -> str | None:
    key = normalize_lord_key(dasha_lord)
    if not key:
        return None

    for name in sheet_names:
        title = normalize_lord_key(name)
        if title == key or title.startswith(key):
            return name

    for name in sheet_names:
        header = first_cell_for(name)
        if not header:
            continue
        header_text = str(header).upper()
        if key in header_text and "DASA" in header_text:
            return name

    return None


def resolve_sheet_name(workbook, dasha_lord: str) -> str | None:
    return resolve_sheet_name_from_names(
        workbook.sheetnames,
        dasha_lord,
        lambda name: next(workbook[name].iter_rows(min_row=1, max_row=1, values_only=True), (None,))[0],
    )


def read_grid(path: str, dasha_lord: str) -> list[list[str]] | None:
    lower = path.lower()
    if lower.endswith(".xlsx"):
        import openpyxl

        workbook = openpyxl.load_workbook(path, read_only=True, data_only=True)
        sheet_name = resolve_sheet_name(workbook, dasha_lord)
        if not sheet_name:
            workbook.close()
            return None

        sheet = workbook[sheet_name]
        grid: list[list[str]] = []
        for row_idx, row in enumerate(sheet.iter_rows(values_only=True)):
            if row_idx >= 320:
                break
            line = [cell_to_text(cell) for cell in row]
            while line and line[-1] == "":
                line.pop()
            if any(cell != "" for cell in line):
                grid.append(line)
        workbook.close()
        return grid

    if lower.endswith(".xls"):
        import xlrd

        book = xlrd.open_workbook(path)
        sheet_name = resolve_sheet_name_from_names(
            book.sheet_names(),
            dasha_lord,
            lambda name: book.sheet_by_name(name).cell_value(0, 0) if book.sheet_by_name(name).nrows else None,
        )
        if not sheet_name:
            return None

        sheet = book.sheet_by_name(sheet_name)
        grid = []
        max_row = min(sheet.nrows, 320)
        for row_idx in range(max_row):
            line = [cell_to_text(sheet.cell_value(row_idx, col_idx)) for col_idx in range(sheet.ncols)]
            while line and line[-1] == "":
                line.pop()
            if any(cell != "" for cell in line):
                grid.append(line)
        return grid

    raise ValueError("Unsupported Excel extension")


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: read_excel_grid.py <path> <dashaLord>", file=sys.stderr)
        return 1

    path = sys.argv[1]
    dasha_lord = sys.argv[2]
    grid = read_grid(path, dasha_lord)
    if grid is None:
        json.dump({"found": False, "grid": []}, sys.stdout, ensure_ascii=False)
        return 0

    json.dump({"found": True, "grid": grid}, sys.stdout, ensure_ascii=False)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
