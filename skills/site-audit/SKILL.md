---
name: site-audit
description: "Run comprehensive pre-launch site audit covering PHP errors, JS errors, slow queries, and performance. Use when user says 'audit site', 'pre-launch check', 'site audit', 'run audit', or '/site-audit'. Orchestrates Python log aggregation, Playwright crawl, QM analysis, and autonomous fix loop."
---

# Site Audit

Pre-launch site audit pipeline: aggregate all log sources into a token-efficient summary, crawl the front-end for JS errors and QM data, rank pages RED/AMBER/GREEN, and autonomously fix the worst offenders.

## Prerequisites

**Read `CONFIG.md` at the start of every run.** Contains thresholds, log paths, QM cookie value, and URL scope. If `CONFIG.md` doesn't exist, warn the user.

## When to Use

- User says "audit site", "pre-launch check", "site audit", "run audit"
- Before a major launch or deployment
- After significant theme/plugin changes
- Periodic health check of the site

## Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `urls` | from CONFIG.md | URL source: `sitemap`, `file:<path>`, or comma-separated list |
| `max-urls` | from CONFIG.md | Cap URLs to crawl (no default — set in CONFIG.md per site) |
| `skip-crawl` | `false` | Skip Playwright crawl (use cached QM data) |
| `skip-review` | `false` | Skip review phase |
| `auto-fix` | `true` | Auto-fix REDs in autonomous loop |
| `branch` | `ask` | Branch strategy: `ask`, `new`, `main` |
| `stop-after` | `6` | Stop after this phase number and write handover doc (1–6) |
| `resume` | `false` | Resume from `audit-handover.md` — skip completed phases |

## Invocation

```
/site-audit                          # full audit with CONFIG.md settings
/site-audit max-urls=50             # override URL cap from config
/site-audit skip-crawl=true         # use cached QM data only
/site-audit skip-review=true        # skip review phase
/site-audit auto-fix=false          # analyse only, no fixes
/site-audit urls=file:urls.txt      # custom URL list
/site-audit stop-after=2            # run phases 0-2 then write handover, stop
/site-audit stop-after=3            # run through analysis then stop
/site-audit resume=true             # read audit-handover.md and continue from next phase
```

## Batch / Multi-Session Workflow

For large sites or context-limited sessions, run phases in separate sessions:

**Session 1 — crawl only:**
```
/site-audit stop-after=2
```
Runs setup → log aggregation → Playwright crawl. Writes `audit-handover.md`. Stop.

**Session 2 — analyse + plan fixes:**
```
/site-audit resume=true stop-after=3
```
Reads handover, runs analysis, presents RED/AMBER/GREEN table. Stop before fixing.

**Session 3 — fix loop:**
```
/site-audit resume=true auto-fix=true stop-after=4
```
Runs fix loop only. Writes updated handover. Stop before review.

**Session 4 — review + ship:**
```
/site-audit resume=true stop-after=6
```
Runs review and creates PR.

### Handover Doc Format

Written to `audit-handover.md` in WP root after each `stop-after` phase.
**Always overwrite — only one handover doc exists at a time.**

```markdown
# Audit Handover — {YYYY-MM-DD}

## Last completed phase: {N} — {Phase Name}

## Site
- Base URL: {base_url}
- Branch: {branch name or 'none'}
- URLs crawled: {N}

## Key Findings
- RED pages: {N} — {worst URL(s)}
- AMBER pages: {N}
- GREEN pages: {N}
- Top errors: {1–3 line summary}

## Data Files
- `audit-summary.json` — full aggregated results
- `wp-content/qm-output/js-errors.json` — JS error crawl output

## Resume Command
\`\`\`
/site-audit resume=true stop-after={next phase}
\`\`\`

## Notes
{anything the next session needs to know — decisions made, skipped items, caveats}
```

### Resume Behaviour

When `resume=true`:
1. Read `audit-handover.md`
2. Announce: "Resuming from phase {N+1}. Last run: {date}. {Key finding summary}."
3. Skip all phases ≤ last completed phase
4. Continue from next phase using existing `audit-summary.json` and `js-errors.json`
5. Do NOT re-run the crawl unless `skip-crawl=false` is explicitly passed

## Architecture

