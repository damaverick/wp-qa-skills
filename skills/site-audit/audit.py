#!/usr/bin/env python3
"""
Site Audit Log Aggregator — combines QM JSON, PHP logs, JS errors, access logs
into a single token-efficient audit-summary.json (~5K tokens for 200 URLs).

Usage: python3 skills/site-audit/audit.py [--base /path/to/wp-root] [--thresholds thresholds.json]
"""

import re
import csv
import json
import glob
import argparse
import os
from collections import Counter, defaultdict
from pathlib import Path
from datetime import datetime


# -- Thresholds (RED) ---------------------------------------------------------
DEFAULT_THRESHOLDS = {
    'query_count': 100,
    'duplicate_queries': 5,
    'slow_query_ms': 50,       # 0.05s
    'ttfb_ms': 500,
    'load_ms': 3000,
    'memory_mb': 64,
    'peak_memory_mb': 128,
}
SEVERITY = ['GREEN', 'AMBER', 'RED']


# -- Parsers ------------------------------------------------------------------

def parse_qm_json(filepath: str) -> dict | None:
    """Parse a single QM JSON output file. Returns dict or None."""
    try:
        with open(filepath, 'r', errors='ignore') as f:
            data = json.load(f)
        if not isinstance(data, dict) or 'url' not in data:
            return None
        # Minimal schema validation: warn if expected keys missing
        expected = ['db', 'timings', 'memory']
        missing = [k for k in expected if k not in data]
        if missing:
            url = data.get('url', 'unknown')
            print(f"WARNING: {url} missing QM keys: {missing}")
        return data
    except (json.JSONDecodeError, IOError):
        return None


def parse_debug_log(filepath: str) -> list[dict]:
    """
    Parse wp-content/debug.log for PHP errors.
    Returns list of {type, message, file, line, count}.
    """
    pattern = re.compile(
        r'PHP (Fatal error|Warning|Notice|Parse error|Deprecated):\s+(.*?)(?:\s+in\s+([^(]+?)(?:\((\d+)\))?)?$',
        re.MULTILINE
    )
    errors = []
    try:
        with open(filepath, 'r', errors='ignore') as f:
            content = f.read()
        for m in pattern.finditer(content):
            err_type = m.group(1).strip()
            msg = m.group(2).strip()
            fname = m.group(3).strip() if m.group(3) else ''
            line = int(m.group(4)) if m.group(4) else 0
            errors.append({
                'type': f'PHP_{err_type.upper().replace(" ", "_")}',
                'message': f'{err_type}: {msg}',
                'file': fname,
                'line': line,
            })
    except (IOError, OSError):
        pass
    return errors


def parse_php_error_log(filepath: str) -> list[dict]:
    """Parse PHP error log for fatal/warning/notice lines."""
    pattern = re.compile(
        r'(\d{4}/\d{2}/\d{2}\s+\d{2}:\d{2}:\d{2})\s+'
        r'\[(error|warning|notice)\]'
    )
    errors = []
    try:
        with open(filepath, 'r', errors='ignore') as f:
            for line in f:
                m = pattern.search(line)
                if m:
                    errors.append({
                        'type': f'PHP_{m.group(2).upper()}',
                        'message': line.strip()[:200],
                        'file': '',
                        'line': 0,
                    })
    except (IOError, OSError):
        pass
    return errors


def parse_js_errors(filepath: str) -> list[dict]:
    """Parse js-errors.json from Playwright crawl output."""
    try:
        with open(filepath, 'r', errors='ignore') as f:
            data = json.load(f)
        if not isinstance(data, list):
            return []
        return data  # [{url, errors: [{message, type}]}]
    except (json.JSONDecodeError, IOError):
        return []


