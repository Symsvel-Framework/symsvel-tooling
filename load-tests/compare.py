#!/usr/bin/env python3
"""Compare k6 summary JSON files without an external Python dependency."""
from __future__ import annotations

import argparse
import json
from pathlib import Path


def metric(payload: dict, name: str, value: str) -> float | None:
    values = payload.get("metrics", {}).get(name, {}).get("values", {})
    raw = values.get(value)
    return float(raw) if isinstance(raw, (float, int)) else None


def human(value: float | None, decimals: int = 1) -> str:
    return "n/a" if value is None else f"{value:.{decimals}f}"


def row(path: Path) -> list[str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict) or not isinstance(data.get("metrics"), dict):
        raise ValueError(f"Invalid k6 summary: {path}")
    rate = metric(data, "http_reqs", "rate")
    p95 = metric(data, "http_req_duration", "p(95)")
    p99 = metric(data, "http_req_duration", "p(99)")
    failures = metric(data, "http_req_failed", "rate")
    server = metric(data, "symsvel_server_error_rate", "rate")
    throttled = metric(data, "symsvel_http_429", "count")
    count = metric(data, "http_reqs", "count")
    return [
        path.stem.replace("-summary", ""),
        human(count, 0),
        human(rate),
        human(p95),
        human(p99),
        human(failures * 100 if failures is not None else None, 2),
        human(server * 100 if server is not None else None, 2),
        human(throttled, 0),
    ]


def render(paths: list[Path]) -> str:
    if not paths:
        raise ValueError("At least one k6 summary JSON file is required.")
    headings = ["Test", "HTTP req.", "Req./s", "p95 (ms)", "p99 (ms)", "Échecs %", "5xx %", "HTTP 429"]
    records = [row(path) for path in paths]
    lines = [
        "| " + " | ".join(headings) + " |",
        "|" + "|".join("---" for _ in headings) + "|",
    ]
    lines.extend("| " + " | ".join(value.replace("|", "/") for value in record) + " |"
                 for record in records)
    return "\n".join(lines)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Compare local SymSvel k6 summary exports.")
    parser.add_argument("summaries", nargs="+", type=Path)
    args = parser.parse_args()
    print(render(args.summaries))