```
ORCHESTRATOR (main session)
|
+-- Phase 0: SETUP
|   Read CONFIG.md for thresholds, log paths, URL scope, max_urls
|   Disable caching plugins if active (W3 Total Cache, WP Rocket, etc.)
|   Confirm QM JSON dispatcher mu-plugin enabled
|   Check wp-content/qm-output/ exists and writable
|
+-- Phase 1: AGGREGATE LOGS (token-free — Python)
|   Run audit.py: reads all log sources, outputs audit-summary.json
|   Sources: wp-content/debug.log, PHP error log, QM JSON dir, access log
|   Output: audit-summary.json — deduplicated, ranked by severity
|
+-- Phase 2: PLAYWRIGHT CRAWL (token-free — Playwright CLI)
|   Run Playwright crawl: uses QM auth cookie + optional HTTP basic auth
|   Captures per URL:
|     - JS runtime exceptions (uncaught errors)
|     - console.error messages (CORS warnings, deprecations)
|     - Failed network requests (CORS blocks, 404s, timeouts)
|     - HTTP error responses (4xx, 5xx on sub-resources)
|   Categorizes each issue: cors | runtime | network | security | deprecation
|   Writes wp-content/qm-output/js-errors.json (full results with categories)
|   Re-run audit.py to merge JS errors into audit-summary.json
|
+-- Phase 3: ANALYSIS
|   Read audit-summary.json (~5K tokens)
|   Rank URLs RED/AMBER/GREEN against CONFIG.md thresholds
|   Present worst offenders table to user → USER GATE
|   STOP until user confirms proceed
|
+-- Phase 4: AUTONOMOUS FIX LOOP
|   For each RED URL (worst first):
|     Research root cause (Explore agent or direct search)
|     Implement fix (Edit tool)
|     Commit per root cause (cite URL + metric)
|     Re-audit that URL subset to verify
|   Iteration cap from CONFIG.md. Stop if zero REDs.
|
+-- Phase 5: REVIEW (call review-fix or review-team)
|   review-fix skill: 8 parallel reviewers, auto-fix quick items
|   Gate: all critical/high findings resolved
|   Skip if skip-review=true
|
+-- Phase 6: COMMIT & SUMMARY
|   Push to audit/{YYYY-MM-DD}-{summary} branch, present summary table
|   Report: URLs audited, REDs fixed, AMBERs remaining
```

### Phase Execution Order — STRICT SEQUENTIAL

**Execute phases 0 -> 1 -> 2 -> 3 -> 4 -> 5 -> 6 in order.** Phase 2 skips if `skip-crawl=true`. Phase 5 skips if `skip-review=true`. Phase 4 skips if `auto-fix=false`.

---

## Workflow

### Phase 0: Setup

1. **Read CONFIG.md** to load thresholds, paths, and settings
2. **Check prerequisites:**
   - QM JSON dispatcher mu-plugin active
   - `wp-content/qm-output/` directory exists
   - Debug log accessible (if WP_DEBUG_LOG enabled)
3. **Disable caching:** If any caching plugin is active (W3 Total Cache, WP Rocket, etc.), disable it via WP-CLI or admin
4. **Set QM threshold:** `QM_DB_EXPENSIVE=0.05` if configurable
5. **Resolve branch strategy** (unless `branch` param explicitly set):
   - Default (`ask`): prompt user — main / new audit branch / worktree
   - New branch: `git checkout -b audit/{YYYY-MM-DD}-{summary}` (e.g., `audit/2026-04-28-fix-homepage-queries`)
   - Worktree: `EnterWorktree` with name `audit-{YYYY-MM-DD}-{summary}`

6. **Before branching, check for uncommitted changes:**
   ```bash
   git status --porcelain
   ```
   If dirty: warn user, offer to `git stash` before creating branch. Do NOT create branch with uncommitted changes.

### Phase 1: Aggregate Logs

Run the Python aggregator:

```bash
python3 skills/site-audit/audit.py --base .
```

This reads all available log sources and outputs `audit-summary.json`.

If the script fails, read individual sources manually:
```bash
# Count debug.log errors
grep -c "PHP" wp-content/debug.log 2>/dev/null || echo "No debug.log"

# Count QM JSON files
ls wp-content/qm-output/*.json 2>/dev/null | wc -l

# Check JS errors
cat wp-content/qm-output/js-errors.json 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'{len(d)} URLs with JS errors')" || echo "No js-errors.json"
```

### Phase 2: Playwright Crawl

**Skip if `skip-crawl=true`.**

Run the Playwright crawl:

```bash
cd tests/playwright && npx playwright test src/crawl.spec.ts --reporter=list
```

For staging environments with HTTP basic auth:
```bash
HTTP_USER=username HTTP_PASS=password SITE_BASE=https://staging.example.com \
  npx playwright test src/crawl.spec.ts --reporter=list
```

This visits all URLs with QM auth cookie and captures **per URL**:
- QM JSON profiles (written by mu-plugin)
- JS runtime exceptions (uncaught errors via `pageerror` event)
- Console errors (CORS warnings, deprecations via `console.error`)
- Failed network requests (CORS blocks, timeouts via `requestfailed` event)
- HTTP error responses on sub-resources (4xx/5xx via `response` event)

Each issue is categorized as: `cors` | `runtime` | `network` | `security` | `deprecation`

Output: `wp-content/qm-output/js-errors.json` — full results with per-URL breakdown and category counts.

After crawl completes, re-run audit.py:

```bash
python3 skills/site-audit/audit.py --base .
```

### Phase 3: Analysis

1. **Read `audit-summary.json`** — focus on:
   - `summary` block: RED/AMBER/GREEN counts
   - `url_scores`: worst RED URLs first
   - `errors`: top deduplicated errors

2. **Present findings to user:**

