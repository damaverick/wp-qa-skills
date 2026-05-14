# Playwright QM Crawl

## Overview

Visits front-end URLs from `wp-sitemap.xml`, collecting QM JSON profiles and JS errors. Simulates human interaction (mouse movement, scroll) to catch interaction-triggered errors. Categorizes each issue automatically.

## Prerequisites

```bash
cd tests/playwright
npm install
npx playwright install chromium
```

`package.json` devDependencies: `@playwright/test`, `@types/node`.
`tsconfig.json` must include `"types": ["node"]`.

## Running the Crawl

**Full production crawl:**
```bash
cd tests/playwright
SITE_BASE=https://mysite.com npx playwright test src/crawl.spec.ts --reporter=list
```

**Single page (target specific URL):**
```bash
cd tests/playwright
SITE_BASE=https://mysite.com \
  CRAWL_URLS=https://mysite.com/some-page/ \
  npx playwright test src/crawl.spec.ts --reporter=list
```

**Multiple specific pages:**
```bash
SITE_BASE=https://mysite.com \
  CRAWL_URLS=https://mysite.com/page-1/,https://mysite.com/page-2/ \
  npx playwright test src/crawl.spec.ts --reporter=list
```

**Staging with HTTP basic auth:**
```bash
cd tests/playwright
SITE_BASE=https://staging.example.com \
  HTTP_USER=username HTTP_PASS=password \
  npx playwright test src/crawl.spec.ts --reporter=list
```

Output:
- `wp-content/qm-output/*.json` — QM profile per URL
- `wp-content/qm-output/js-errors.json` — full results with categories

## Environment Variables

| Env Var | Default | Description |
|---------|---------|-------------|
| `SITE_BASE` | `https://ibbotson.local` | Site base URL |
| `MAX_URLS` | `200` | Sitemap URL cap |
| `QM_COOKIE_VALUE` | `1` | Must match mu-plugin |
| `HTTP_USER` | — | HTTP basic auth username (staging) |
| `HTTP_PASS` | — | HTTP basic auth password (staging) |
| `CRAWL_URLS` | — | Comma-separated URLs — overrides sitemap entirely |

## What It Captures Per Page

| Data | Playwright Event | Notes |
|------|-----------------|-------|
| JS runtime exceptions | `pageerror` | Uncaught errors |
| Console errors | `console` (type=error) | CORS warnings, deprecations |
| Failed network requests | `requestfailed` | CORS blocks, timeouts, 404s |
| HTTP error responses | `response` (status ≥ 400) | 4xx/5xx on sub-resources |
| Page load time | — | ms from navigation start |
| HTTP status | — | Page-level response code |

## Human Interaction Simulation

After each page loads, the crawl:
1. Moves mouse in a Z-pattern across the viewport (catches hover-triggered errors)
2. Scrolls down 300px then back up (triggers scroll listeners, lazy-load)
3. Waits 1s for deferred errors to fire

This catches interaction-triggered errors missed by static crawls (e.g. component init on hover, mousemove-dependent JS).

## Error Categories

Each issue classified automatically:

| Category | Triggers |
|----------|---------|
| `cors` | CORS/Access-Control headers, `fonts.gstatic.com` ERR_FAILED |
| `runtime` | Uncaught JS exceptions, component init failures |
| `network` | 4xx/5xx, timeouts, ERR_FAILED (non-CORS) |
| `security` | Mixed content, insecure resource warnings |
| `deprecation` | "deprecated", "will be removed" messages |

## Output Format (`js-errors.json`)

```json
[
  {
    "url": "/some-page/",
    "httpStatus": 200,
    "loadTimeMs": 1240,
    "errors": [
      {"message": "Cannot read properties of undefined (reading 'get')", "type": "pageerror", "category": "runtime"},
      {"message": "Access to font ... blocked by CORS policy", "type": "console.error", "category": "cors"}
    ],
    "failedRequests": [
      {"url": "https://fonts.gstatic.com/...", "resourceType": "font", "status": 0, "failure": "net::ERR_FAILED", "category": "cors"}
    ],
    "summary": {
      "totalErrors": 2,
      "totalFailedRequests": 1,
      "cors": 2,
      "runtime": 1,
      "network": 0,
      "security": 0,
      "deprecation": 0
    }
  }
]
```

## URL Collection

- Reads `wp-sitemap.xml` + all sub-sitemaps
- Deduplicates, sorts by path depth (shallow first)
- Caps at `MAX_URLS`
- Homepage always included
- Override entirely with `CRAWL_URLS` env var

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| No QM JSON files | Check mu-plugin active, QM_COOKIE value matches |
| All pages 500 | Check caching plugin disabled, site accessible |
| JS errors not captured | Check `js-errors.json` written in `test.afterAll` |
| Crawl takes too long | Reduce MAX_URLS or use CRAWL_URLS for single page |
| Interaction errors missing | Ensure `networkidle` wait completes before mouse simulation |
| Staging returns 401 | Pass `HTTP_USER` and `HTTP_PASS` env vars |
