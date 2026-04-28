# Playwright QM Crawl

## Overview

The Playwright crawl visits up to 200 front-end URLs from `wp-sitemap.xml`, collecting QM JSON data and JS errors. Runs logged-out with a QM auth cookie so the QM JSON dispatcher writes per-page profiles.

## Prerequisites

```bash
cd tests/playwright
npm install  # if not already installed
npx playwright install chromium
```

## Running the Crawl

```bash
cd tests/playwright
npx playwright test src/crawl.spec.ts --reporter=list
```

Output:
- `wp-content/qm-output/*.json` — one QM profile per URL
- `wp-content/qm-output/js-errors.json` — JS errors per URL

## QM Auth Cookie

The mu-plugin checks for `QM_COOKIE` (name: `qm_auth`, any truthy value). The crawl sets this cookie before each page visit.

## JS Error Capture

Two types captured per page:
- `pageerror` — uncaught JS exceptions
- `console.error` — `console.error()` calls

Output format (`js-errors.json`):
```json
[
  {
    "url": "/some-page/",
    "errors": [
      {"message": "Uncaught TypeError: ...", "type": "pageerror"},
      {"message": "Failed to load resource", "type": "console.error"}
    ]
  }
]
```

## URL Collection

- Reads `wp-sitemap.xml` + all sub-sitemaps
- Deduplicates and caps at MAX_URLS (200)
- Prioritizes homepage + top-level pages first

## Config

Edit `tests/playwright/src/crawl.spec.ts` constants or use environment variables:

| Constant | Env Var | Default | Description |
|----------|---------|---------|-------------|
| `BASE` | `SITE_BASE` | `https://mysite.local` | Site URL |
| `MAX_URLS` | `MAX_URLS` | `200` | URLs to crawl |
| `QM_COOKIE.value` | `QM_COOKIE_VALUE` | `1` | Must match mu-plugin |

```bash
# Example: custom site, 100 URLs
SITE_BASE=https://myproject.local MAX_URLS=100 npx playwright test src/crawl.spec.ts --reporter=list
```

Domain for QM cookie auto-extracted from BASE URL.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| No QM JSON files | Check mu-plugin active, QM_COOKIE value matches |
| All pages 500 | Check W3 Total Cache disabled, site accessible |
| JS errors not captured | Check `js-errors.json` written in `test.afterAll` |
| Crawl takes too long | Reduce MAX_URLS, increase timeout |
