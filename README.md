# WP QA Skills

Claude Code skills for WordPress site auditing and bug fixing.

## Skills

| Skill | Purpose |
|-------|---------|
| `site-audit` | Autonomous site audit: crawl, score RED/AMBER/GREEN, fix loop, PR |
| `fix-bugherd` | Dual-mode bug fixing: BugHerd ticket or plain text description |
| `review-fix` | 8 parallel code reviewers, auto-fix quick items |
| `review-team` | Team-based code review workflow |
| `develop-team` | Multi-agent development for complex tasks |

## Setup

1. Copy skills to your project's `skills/` directory
2. Copy `tests/playwright/` for the crawl (optional, site-audit only)
3. Run `cd tests/playwright && npm install` if using crawl
4. Edit `CONFIG.md` in each skill before first use

## Requirements

- Claude Code
- Python 3 (for audit.py)
- Node.js + Playwright (for crawl)
- `gh` CLI (for PR creation)
- `wp` CLI (for WordPress operations)
