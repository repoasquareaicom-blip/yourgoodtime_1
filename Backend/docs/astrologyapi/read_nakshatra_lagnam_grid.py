#!/usr/bin/env python3
"""Read the first worksheet from nakshatra lagnam.xlsx and print JSON to stdout."""

from __future__ import annotations

import json
import sys

from read_excel_grid import cell_to_text


def read_first_sheet(path: str) -> list[list[str]]:
    lower = path.lower()
    if lower.endswith(".xlsx"):
        import openpyxl

        workbook = openpyxl.load_workbook(path, read_only=True, data_only=True)
        sheet = workbook[workbook.sheetnames[0]]
        grid: list[list[str]] = []
        for row_idx, row in enumerate(sheet.iter_rows(values_only=True)):
            if row_idx >= 500:
                break
            line = [cell_to_text(cell) for cell in row]
            while line and line[-1] == "":
                line.pop()
            if any(cell != "" for cell in line):
                grid.append(line)
        workbook.close()
        return grid

    raise ValueError("Unsupported Excel extension")


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: read_nakshatra_lagnam_grid.py <path>", file=sys.stderr)
        return 1

    path = sys.argv[1]
    grid = read_first_sheet(path)
    json.dump({"found": True, "grid": grid}, sys.stdout, ensure_ascii=False)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
