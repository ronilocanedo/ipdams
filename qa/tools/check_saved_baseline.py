#!/usr/bin/env python3
"""Read-only consistency check of saved IPDAMS synthetic QA results.

Usage: python qa/tools/check_saved_baseline.py PATH_TO_LOCAL_QA_DIRECTORY
Does not contact production or make any database/HTTP requests. It reads only
three pre-existing JSON reports and emits a short summary; it creates no files.
"""
import argparse
import json
from pathlib import Path
import sys


def read_json(path):
    if not path.is_file():
        raise ValueError(f"Missing historical report: {path}")
    with path.open(encoding="utf-8-sig") as stream:
        return json.load(stream)


def check(qa_root):
    overall = read_json(qa_root / "comprehensive/results/overall-summary.json")
    funded = read_json(qa_root / "funded-settlement-qa-20261116/funded-e2e-summary.json")
    accounting = read_json(qa_root / "funded-settlement-qa-20261116/reimbursement-accounting-readiness-8771.json")
    suites = (
        overall["restored_browser"], overall["disposable_browser"],
        overall["original_financial_audit"], overall["disposable_financial_audit"],
        overall["original_pure_rules"], overall["disposable_pure_rules"],
    )
    passes = sum(int(s.get("passed", 0)) for s in suites)
    failed = sum(int(s.get("failed", 0)) for s in suites)
    skipped = sum(int(s.get("skipped", 0)) for s in suites)
    tests = [
        (overall.get("assessment") == "PASS_WITH_DOCUMENTED_LIMITATIONS", "historical suite assessment"),
        ((passes, failed, skipped) == (162, 0, 8), "historical counts"),
        (overall.get("production_tested") is False, "not production-tested"),
        (overall.get("restored_fingerprint_unchanged") is True, "restored fingerprint"),
        (overall.get("disposable_fingerprint_unchanged") is True, "disposable fingerprint"),
        (funded.get("assessment") == "BLOCKED_BY_EXISTING_APPLICATION_GUARD", "funded finalization blocked"),
        (funded.get("dv_id") == 13 and funded.get("reimbursement") == "359.75", "synthetic DV identity and amount"),
        (funded.get("readback", {}).get("dv_status") == "DRAFT", "DV remains draft"),
        (funded.get("readback", {}).get("check_count") == 0, "no synthetic check"),
        (accounting.get("read_only") is True and accounting.get("journal_preview_only") is True, "read-only accounting preview"),
        (accounting.get("finalization_authorized") is False, "no finalization authority"),
        (accounting.get("debit_total") == "359.75" and accounting.get("allocation_count") == 2, "two allocations reconcile"),
    ]
    errors = [label for ok, label in tests if not ok]
    print(f"Historical QA baseline: {passes} PASS / {failed} FAIL / {skipped} SKIPPED")
    print("Status: " + ("PASS (evidence consistency only)" if not errors else "FAIL (evidence mismatch)"))
    print("Funded DV finalization: BLOCKED (saved result; no rerun)")
    for error in errors:
        print("Mismatch: " + error, file=sys.stderr)
    return not errors


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("qa_root", type=Path, help="Path ending in IPDAMS-DV-Local-QA/qa")
    args = parser.parse_args()
    try:
        return 0 if check(args.qa_root) else 1
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as e:
        print(f"BLOCKED: {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
