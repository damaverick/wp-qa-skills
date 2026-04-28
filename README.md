# WP QA Skills

Claude Code skills for WordPress quality assurance — automated site auditing, bug fixing, and code review.

## What this is

A set of reusable [Claude Code](https://claude.ai/claude-code) skills. Claude Code auto-discovers them when you drop them into your WordPress project. Each skill is a prompt + config — Claude runs the pipeline, you approve at key gates.

## Prerequisites

| Tool | Required for | Install |
|------|-------------|---------|
| **Claude Code** | All skills | `npm install -g @anthropic-ai/claude-code` |
| **Python 3** | `site-audit` (audit.py) | `python3 --version` — already on macOS/Linux |
| **Node.js + Playwright** | `site-audit` (crawl) | `cd tests/playwright && npm install` |
| **`gh` CLI** | PR creation in both audit + fix | `brew install gh` then `gh auth login` |
| **`wp` CLI** | WordPress operations | Already in most WP installs, or install via Homebrew |

## Install

**Copy skills into your WordPress project:**

```bash
git clone https://github.com/damaverick/wp-qa-skills.git /tmp/wp-qa-skills

# Pick the skills you need:
cp -r /tmp/wp-qa-skills/skills/site-audit   your-project/skills/site-audit
cp -r /tmp/wp-qa-skills/skills/fix-bugherd  your-project/skills/fix-bugherd
cp -r /tmp/wp-qa-skills/skills/review-fix   your-project/skills/review-fix
cp -r /tmp/wp-qa-skills/skills/review-team  your-project/skills/review-team
cp -r /tmp/wp-qa-skills/skills/develop-team your-project/skills/develop-team

# Optional: Playwright crawl for site-audit
cp -r /tmp/wp-qa-skills/tests               your-project/tests
```

**Where skills live:**

- `skills/` at your **project root** — Claude auto-discovers them. This is the right place for shared, project-specific skills. ✅
- `.claude/skills/` at your **project root** — also works, but meant for personal skills. Works too. ✅
- `~/.claude/skills/` — global, available across all projects. OK but not recommended for shared team use.

The `skills/` directory is the standard — it gets committed to git, your team gets the same skills.

## Skills

| Skill | Purpose | Key command |
|-------|---------|-------------|
| `site-audit` | Crawl site, score RED/AMBER/GREEN, auto-fix loop, open PR | `/site-audit` |
| `fix-bugherd` | Fix bugs from BugHerd ticket or plain text description | `/fix-bugherd BH-123` |
| `review-fix` | 8 parallel reviewers, auto-fix quick issues | Called by above skills |
| `review-team` | Team-based review with gate enforcement | Called by above skills |
| `develop-team` | Multi-agent dev for complex fixes | Called by fix-bugherd |

## Quick start

### Run a site audit

```bash
# 1. Edit thresholds for your site
vim skills/site-audit/CONFIG.md

# 2. Run the audit
/site-audit

# 3. Claude crawls your site, scores every URL, shows REDs
# 4. You confirm, Claude auto-fixes, opens a PR
```

### Fix a BugHerd task

```bash
# 1. Set your BugHerd API key
vim skills/fix-bugherd/CONFIG.md

# 2. Fix a task
/fix-bugherd BH-123

# 3. Claude reads the task, researches, fixes, pushes, updates BugHerd
```

### Fix a bug without a ticket

```bash
/fix-bugherd the mobile nav hamburger menu doesn't open on iOS Safari
```

## How it works

1. You type `/fix-bugherd BH-123` in Claude Code
2. Claude loads `skills/fix-bugherd/SKILL.md` — the orchestration plan
3. Claude loads `skills/fix-bugherd/CONFIG.md` — your API keys and settings
4. Claude runs through phases: read bug → research → fix → review → commit → push
5. You approve at key gates (before push, after research)
6. PR is opened, BugHerd updated

No setup beyond copying the files and filling in CONFIG.md.

## Customise

Each skill has its own `CONFIG.md`. Edit these before first use:

- `skills/site-audit/CONFIG.md` — thresholds, URL scope, log paths
- `skills/fix-bugherd/CONFIG.md` — BugHerd API key, project IDs, team members

Thresholds are also tunable via JSON:

```bash
python3 skills/site-audit/audit.py --thresholds my-thresholds.json
```

## Feedback

Found a bug or have an idea? Open an issue or PR on this repo.
