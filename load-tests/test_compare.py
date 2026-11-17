#!/usr/bin/env python3
"""Unit checks for the human-readable k6 comparison report."""
import json
import tempfile
import unittest
from pathlib import Path
from compare import render


class CompareTest(unittest.TestCase):
    def test_metrics_and_missing_values(self):
        with tempfile.TemporaryDirectory() as temp:
            first = Path(temp) / "prod-smoke-summary.json"
            first.write_text(json.dumps({
                "metrics": {
                    "http_reqs": {"values": {"rate": 34.5, "count": 345}},
                    "http_req_duration": {"values": {"p(95)": 120.1, "p(99)": 299.2}},
                    "http_req_failed": {"values": {"rate": 0.01}},
                    "symsvel_server_error_rate": {"values": {"rate": 0.0}},
                    "symsvel_http_429": {"values": {"count": 0}}
                }
            }), encoding="utf-8")
            second = Path(temp) / "dev-load-summary.json"
            second.write_text(json.dumps({"metrics": {}}), encoding="utf-8")
            report = render([first, second])
            self.assertIn("prod-smoke | 345 | 34.5 | 120.1 | 299.2 | 1.00 | 0.00 | 0", report)
            self.assertIn("dev-load | n/a | n/a", report)
            self.assertIn("Req./s", report)

    def test_rejects_invalid_summary(self):
        with tempfile.TemporaryDirectory() as temp:
            file = Path(temp) / "bad.json"
            file.write_text("{}", encoding="utf-8")
            with self.assertRaises(ValueError):
                render([file])


if __name__ == "__main__":
    unittest.main()
