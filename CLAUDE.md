# wp-qa-skills

Repository of reusable Claude Code skills for WordPress QA: automated site auditing, bug fixing, and code review.

## Skills in this repo

- `skills/site-audit/` — Autonomous site audit pipeline (crawl, score, fix, PR)
- `skills/fix-bugherd/` — Dual-mode bug fixing (BugHerd API + plain text)
- `skills/review-fix/` — 8 parallel reviewers with auto-fix
- `skills/review-team/` — Team-based code review
- `skills/develop-team/` — Multi-agent complex development

## To use

Copy the skill(s) you want into your project's `skills/` directory. Each skill has its own `CONFIG.md` — fill in before first run.

## Conventions

- Branch naming: `fix/{YYYY-MM-DD}-{slug}`, `audit/{YYYY-MM-DD}-{summary}`
- Commit messages: `fix:` prefix with fix table in body
- PR format: fix table + review checklist