```
## Site Audit Results

| Status | Count |
|--------|-------|
| RED    | {N}   |
| AMBER  | {N}   |
| GREEN  | {N}   |

### Worst RED URLs
| URL | Queries | TTFB | PHP Errors | JS Errors | Reasons |
|-----|---------|------|------------|-----------|---------|
| ... | ...     | ...  | ...        | ...       | ...     |

### Top Errors
1. [{type}] {message} — {count} occurrences
2. ...

Proceed with autonomous fix loop? (30 iteration cap)
```

3. **STOP — user gate.** Do NOT proceed until user confirms.

### Phase 4: Autonomous Fix Loop

**Skip if `auto-fix=false`.**

For each RED URL, worst first:

1. **Research** — find root cause:
   - PHP errors: Read cited file ±25 lines around error line
   - Slow queries: Check QM `top_slow_queries` for the URL, trace caller
   - Query count: Check `query_pattern_histogram` for N+1 patterns
   - JS errors: Check `js-errors.json` entry for the URL, search codebase for error source

2. **Fix** — apply code change:
   - One root cause at a time
   - Verify with `php -l` for PHP files
   - If 3 fix attempts fail → skip and flag for human

3. **Commit** — one commit per root cause. Body must include the fix table:
   ```bash
   git add <files-changed>
   git commit -m "$(cat <<'EOF'
   fix: <description>

   | Bug | File(s) | Fix | How to test |
   |-----|---------|-----|-------------|
   | {what was wrong} | {file:line} | {what changed} | {steps to verify} |

   URL: <affected URL>
   Metric: <queries/TTFB/PHP error/JS error>
   Evidence: audit-summary.json

   Co-Authored-By: Claude <noreply@anthropic.com>
   EOF
   )"
   ```

4. **Re-audit** — re-run audit.py on the fixed URL to verify improvement

**Cap from CONFIG.md** (`max_iterations`). If zero REDs achieved, proceed. If cap reached, present remaining REDs and ask user.

**Revert rule:** If any fix regresses a previously green URL, revert it immediately.

### Phase 5: Review

**Skip if `skip-review=true`.**

Invoke the `review-fix` skill:
- 8 parallel reviewers examine the diff
- Auto-fix quick items
- Gate: all critical/high findings resolved before proceeding

### Phase 6: Commit & Summary

1. **STOP — user gate.** Present the branch and commits. Ask: "Push branch and create PR?" Do NOT proceed until user confirms.

2. **Push branch** (named `audit/{YYYY-MM-DD}-{summary}`):
   ```bash
   git push -u origin $(git branch --show-current)
   ```

3. **Create GitHub PR** with the fix table as description:
   ```bash
   gh pr create \
     --title "Audit fix: {summary}" \
     --body "$(cat <<'EOF'
   | Bug | File(s) | Fix | How to test |
   |-----|---------|-----|-------------|
   | ... | ... | ... | ... |

   ## How to review
   - [ ] Each commit is one root cause
   - [ ] No regressions (re-run audit on fixed URLs)
   EOF
   )"
   ```

4. **Present summary:**

```
## Site Audit Complete

**Branch**: `audit/{YYYY-MM-DD}`
**URLs audited**: {N}
**REDs fixed**: {N}
**AMBERs remaining**: {N}
**Commits**: {N}

### Bug fixes applied
| Bug | File(s) | Fix | How to test |
|-----|---------|-----|-------------|
| {e.g. homepage 339 queries} | {file:line} | {what changed} | {visit URL, check QM} |
| ... | ... | ... | ... |

### Remaining AMBER
| URL | Reasons |
|-----|---------|
| ... | approaching threshold |

### Phase tracker
| Phase | Status |
|-------|--------|
| 0. Setup | Done |
| 1. Aggregate Logs | {N} sources |
| 2. Playwright Crawl | {N} URLs crawled / Skipped |
| 3. Analysis | User confirmed |
| 4. Fix Loop | {N} iterations, {N} REDs fixed |
| 5. Review | {Done / Skipped} |
| 6. Summary | Done |
```

---

## Error Handling

| Scenario | Action |
|----------|--------|
| audit.py fails | Run manual log checks, present raw counts |
| No QM JSON files | Run Playwright crawl first, or warn user |
| Playwright not installed | Skip crawl, use cached data, warn user |
| Fix breaks a previously green URL | Revert fix immediately, note in summary |
| 3 fix attempts fail for same error | Skip, flag for human review |
| Iteration cap reached | Present remaining REDs, ask user next step |
| Caching plugin won't disable | Warn user, continue (results may be cached) |
| debug.log not accessible | Skip PHP log parsing, note in summary |
| `gh` CLI not installed | Warn user, skip PR creation, push branch only |
| Branch already exists | Use unique suffix, or prompt for different branch name |

## Escalation

Stop and notify user if:
- Root cause unclear after 3 fix attempts per error
- Database schema changes required
- Plugin core files must be modified (no clean hook workaround)
- Fix touches login, authentication, or payment flows
- Security compromise suspected
