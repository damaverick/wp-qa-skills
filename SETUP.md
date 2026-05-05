# Team Setup Guide

Step-by-step for new team members. Takes about 10 minutes.

## Step 1 — Install Claude Code

```bash
npm install -g @anthropic-ai/claude-code
```

First time: run `claude` and log in with your Anthropic account.

## Step 2 — Install the GitHub CLI

```bash
brew install gh
gh auth login
```

## Step 3 — Add skills to your project

Copy the skills folder into your WordPress project:

```bash
git clone https://github.com/YOUR_ORG/wp-qa-skills.git /tmp/wp-qa-skills
cp -r /tmp/wp-qa-skills/skills /path/to/your/project/skills
```

If your project already has a `skills/` folder (team member set it up), skip this — skills are already there.

## Step 4 — For site-audit: install the QM mu-plugin

```bash
cp skills/site-audit/mu-plugins/qm-perf-capture.php wp-content/mu-plugins/
```

Then install **Query Monitor** from WordPress admin > Plugins > Add New.

Enable debug logging:

```bash
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG true --raw
```

Install Playwright (for JS error detection):

```bash
cd tests/playwright && npm install && npx playwright install chromium
```

## Step 5 — Fill in config

Open `skills/site-audit/CONFIG.md` and set:
- Your site URL
- QM auth cookie value (get from browser DevTools after logging in to WP admin)
- How many URLs to crawl (`max_urls`)

For BugHerd (optional — only needed if using `/fix-bugherd BH-123`):

Open `skills/fix-bugherd/CONFIG.md` and add your BugHerd API key.

## Step 6 — Run your first skill

Open your project in Claude Code:

```bash
cd /path/to/your/wordpress/project
claude
```

Then type in the chat:

```
/site-audit
```

or to fix a bug without a ticket:

```
/fix-bugherd the contact form is not sending emails
```

---

## Common invocations

```bash
# Full site audit
/site-audit

# Audit without auto-fixing (just report)
/site-audit auto-fix=false

# Fix a BugHerd task
/fix-bugherd BH-123

# Fix a bug by description (no ticket needed)
/fix-bugherd the mobile menu doesn't open on iOS

# Review a PR
/review-team 456
```

## Troubleshooting

**`/site-audit` says no QM JSON files found**
Run the Playwright crawl first, or check the QM cookie value in CONFIG.md is correct.

**`/fix-bugherd BH-123` says API key not configured**
Add your BugHerd API key to `skills/fix-bugherd/CONFIG.md`. Or use plain text mode: `/fix-bugherd <description>`.

**Playwright not found**
Run `cd tests/playwright && npm install && npx playwright install chromium`.

**Claude Code doesn't recognise `/site-audit`**
Make sure `skills/site-audit/SKILL.md` exists in your project root's `skills/` folder.