def parse_php_error_csv(filepath: str) -> list[dict]:
    """Parse wp-content/logs/php-error-logs.csv (columns: Message, Time)."""
    pattern = re.compile(
        r'PHP (Fatal error|Warning|Notice|Parse error|Deprecated):\s+(.*?)(?:\s+in\s+([^(]+?)(?:\s+on\s+line\s+(\d+))?)?$'
    )
    errors = []
    try:
        with open(filepath, 'r', errors='ignore', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                msg = row.get('Message', '').strip()
                m = pattern.search(msg)
                if m:
                    err_type = m.group(1).strip()
                    body = m.group(2).strip()
                    fname = m.group(3).strip() if m.group(3) else ''
                    line = int(m.group(4)) if m.group(4) else 0
                    errors.append({
                        'type': f'PHP_{err_type.upper().replace(" ", "_")}',
                        'message': f'{err_type}: {body}',
                        'file': fname,
                        'line': line,
                    })
                elif msg:
                    errors.append({
                        'type': 'PHP_ERROR',
                        'message': msg[:200],
                        'file': '',
                        'line': 0,
                    })
    except (IOError, OSError):
        pass
    return errors


def parse_access_csv(filepath: str) -> dict:
    """
    Parse wp-content/logs/access-logs.csv.
    Columns: Log Type, IP Address, Status, Time, Resource, User Agent, Domain
    Returns dict with entry count and 4xx/5xx breakdown.
    """
    total = 0
    errors_4xx = []
    errors_5xx = []
    try:
        with open(filepath, 'r', errors='ignore', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                total += 1
                status = row.get('Status', '').strip().strip('"')
                resource = row.get('Resource', '').strip()
                try:
                    code = int(status)
                    if 400 <= code < 500:
                        errors_4xx.append({'status': code, 'resource': resource})
                    elif code >= 500:
                        errors_5xx.append({'status': code, 'resource': resource})
                except ValueError:
                    pass
    except (IOError, OSError):
        pass
    return {'entries': total, 'errors_4xx': errors_4xx, 'errors_5xx': errors_5xx}


def dedup_errors(errors: list[dict]) -> list[dict]:
    """Deduplicate errors by message, count occurrences."""
    counter = Counter()
    detail = {}
    for e in errors:
        key = e['message'][:120]
        counter[key] += 1
        if key not in detail:
            detail[key] = e
    return [
        {**detail[k], 'count': c}
        for k, c in counter.most_common()
    ]


def score_url(qm_data: dict, js_error_urls: dict, deduped_php: list[dict], thresholds: dict = None) -> dict:
    if thresholds is None:
        thresholds = DEFAULT_THRESHOLDS
    """Score a single URL from QM data against thresholds."""
    url = qm_data['url']
    result = {
        'url': url,
        'query_count': qm_data.get('db', {}).get('total_queries', 0),
        'duplicate_queries': qm_data.get('db', {}).get('duplicate_queries', 0),
        'ttfb_ms': int(qm_data.get('timings', {}).get('total', 0) * 1000),
        'memory_mb': qm_data.get('memory', {}).get('usage_mb', 0),
        'peak_memory_mb': qm_data.get('memory', {}).get('peak_mb', 0),
        'php_errors': len(qm_data.get('php_errors', [])),
        'js_errors': len(js_error_urls.get(url, [])),
        'slow_queries': len(qm_data.get('top_slow_queries', [])),
        'slowest_query_ms': 0,
    }

    # Get slowest query time
    slow_qs = qm_data.get('top_slow_queries', [])
    if slow_qs:
        result['slowest_query_ms'] = int(max(q['time'] for q in slow_qs) * 1000)

    # Scoring
    reasons = []
    if result['query_count'] > thresholds['query_count']:
        reasons.append(f"queries={result['query_count']}>{thresholds['query_count']}")
    if result['duplicate_queries'] > thresholds['duplicate_queries']:
        reasons.append(f"dupes={result['duplicate_queries']}>{thresholds['duplicate_queries']}")
    if result['slowest_query_ms'] > thresholds['slow_query_ms']:
        reasons.append(f"slowest_query={result['slowest_query_ms']}ms>{thresholds['slow_query_ms']}ms")
    if result['ttfb_ms'] > thresholds['ttfb_ms']:
        reasons.append(f"ttfb={result['ttfb_ms']}ms>{thresholds['ttfb_ms']}ms")
    if result['php_errors'] > 0:
        reasons.append(f"php_errors={result['php_errors']}")
    if result['js_errors'] > 0:
        reasons.append(f"js_errors={result['js_errors']}")
    if result['memory_mb'] > thresholds['memory_mb']:
        reasons.append(f"memory={result['memory_mb']}MB>{thresholds['memory_mb']}MB")
    if result['peak_memory_mb'] > thresholds['peak_memory_mb']:
        reasons.append(f"peak_memory={result['peak_memory_mb']}MB>{thresholds['peak_memory_mb']}MB")

    if reasons:
        result['status'] = 'RED'
        result['reasons'] = reasons
    elif (
        result['query_count'] > thresholds['query_count'] * 0.7
        or result['ttfb_ms'] > thresholds['ttfb_ms'] * 0.7
    ):
        result['status'] = 'AMBER'
        result['reasons'] = ['approaching threshold']
    else:
        result['status'] = 'GREEN'
        result['reasons'] = []

    return result


# -- Main ---------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description='Site Audit Log Aggregator')
    parser.add_argument('--base', default='.', help='Path to WordPress root')
    parser.add_argument('--thresholds', default=None,
                        help='Path to thresholds.json file (overrides defaults)')
    args = parser.parse_args()
    base = Path(args.base)

    # Load thresholds: file > defaults
    thresholds = DEFAULT_THRESHOLDS.copy()
    if args.thresholds:
        try:
            with open(args.thresholds, 'r') as f:
                thresholds.update(json.load(f))
        except (IOError, json.JSONDecodeError) as e:
            print(f"WARNING: Could not load thresholds from {args.thresholds}: {e}")
            print(f"Using default thresholds.")

    summary = {
        'timestamp': datetime.now().isoformat(),
        'generator': 'audit.py',
        'sources': {},
        'errors': [],
        'url_scores': {},
    }

    # --- Source 1: QM JSON files ---
    qm_dir = base / 'wp-content' / 'qm-output'
    qm_files = sorted(qm_dir.glob('*.json')) if qm_dir.exists() else []
    # Skip js-errors.json — handled separately
    qm_files = [f for f in qm_files if f.name != 'js-errors.json']

    qm_data_list = []
    for fpath in qm_files:
        data = parse_qm_json(str(fpath))
        if data:
            qm_data_list.append(data)

    summary['sources']['qm_json'] = {
        'files': len(qm_files),
        'parsed': len(qm_data_list),
        'urls_with_php_errors': sum(1 for d in qm_data_list if d.get('php_errors')),
    }

    # --- Source 2: JS errors ---
    js_errors_file = qm_dir / 'js-errors.json'
    js_data = parse_js_errors(str(js_errors_file)) if js_errors_file.exists() else []
    # Build lookup: URL -> list of error dicts
    js_error_urls = {}
    all_js_errors = []
    for entry in js_data:
        url = entry.get('url', '')
        errors = entry.get('errors', [])
        js_error_urls[url] = errors
        for e in errors:
            all_js_errors.append({
                'type': f'JS_{e.get("type", "error").upper().replace(".", "_")}',
                'message': e.get('message', ''),
                'file': '',
                'line': 0,
                'url': url,
            })

    summary['sources']['js_errors'] = {
        'files': 1 if js_errors_file.exists() else 0,
        'urls_with_errors': len(js_data),
        'total_errors': sum(len(e.get('errors', [])) for e in js_data),
    }

    # --- Source 3: debug.log ---
    debug_log = base / 'wp-content' / 'debug.log'
    php_debug_errors = parse_debug_log(str(debug_log)) if debug_log.exists() else []
    summary['sources']['debug_log'] = {
        'files': 1 if debug_log.exists() else 0,
        'errors': len(php_debug_errors),
        'unique': len(set(e['message'][:120] for e in php_debug_errors)),
    }

    # --- Source 4: PHP error log (text or CSV) ---
    php_error_log_txt = base / 'logs' / 'php-error.log'
    php_error_log_csv = base / 'wp-content' / 'logs' / 'php-error-logs.csv'
    php_log_errors: list[dict] = []
    if php_error_log_txt.exists():
        php_log_errors = parse_php_error_log(str(php_error_log_txt))
    if php_error_log_csv.exists():
        php_log_errors += parse_php_error_csv(str(php_error_log_csv))
    summary['sources']['php_error_log'] = {
        'files': sum([php_error_log_txt.exists(), php_error_log_csv.exists()]),
        'errors': len(php_log_errors),
    }

    # --- Source 5: Access log (text or CSV) ---
    access_log_txt = base / 'logs' / 'access.log'
    access_log_csv = base / 'wp-content' / 'logs' / 'access-logs.csv'
    access_entries = 0
    access_4xx: list[dict] = []
    access_5xx: list[dict] = []
    try:
        with open(access_log_txt, 'r', errors='ignore') as f:
            access_entries = sum(1 for _ in f)
    except (IOError, OSError):
        pass
    if access_log_csv.exists():
        csv_access = parse_access_csv(str(access_log_csv))
        access_entries += csv_access['entries']
        access_4xx = csv_access['errors_4xx']
        access_5xx = csv_access['errors_5xx']
    summary['sources']['access_log'] = {
        'files': sum([access_entries > 0, access_log_csv.exists()]),
        'entries': access_entries,
        'errors_4xx': len(access_4xx),
        'errors_5xx': len(access_5xx),
    }

    # --- Deduplicate and rank errors ---
    all_php_errors = php_debug_errors + php_log_errors
    deduped_php = dedup_errors(all_php_errors)
    deduped_js = dedup_errors(all_js_errors)

    summary['errors'] = deduped_php + deduped_js

    # --- Score URLs ---
    url_scores = {}
    red_count = amber_count = green_count = 0
    for qm_data in qm_data_list:
        score = score_url(qm_data, js_error_urls, deduped_php, thresholds)
        url_scores[qm_data['url']] = score
        if score['status'] == 'RED':
            red_count += 1
        elif score['status'] == 'AMBER':
            amber_count += 1
        else:
            green_count += 1

    # Compact: full detail for RED/AMBER, url+status only for GREEN
    compact_scores = {}
    for url, s in url_scores.items():
        if s['status'] == 'GREEN':
            compact_scores[url] = {'url': url, 'status': 'GREEN'}
        else:
            compact_scores[url] = s

    # Sort REDs first for easy reading
    sorted_scores = dict(
        sorted(compact_scores.items(), key=lambda x: (
            0 if x[1]['status'] == 'RED' else 1 if x[1]['status'] == 'AMBER' else 2,
            x[0]
        ))
    )
    summary['url_scores'] = sorted_scores
    summary['summary'] = {
        'total_urls_scored': len(url_scores),
        'red': red_count,
        'amber': amber_count,
        'green': green_count,
    }

    # --- Write output ---
    output_path = base / 'audit-summary.json'
    with open(output_path, 'w') as f:
        json.dump(summary, f, indent=2, default=str)

    # Console summary
    print(f"\n{'='*60}")
    print(f"SITE AUDIT SUMMARY — {summary['timestamp'][:19]}")
    print(f"{'='*60}")
    print(f"QM JSON files: {len(qm_data_list)} parsed")
    print(f"PHP errors:    {len(all_php_errors)} total, {len(deduped_php)} unique")
    print(f"JS errors:     {summary['sources']['js_errors']['total_errors']} total on {len(js_data)} URLs")
    access_4xx_count = len(access_4xx) if access_log_csv.exists() else 0
    access_5xx_count = len(access_5xx) if access_log_csv.exists() else 0
    print(f"Access log:    {access_entries} entries ({access_4xx_count} 4xx, {access_5xx_count} 5xx)")
    print(f"\nURLs: {red_count} RED | {amber_count} AMBER | {green_count} GREEN")
    print(f"\nWritten to: {output_path}")
    print(f"{'='*60}\n")

    # Print top 5 RED URLs
    red_urls = [(url, s) for url, s in url_scores.items() if s['status'] == 'RED']
    if red_urls:
        print("WORST RED URLs:")
        for url, s in red_urls[:5]:
            print(f"  {url}")
            for r in s.get('reasons', []):
                print(f"    - {r}")


if __name__ == '__main__':
    main()
