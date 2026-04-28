# Skill Workflow Overview

Quick reference for running or reviewing the two new skills.

## Skill 1: `site-audit` — Pre-Launch Site Audit

### What it does
Crawls the site, finds every performance problem and error, auto-fixes the worst ones.

### Triggers
`/site-audit`, "audit site", "pre-launch check", "run audit"

### How it works (6 phases)

```
Phase 0: SETUP — read CONFIG.md, disable cache, check QM plugin active
Phase 1: AGGREGATE — Python script reads all log sources, outputs audit-summary.json
Phase 2: CRAWL — Playwright visits URLs (count from CONFIG.md) with QM cookie, captures JS errors
Phase 3: ANALYSIS — score every URL RED/AMBER/GREEN, show worst offenders, ASK USER
Phase 4: FIX LOOP — auto-fix RED URLs one at a time, commit per root cause, re-audit
Phase 5: REVIEW — spawn 8 parallel review agents
Phase 6: SUMMARY — push branch, show before/after table
```

### Key files
| File | Role |
|------|------|
| `skills/site-audit/SKILL.md` | The skill — what Claude reads |
| `skills/site-audit/CONFIG.md` | Thresholds, paths, URLs, cookie |
| `skills/site-audit/audit.py` | Python log aggregator (runs BEFORE Claude reads) |
| `skills/site-audit/references/playwright-crawl.md` | How to run the crawl |
| `tests/playwright/src/crawl.spec.ts` | Playwright crawl script |

### What gets scored RED
Tune thresholds in CONFIG.md. Defaults: queries > 100, dupes > 5, any slow query > 0.05s, TTFB > 500ms, load > 3s, any PHP error, any JS error, memory > 64MB, peak > 128MB.

### Parameters
| Param | Default | Effect |
|-------|---------|--------|
| `max-urls` | from CONFIG.md | How many pages to crawl (no default — set per site) |
| `skip-crawl` | false | Use cached QM data |
| `skip-review` | false | Skip the 8-agent review |
| `auto-fix` | true | Auto-fix REDs or just report |
| `branch` | ask | ask / new / main |

### Example invocations
```
/site-audit                              # full audit with CONFIG.md settings
/site-audit max-urls=50                  # override URL cap from config
/site-audit skip-crawl=true             # use existing QM data only
/site-audit auto-fix=false              # analyse only, don't fix
/site-audit skip-review=true            # fix without review
```

### What a reviewer (Opus) should check
1. **audit-summary.json** — does RED/AMBER/GREEN scoring match thresholds?
2. **Each fix commit** — one root cause per commit? Commit cites URL + metric?
3. **Summary table complete** — bug, file, fix, and how-to-test columns filled for every fix?
4. **No regressions** — any previously green URL turned red?
5. **Iteration cap** — did the loop stop cleanly or hit the cap (from CONFIG.md)?
6. **Escalation respected** — no fixes to auth, payments, or plugin core?

---

## Skill 2: `fix-bugherd` — Bug Fix Pipeline (dual-mode)

### What it does
Fixes bugs end-to-end. Two modes: connected to BugHerd (reads task, updates status) or standalone (just describe the bug).

### Triggers
`/fix-bugherd BH-123`, `/fix-bugherd <bug description>`, "fix this bug"

### How it works (8 phases)

```
MODE DETECTION — BugHerd task ID? Or plain text description?

Phase 0: BRANCH — ask user: main / new branch / worktree
Phase 1: READ — BugHerd API fetch OR parse plain text description
Phase 2: RESEARCH — find root cause, draw ASCII diagram, ASK USER to confirm
Phase 3: PLAN — simple fix (1-2 files) or complex (invoke develop-team)
Phase 4: IMPLEMENT — apply fix, lint check
Phase 5: REVIEW — 8 parallel review agents (skippable)
Phase 6: COMMIT — conventional commit, push to branch
Phase 7: HANDOFF — BugHerd mode only: update task status, post comment
Phase 8: SUMMARY — what was done, commit hash, phase tracker
```

### Key files
| File | Role |
|------|------|
| `skills/fix-bugherd/SKILL.md` | The skill — what Claude reads |
| `skills/fix-bugherd/CONFIG.md` | API key, project IDs, status mappings |

### Parameters
| Param | Default | Effect |
|-------|---------|--------|
| `branch` | ask | ask / main / new / worktree |
| `skip-review` | false | Skip 8-agent review |
| `auto-commit` | true | Auto-commit after fix |

### Example invocations
```
/fix-bugherd BH-123                           # BugHerd task
/fix-bugherd BH-123 branch=new                # new branch
/fix-bugherd mobile nav broken on iOS         # plain text mode
/fix-bugherd the contact form emails are blank since the last update
/fix-bugherd fix 500 error on the blog page branch=main skip-review=true
```

### Plain text mode details
No BugHerd task needed. Claude:
1. Parses your description for: what's broken, where, when, expected behavior
2. Asks clarifying questions if vague
3. Proceeds through same research→fix→review→commit pipeline
4. Skips Phase 7 (no BugHerd to update)

### What a reviewer (Opus) should check
1. **Mode detection correct** — BugHerd task → API mode. Text → plain text mode.
2. **Phase 2 user gate** — did Claude stop and wait for confirmation?
3. **ASCII diagram exists** — every Phase 2 must include one
4. **Review agents spanned** — unless skip-review=true, 3-5 agents must run
5. **BugHerd handoff** — status updated, comment posted (BugHerd mode only)
6. **Commit format** — `fix: <desc> [BH-{id}]` for BugHerd, `fix: <desc>` for plain text
7. **Branch naming** — `fix/BH-{id}` or `fix/{slug-from-description}`

---

## Dependencies Both Skills Call

| Skill | Used by | Purpose |
|-------|---------|---------|
| `review-fix` | Both | 8 parallel code reviewers |
| `develop-team` | fix-bugherd | Complex fix planning (3+ files) |
| `review-team` | site-audit | Large review escalation |

## Shared Infrastructure

| Asset | Purpose |
|-------|---------|
| `skills/site-audit/audit.py` | Log aggregation script |
| `wp-content/qm-output/` | QM JSON profiles per URL |
| `tests/playwright/` | Playwright crawl project |
| `wp-content/debug.log` | PHP error log (when WP_DEBUG_LOG on) |

## Quick Comparison

| | site-audit | fix-bugherd |
|---|---|---|
| Scope | Whole site (configurable count) | Single bug |
| Input | sitemap or URL list | BugHerd task or text |
| External | QM + Playwright + Python | BugHerd API (optional) |
| Fixes | Many, autonomous | One, user-gated |
| Output | audit-summary.json | Commit + BugHerd comment |
| Review | review-fix or review-team | review-fix |
| Branch | `audit/{YYYY-MM-DD}-{summary}` | `fix/{YYYY-MM-DD}-{slug}` |
