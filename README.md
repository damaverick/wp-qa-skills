# WP QA Skills

Claude Code skills for WordPress QA. Drop into any project's `skills/` folder — Claude auto-discovers them. Each skill is a pipeline; you approve at key gates.

> **These run in Claude Code CLI**, not in GitHub. See [GitHub limitations](#github-limitations).

## What it audits

| Signal | Source | Captured by |
|--------|--------|-------------|
| PHP errors and warnings | `wp-content/debug.log` | WordPress `WP_DEBUG_LOG` |
| MySQL slow queries and duplicates | per-page query profiles | Query Monitor plugin + mu-plugin |
| JavaScript console errors | browser runtime | Playwright crawl |
| Page load time and TTFB | HTTP timing | Playwright crawl |
| Memory usage per page | PHP runtime | Query Monitor mu-plugin |

**How it works:** Playwright visits every page as a logged-in user, triggering the QM mu-plugin to write a JSON profile per URL (DB queries, timings, memory, PHP errors). A Python script aggregates all profiles into `audit-summary.json`, scores every URL RED/AMBER/GREEN, and Claude fixes the worst offenders.

## Skills

| Skill | Invoke | What it does |
|-------|--------|--------------|
| `site-audit` | `/site-audit` | Crawl site, score pages RED/AMBER/GREEN — auto-fix worst offenders, open PR |
| `fix-bugherd` | `/fix-bugherd BH-123` or `/fix-bugherd <description>` | Fix bugs end-to-end — BugHerd ticket or plain text, no ticket needed |
| `review-fix` | Auto-called | 8 parallel reviewers, auto-fix loop |
| `review-team` | `/review-team <PR>` | Adversarial PR review with Devil's Advocate |
| `develop-team` | Auto-called | Multi-agent implementation for complex fixes |

`review-fix` and `develop-team` are called automatically by the two main skills — no need to invoke them directly.

## Setup

**From your WordPress project root** (the folder that contains `wp-content/`):

```bash
bash <(curl -s https://raw.githubusercontent.com/damaverick/wp-qa-skills/main/install.sh)
```

The installer copies files then immediately launches an interactive setup wizard. The wizard:

- Asks your site URL, max URLs to crawl, and fix loop settings → writes `CONFIG.md` automatically
- Installs the QM mu-plugin and creates `wp-content/qm-output/` (required before Playwright runs)
- Installs Playwright dependencies (`npm install + playwright install chromium`)
- Checks `WP_DEBUG` / `WP_DEBUG_LOG` in `wp-config.php` and warns if missing
- Configures git remote
- Optionally adds BugHerd API key

The wizard handles everything. **You do not need to manually edit any config files.**

### After setup — install Query Monitor plugin

The QM plugin must be active in WordPress before you run an audit:

```bash
# Via WP-CLI (fastest):
wp plugin install query-monitor --activate

# Or: WordPress Admin > Plugins > Add New > search "Query Monitor" > Install > Activate
```

Also enable debug logging if not already set:

```bash
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
```

### Verify everything is ready

```bash
python3 scripts/preflight.py
```

This checks all prerequisites and prints `[OK]` / `[WARN]` / `[FAIL]` for each one. Fix any `[FAIL]` items before running `/site-audit`.

### Skip the wizard (CI / automation)

```bash
bash <(curl -s .../install.sh) --no-setup
# Then configure manually:
python3 setup.py
```

Commit `skills/` to git — the whole team gets them automatically.

## Commands

```bash
/site-audit                        # full audit with CONFIG.md settings
/site-audit max-urls=50            # override URL cap
/site-audit skip-crawl=true        # use cached QM data, skip Playwright
/site-audit auto-fix=false         # analyse only, no fixes
/site-audit skip-review=true       # fix without the 8-agent review

/fix-bugherd BH-123                # fix a BugHerd task
/fix-bugherd BH-123 branch=new     # fix on a new branch
/fix-bugherd mobile nav broken on iOS Safari   # plain text — no ticket needed
/fix-bugherd the contact form emails are blank since the last update

/review-team 123                   # adversarial review of PR #123
/review-fix                        # review your local diff before pushing
```

## Requirements

### All skills

| Tool | Install |
|------|---------|
| **Claude Code** | `npm install -g @anthropic-ai/claude-code` |
| **`gh` CLI** | `brew install gh` then `gh auth login` |

### site-audit only

| Tool | Install |
|------|---------|
| **Python 3** | Pre-installed on macOS/Linux. Check: `python3 --version` |
| **Query Monitor plugin** | WordPress admin > Plugins > Add New > "Query Monitor" |
| **QM mu-plugin** | `cp skills/site-audit/mu-plugins/qm-perf-capture.php wp-content/mu-plugins/` |
| **Playwright** | `cd tests/playwright && npm install && npx playwright install chromium` |
| **WP_DEBUG_LOG** | `wp config set WP_DEBUG_LOG true --raw` |

### fix-bugherd only

| Tool | Notes |
|------|-------|
| **BugHerd API key** | Optional. BugHerd > Settings > API Keys — add to `skills/fix-bugherd/CONFIG.md`. Without it, plain text mode still works. |

## How site-audit works (the data pipeline)

The audit needs per-page performance data. Here's the flow:

```
1. QUERY MONITOR PLUGIN
   Active on your WordPress site.
   The mu-plugin adds a JSON output mode — writes per-page profiles as .json files.

2. MU-PLUGIN (qm-perf-capture.php)
   At shutdown, if the request has a qm_auth cookie, dumps everything into JSON:
     - All DB queries (count, duplicates, slow queries, pattern histogram)
     - PHP errors and warnings collected during the request
     - Memory usage, page generation time
   Output: wp-content/qm-output/<timestamp>_<hash>.json

3. PLAYWRIGHT CRAWL
   Visits every front-end URL (up to your max_urls cap).
   Sets the qm_auth cookie — triggers the mu-plugin.
   Also captures JS console errors.
   Output: one QM JSON file per URL + js-errors.json

4. audit.py AGGREGATOR
   Reads all QM JSON files + PHP debug.log + PHP error log.
   Scores every URL RED/AMBER/GREEN against thresholds in CONFIG.md.
   Output: audit-summary.json (~5K tokens — Claude reads this, not raw logs)

5. CLAUDE FIX LOOP
   For each RED URL: research root cause → edit code → commit → re-audit.
   Stops when zero REDs or iteration cap hit.
```

## Log files

| File | Generated by | How to enable | Contains |
|------|-------------|---------------|----------|
| `wp-content/qm-output/*.json` | QM mu-plugin | Playwright crawl must run | DB queries, timings, memory, PHP errors per URL |
| `wp-content/qm-output/js-errors.json` | Playwright crawl | Auto during crawl | JS console errors per URL |
| `wp-content/debug.log` | WordPress | `WP_DEBUG_LOG=true` in wp-config.php | PHP errors, warnings, notices |
| `logs/php-error.log` | Server | Auto on Local by Flywheel | PHP error log |
| `logs/access.log` | Server | Auto on Local by Flywheel | HTTP access log |

QM JSON files are the primary data source. Without them you get PHP/JS errors but no query counts or slow query data.

## Where skills live

- `.claude/skills/` — project-level skills, auto-discovered by Claude at session start
- `~/.claude/skills/` — global across all projects

## Team review workflow

The typical workflow for using these skills with GitHub PRs:

```
Dev pushes branch → creates PR on GitHub
                              ↓
Tech lead runs locally:  /review-team 123
                         (fetches PR diff from GitHub, runs analysis locally)
                              ↓
Posts findings as PR comment on GitHub
```

`review-team` needs a PR number — the number GitHub assigns automatically when a PR is created. Find it in the URL (`github.com/org/repo/pull/123`) or in the PR list.

**Simpler day-to-day workflow** — run `review-fix` before every push, no PR number needed:

```bash
# Before pushing your branch:
/review-fix          # reviews your local diff, auto-fixes issues, commits
git push
```

## GitHub limitations

These are **Claude Code CLI skills** — they run in your terminal, not in GitHub issues or PRs.

| Skill | Runs | Notes |
|-------|------|-------|
| `/site-audit` | Local only | Needs live WP site + QM plugin |
| `/fix-bugherd` | Local only | Needs WP codebase access |
| `/review-fix` | Local only | Run before pushing |
| `/review-team` | Local, reviews GitHub PRs | Fetches PR diff via `gh` CLI, analysis runs on your machine |
| `@claude` in PR comments | GitHub only | Basic tasks only — does not run these skill pipelines |

The [`anthropic/claude-code-action`](https://github.com/anthropic/claude-code-action) GitHub Action lets you tag `@claude` in PR comments for simple one-shot tasks ("fix this typo", "explain this function"), but it cannot run multi-agent pipelines like review-team or site-audit.

## Companion skills & plugins

These are not in this repo but work alongside it — Claude picks them up automatically from global scope when installed. Team members without them will still get the full audit/fix pipeline, just without the extra WP-specific context.

### WordPress agent skills

Helps Claude understand WP coding patterns, hooks, WP-CLI, block development, REST API, and more. Used automatically when `fix-bugherd` or `site-audit` touches WordPress code.

```bash
git clone https://github.com/WordPress/agent-skills.git /tmp/wp-agent-skills
cp -r /tmp/wp-agent-skills/skills/* ~/.claude/skills/
```

### Superpowers plugin

Improves Claude's reasoning during every phase — systematic debugging, verification before committing, parallel agent dispatch, and more.

```bash
claude plugins install superpowers
```

Both install globally — available in every project automatically, no per-project setup needed.

## Conventions

- Branches: `fix/YYYY-MM-DD-{slug}` or `audit/YYYY-MM-DD-{summary}`
- Commits: `fix:` prefix with a fix table in the body
- PRs: fix table + review checklist

## Customise

Each skill has a `CONFIG.md`. Edit before first use:

- `skills/site-audit/CONFIG.md` — site URL, thresholds (query count, TTFB, slow query time, memory), URL scope, log paths, QM cookie value
- `skills/fix-bugherd/CONFIG.md` — BugHerd API key, project IDs, team member IDs

Override thresholds on the command line:

```bash
/site-audit max-urls=50
/site-audit auto-fix=false       # analyse only, no fixes
/site-audit skip-crawl=true      # use cached QM data
/fix-bugherd BH-123 branch=main skip-review=true
```
