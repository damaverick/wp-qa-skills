#!/usr/bin/env python3
"""Token-efficient log filter for wp-qa-skills.

Usage:
  python3 scripts/log-filter.py debug-log [--file wp-content/debug.log] [--top 20] [--since 7d]
  python3 scripts/log-filter.py access-log [--file logs/access.log] [--top 20] [--since 7d]
  python3 scripts/log-filter.py all [--base .]

Outputs compact JSON (~500 tokens vs ~40K raw log).
"""

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path


def parse_since(s):
    if not s:
        return None
    m = re.match(r"^(\d+)([dhm])$", s)
    if not m:
        return None
    n, unit = int(m.group(1)), m.group(2)
    return timedelta(days=n) if unit == "d" else timedelta(hours=n) if unit == "h" else timedelta(minutes=n)


def cutoff(since_str):
    delta = parse_since(since_str)
    return datetime.now(tz=timezone.utc) - delta if delta else None


# ── debug-log ────────────────────────────────────────────

PHP_PAT = re.compile(
    r"^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\] "
    r"PHP (Fatal error|Warning|Notice|Deprecated|Parse error|Strict Standards): "
    r"(.+?) in (.+?) on line (\d+)",
    re.MULTILINE,
)


def parse_ts_debug(ts):
    try:
        return datetime.strptime(ts, "%d-%b-%Y %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def normalize(msg):
    msg = re.sub(r"/[\w./\-]+\.php", "/file.php", msg)
    msg = re.sub(r"\b\d+\b", "N", msg)
    msg = re.sub(r"'[^']*'", "'X'", msg)
    return msg.strip()


def parse_debug_log(file_path, top=20, since=None):
    path = Path(file_path)
    if not path.exists():
        return {"error": f"not found: {file_path}"}

    cut = cutoff(since)
    total = 0
    counts = Counter()
    examples = {}

    for line in open(path, errors="replace"):
        total += 1
        m = PHP_PAT.match(line)
        if not m:
            continue
        ts_str, err_type, msg, src_file, src_line = m.groups()
        if cut:
            ts = parse_ts_debug(ts_str)
            if ts and ts < cut:
                continue
        norm = normalize(msg)
        key = f"{err_type}:{norm}"
        counts[key] += 1
        if key not in examples:
            examples[key] = {"type": err_type, "message": msg[:200],
                             "file": src_file, "line": int(src_line)}

    type_totals = defaultdict(int)
    for key, n in counts.items():
        type_totals[examples[key]["type"]] += n

    return {
        "total_lines": total,
        "unique_errors": len(counts),
        "by_type": dict(type_totals),
        "top_errors": [{**examples[k], "count": n} for k, n in counts.most_common(top)],
    }


# ── access-log ───────────────────────────────────────────

ACCESS_PAT = re.compile(
    r'\S+ \S+ \S+ \[([^\]]+)\] "\w+ (\S+) \S+" (\d+) \S+'
)


def parse_ts_access(ts):
    try:
        return datetime.strptime(ts, "%d/%b/%Y:%H:%M:%S %z")
    except ValueError:
        return None


def parse_access_log(file_path, top=20, since=None):
    path = Path(file_path)
    if not path.exists():
        return {"error": f"not found: {file_path}"}

    cut = cutoff(since)
    total = 0
    status_counts = Counter()
    url_404 = Counter()
    url_5xx = Counter()
    hourly = Counter()

    for line in open(path, errors="replace"):
        m = ACCESS_PAT.match(line)
        if not m:
            continue
        ts_str, path_str, status_str = m.groups()
        if cut:
            ts = parse_ts_access(ts_str)
            if ts and ts < cut:
                continue
        total += 1
        status = int(status_str)
        status_counts[status] += 1
        if status == 404:
            url_404[path_str] += 1
        elif status >= 500:
            url_5xx[path_str] += 1
        hour_m = re.match(r"(\d+/\w+/\d+:\d+)", ts_str)
        if hour_m:
            hourly[hour_m.group(1)] += 1

    return {
        "total_requests": total,
        "status_codes": dict(status_counts.most_common()),
        "top_404_urls": [{"url": u, "count": c} for u, c in url_404.most_common(top)],
        "top_5xx_urls": [{"url": u, "count": c} for u, c in url_5xx.most_common(top)],
        "hourly_traffic": dict(sorted(hourly.most_common(24))),
    }


# ── subcommands ───────────────────────────────────────────

def cmd_debug(args):
    print(json.dumps(parse_debug_log(args.file, args.top, getattr(args, "since", None)), indent=2))


def cmd_access(args):
    print(json.dumps(parse_access_log(args.file, args.top, getattr(args, "since", None)), indent=2))


def cmd_all(args):
    base = args.base
    print(json.dumps({
        "debug_log": parse_debug_log(f"{base}/wp-content/debug.log", args.top),
        "access_log": parse_access_log(f"{base}/logs/access.log", args.top),
    }, indent=2))


# ── main ─────────────────────────────────────────────────

def main():
    p = argparse.ArgumentParser(description="Token-efficient log filter for wp-qa-skills")
    sub = p.add_subparsers(dest="cmd", required=True)

    dl = sub.add_parser("debug-log")
    dl.add_argument("--file", default="wp-content/debug.log")
    dl.add_argument("--top", type=int, default=20)
    dl.add_argument("--since", default=None, help="7d | 24h | 30m")
    dl.set_defaults(func=cmd_debug)

    al = sub.add_parser("access-log")
    al.add_argument("--file", default="logs/access.log")
    al.add_argument("--top", type=int, default=20)
    al.add_argument("--since", default=None, help="7d | 24h | 30m")
    al.set_defaults(func=cmd_access)

    a = sub.add_parser("all")
    a.add_argument("--base", default=".")
    a.add_argument("--top", type=int, default=20)
    a.set_defaults(func=cmd_all)

    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
