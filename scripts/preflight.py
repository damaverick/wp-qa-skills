#!/usr/bin/env python3
"""Pre-flight validator for wp-qa-skills.

Usage:
  python3 scripts/preflight.py [--base .]

Exit 0 = no FAIL. Exit 1 = at least one FAIL.
"""

import argparse
import re
import subprocess
import sys
from pathlib import Path


def chk(status, label, note=""):
    pad = {"OK": "OK  ", "WARN": "WARN", "FAIL": "FAIL"}[status]
    line = f"[{pad}] {label}"
    if note:
        line += f" — {note}"
    print(line)
    return status


def count_lines(path):
    n = 0
    with open(path, errors="replace") as f:
        for _ in f:
            n += 1
    return n


def config_value(config_path, setting):
    if not config_path.exists():
        return None
    text = config_path.read_text()
    m = re.search(r"\|\s*`" + re.escape(setting) + r"`\s*\|\s*`?([^|`\n]+?)`?\s*\|", text)
    return m.group(1).strip() if m else None


def run_checks(base_str):
    base = Path(base_str)
    counts = {"OK": 0, "WARN": 0, "FAIL": 0}

    def c(status, label, note=""):
        s = chk(status, label, note)
        counts[s] += 1

    # WP root
    if (base / "wp-content").is_dir():
        c("OK", "wp-content/ directory found")
    else:
        c("FAIL", "wp-content/ not found", f"run from WordPress root, not {base}")

    # debug.log
    debug_log = base / "wp-content" / "debug.log"
    if debug_log.exists():
        lines = count_lines(debug_log)
        mb = debug_log.stat().st_size / 1024 / 1024
        c("OK", f"wp-content/debug.log exists ({lines} lines, {mb:.1f}MB)")
    else:
        c("WARN", "wp-content/debug.log not found",
          "define('WP_DEBUG_LOG', true) in wp-config.php")

    # Optional logs
    for label, rel in [("logs/php-error.log", "logs/php-error.log"),
                        ("logs/access.log", "logs/access.log")]:
        p = base / rel
        if p.exists():
            c("OK", f"{label} found")
        else:
            c("WARN", f"{label} not found", "optional — skip if unused")

    # mu-plugin
    mu = base / "wp-content" / "mu-plugins" / "qm-perf-capture.php"
    if mu.exists():
        c("OK", "wp-content/mu-plugins/qm-perf-capture.php installed")
    else:
        c("FAIL", "qm-perf-capture.php not installed",
          "run: python3 setup.py  or copy from skills/site-audit/mu-plugins/")

    # qm-output directory — must exist before Playwright crawl
    qm_out = base / "wp-content" / "qm-output"
    if qm_out.is_dir():
        c("OK", "wp-content/qm-output/ exists")
    else:
        c("FAIL", "wp-content/qm-output/ missing",
          "run: python3 setup.py  or: mkdir -p wp-content/qm-output")

    # wp-config.php debug flags
    wp_config = base / "wp-config.php"
    if wp_config.exists():
        text = wp_config.read_text(errors="replace")
        for key in ("WP_DEBUG", "WP_DEBUG_LOG"):
            pat = r"define\s*\(\s*['\"]" + key + r"['\"]\s*,\s*true\s*\)"
            found = bool(re.search(pat, text))
            c("OK" if found else "WARN", f"{key} = {'true' if found else 'not set'} in wp-config.php")
    else:
        c("WARN", "wp-config.php not found")

    # Python
    c("OK", f"Python {sys.version.split()[0]} available")

    # Node
    r = subprocess.run(["node", "--version"], capture_output=True, text=True)
    if r.returncode == 0:
        c("OK", f"Node {r.stdout.strip()} available")
    else:
        c("WARN", "Node not found", "install Node.js for Playwright")

    # Playwright node_modules
    nm = base / "tests" / "playwright" / "node_modules"
    if nm.exists():
        c("OK", "tests/playwright/node_modules found")
    else:
        c("FAIL", "tests/playwright/node_modules not found",
          "run: cd tests/playwright && npm install")

    # CONFIG.md
    config_path = base / ".claude" / "skills" / "site-audit" / "CONFIG.md"
    if config_path.exists():
        c("OK", ".claude/skills/site-audit/CONFIG.md exists")
    else:
        c("FAIL", ".claude/skills/site-audit/CONFIG.md missing", "run: python3 setup.py")

    # base_url
    base_url = config_value(config_path, "base_url")
    if base_url and "REQUIRED" not in base_url:
        c("OK", f"base_url configured: {base_url}")
    else:
        c("FAIL", "base_url not configured", "run: python3 setup.py")

    # max_urls
    max_urls = config_value(config_path, "max_urls")
    if max_urls and "REQUIRED" not in max_urls:
        c("OK", f"max_urls configured: {max_urls}")
    else:
        c("FAIL", "max_urls still set to REQUIRED", "run: python3 setup.py")

    print()
    print(f"Summary: {counts['OK']} OK, {counts['WARN']} WARN, {counts['FAIL']} FAIL")
    return counts["FAIL"] == 0


def main():
    p = argparse.ArgumentParser(description="Pre-flight validator for wp-qa-skills")
    p.add_argument("--base", default=".", help="WordPress root directory")
    args = p.parse_args()
    ok = run_checks(args.base)
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
