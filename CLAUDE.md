# wp-qa-skills

Claude Code skills for WordPress QA: site auditing, bug fixing, and code review.

## Skills

- `skills/site-audit/` — Crawl site, score RED/AMBER/GREEN (PHP errors, JS errors, slow SQL), auto-fix, PR
- `skills/fix-bugherd/` — Fix bugs from BugHerd ticket or plain text description
- `skills/review-fix/` — 8 parallel reviewers with auto-fix loop (called automatically)
- `skills/review-team/` — Adversarial PR review with Devil's Advocate
- `skills/develop-team/` — Multi-agent implementation for complex fixes (called automatically)

## Conventions

- Branches: `fix/{YYYY-MM-DD}-{slug}`, `audit/{YYYY-MM-DD}-{summary}`
- Commits: `fix:` prefix with fix table in body
- PRs: fix table + review checklist
