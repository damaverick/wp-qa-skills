import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// ── Config ──────────────────────────────────────────────────────────────────
const BASE = process.env.SITE_BASE || 'https://mysite.local';
const MAX_URLS = parseInt(process.env.MAX_URLS || '200', 10);
const QM_COOKIE = {
  name: 'qm_auth',
  value: process.env.QM_COOKIE_VALUE || '1',
  domain: new URL(BASE).hostname,
  path: '/',
};

// HTTP Basic Auth for staging environments
const HTTP_USER = process.env.HTTP_USER || '';
const HTTP_PASS = process.env.HTTP_PASS || '';

// Output paths (relative to WP root)
const WP_ROOT = path.resolve(__dirname, '..', '..', '..');
const QM_OUTPUT = path.join(WP_ROOT, 'wp-content', 'qm-output');
const JS_ERRORS_FILE = path.join(QM_OUTPUT, 'js-errors.json');

// ── Types ───────────────────────────────────────────────────────────────────

type ErrorCategory = 'runtime' | 'cors' | 'network' | 'security' | 'deprecation' | 'other';

interface JsError {
  message: string;
  type: 'pageerror' | 'console.error';
  category: ErrorCategory;
  source?: string;
}

interface FailedRequest {
  url: string;
  resourceType: string;
  status: number;
  statusText: string;
  failure: string;
  category: ErrorCategory;
}

interface UrlAuditResult {
  url: string;
  httpStatus: number;
  loadTimeMs: number;
  errors: JsError[];
  failedRequests: FailedRequest[];
  summary: {
    totalErrors: number;
    totalFailedRequests: number;
    cors: number;
    runtime: number;
    network: number;
    security: number;
    deprecation: number;
  };
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function categorizeConsoleError(message: string): ErrorCategory {
  const lower = message.toLowerCase();
  if (lower.includes('cors') || lower.includes('access-control-allow-origin') || lower.includes('cross-origin')) {
    return 'cors';
  }
  if (lower.includes('failed to load resource') || lower.includes('net::err_')) {
    return 'network';
  }
  if (lower.includes('mixed content') || lower.includes('insecure') || lower.includes('blocked')) {
    return 'security';
  }
  if (lower.includes('deprecated') || lower.includes('will be removed')) {
    return 'deprecation';
  }
  return 'runtime';
}

function categorizeFailedRequest(failure: string, url: string, status: number): ErrorCategory {
  const lower = failure.toLowerCase();
  if (lower.includes('cors') || lower.includes('access-control')) {
    return 'cors';
  }
  if (status === 0 && (lower.includes('net::err_failed') || lower.includes('blocked'))) {
    // Status 0 + ERR_FAILED often means CORS block at network level
    if (url.includes('fonts.gstatic.com') || url.includes('fonts.googleapis.com')) {
      return 'cors';
    }
    return 'network';
  }
  if (status >= 400 && status < 500) return 'network';
  if (status >= 500) return 'network';
  return 'network';
}

async function fetchSitemapUrls(baseUrl: string): Promise<string[]> {
  const urls: string[] = [];

  const sitemapUrl = `${baseUrl}/wp-sitemap.xml`;
  console.log(`Fetching sitemap: ${sitemapUrl}`);

  const fetchOpts: RequestInit = {
    // @ts-ignore — Node.js specific option for self-signed certs
    dispatcher: undefined,
  };

  // Add basic auth header if configured
  if (HTTP_USER && HTTP_PASS) {
    const authHeader = Buffer.from(`${HTTP_USER}:${HTTP_PASS}`).toString('base64');
    fetchOpts.headers = { 'Authorization': `Basic ${authHeader}` };
  }

  try {
    const { Agent } = await import('undici');
    const agent = new Agent({ connect: { rejectUnauthorized: false } });
    // @ts-ignore
    fetchOpts.dispatcher = agent;
  } catch {
    // undici not available, rely on NODE_TLS_REJECT_UNAUTHORIZED env var
  }

  try {
    const res = await fetch(sitemapUrl, fetchOpts);
    if (!res.ok) {
      console.warn(`Sitemap returned ${res.status}, falling back to manual discovery`);
      return [];
    }
    const xml = await res.text();

    const subSitemapMatches = xml.matchAll(/<loc>(.*?)<\/loc>/g);
    const subSitemapUrls: string[] = [];
    for (const m of subSitemapMatches) {
      subSitemapUrls.push(m[1]);
    }

    for (const subUrl of subSitemapUrls) {
      try {
        const subRes = await fetch(subUrl, fetchOpts);
        if (!subRes.ok) continue;
        const subXml = await subRes.text();
        const pageMatches = subXml.matchAll(/<loc>(.*?)<\/loc>/g);
        for (const pm of pageMatches) {
          urls.push(pm[1]);
        }
      } catch {
        console.warn(`Failed to fetch sub-sitemap: ${subUrl}`);
      }
    }
  } catch (err: any) {
    console.warn(`Sitemap fetch failed: ${err.message}`);
  }

  if (!urls.includes(baseUrl) && !urls.includes(baseUrl + '/')) {
    urls.unshift(baseUrl + '/');
  }

  const unique = [...new Set(urls)];

  unique.sort((a, b) => {
    const depthA = new URL(a).pathname.split('/').filter(Boolean).length;
    const depthB = new URL(b).pathname.split('/').filter(Boolean).length;
    return depthA - depthB;
  });

  return unique.slice(0, MAX_URLS);
}

// ── Test ────────────────────────────────────────────────────────────────────

const allResults: UrlAuditResult[] = [];

test.describe('Site Audit Crawl', () => {
  let urls: string[] = [];

  test.beforeAll(async () => {
    if (!fs.existsSync(QM_OUTPUT)) {
      fs.mkdirSync(QM_OUTPUT, { recursive: true });
    }

    // CRAWL_URLS env var overrides sitemap — comma-separated absolute URLs
    const crawlOverride = process.env.CRAWL_URLS;
    if (crawlOverride) {
      urls = crawlOverride.split(',').map((u: string) => u.trim()).filter(Boolean);
      console.log(`\nCrawling ${urls.length} URLs (override)\n`);
    } else {
      urls = await fetchSitemapUrls(BASE);
      console.log(`\nCrawling ${urls.length} URLs (max ${MAX_URLS})\n`);
    }

    if (urls.length === 0) {
      console.warn('No URLs found from sitemap. Add URLs manually or check site accessibility.');
    }
  });

  test('crawl all pages for QM data and JS errors', async ({ browser }) => {
    const contextOpts: any = { ignoreHTTPSErrors: true };
    if (HTTP_USER && HTTP_PASS) {
      contextOpts.httpCredentials = { username: HTTP_USER, password: HTTP_PASS };
    }
    const context = await browser.newContext(contextOpts);

    await context.addCookies([QM_COOKIE]);

    const page = await context.newPage();

    for (let i = 0; i < urls.length; i++) {
      const url = urls[i];
      const urlPath = new URL(url).pathname || '/';
      const progress = `[${i + 1}/${urls.length}]`;

      const pageErrors: JsError[] = [];
      const failedRequests: FailedRequest[] = [];

      // Capture uncaught JS exceptions
      const onPageError = (error: Error) => {
        pageErrors.push({
          message: error.message,
          type: 'pageerror',
          category: categorizeConsoleError(error.message),
        });
      };

      // Capture console.error calls (includes CORS warnings the browser logs)
      const onConsoleError = (msg: any) => {
        if (msg.type() === 'error') {
          const text = msg.text();
          pageErrors.push({
            message: text,
            type: 'console.error',
            category: categorizeConsoleError(text),
          });
        }
      };

      // Capture failed network requests (CORS blocks, 404s, timeouts)
      const onRequestFailed = (request: any) => {
        const failure = request.failure()?.errorText || 'unknown';
        const reqUrl = request.url();
        failedRequests.push({
          url: reqUrl,
          resourceType: request.resourceType(),
          status: 0,
          statusText: '',
          failure,
          category: categorizeFailedRequest(failure, reqUrl, 0),
        });
      };

      // Capture HTTP error responses (4xx, 5xx)
      const onResponse = (response: any) => {
        const status = response.status();
        if (status >= 400) {
          const reqUrl = response.url();
          failedRequests.push({
            url: reqUrl,
            resourceType: response.request().resourceType(),
            status,
            statusText: response.statusText(),
            failure: `HTTP ${status}`,
            category: categorizeFailedRequest(`HTTP ${status}`, reqUrl, status),
          });
        }
      };

      page.on('pageerror', onPageError);
      page.on('console', onConsoleError);
      page.on('requestfailed', onRequestFailed);
      page.on('response', onResponse);

      let httpStatus = 0;
      let loadTime = 0;

      try {
        const startTime = Date.now();
        const response = await page.goto(url, {
          waitUntil: 'networkidle',
          timeout: 30_000,
        });
        loadTime = Date.now() - startTime;
        httpStatus = response?.status() ?? 0;

        const errorCount = pageErrors.length + failedRequests.length;
        const suffix = errorCount > 0 ? ` [${errorCount} issues]` : '';
        console.log(`${progress} ${httpStatus} ${loadTime}ms ${urlPath}${suffix}`);

        if (httpStatus >= 500) {
          console.warn(`  !! Server error on ${urlPath}`);
        }

        // Simulate human interaction — triggers hover/mousemove-dependent errors
        try {
          const viewport = page.viewportSize() || { width: 1280, height: 720 };
          const cx = viewport.width / 2;
          const cy = viewport.height / 2;
          // Sweep mouse across page in a Z pattern
          await page.mouse.move(0, 0);
          await page.mouse.move(cx, cy, { steps: 10 });
          await page.mouse.move(viewport.width * 0.8, viewport.height * 0.2, { steps: 10 });
          await page.mouse.move(viewport.width * 0.2, viewport.height * 0.8, { steps: 10 });
          await page.mouse.move(cx, cy, { steps: 10 });
          // Scroll down and back up
          await page.mouse.wheel(0, 300);
          await page.waitForTimeout(500);
          await page.mouse.wheel(0, -300);
        } catch {
          // ignore — interaction errors don't invalidate the audit
        }

        // Let QM mu-plugin write JSON + catch any deferred errors
        await page.waitForTimeout(1000);
      } catch (err: any) {
        console.error(`${progress} FAIL ${urlPath}: ${err.message}`);
      }

      page.removeListener('pageerror', onPageError);
      page.removeListener('console', onConsoleError);
      page.removeListener('requestfailed', onRequestFailed);
      page.removeListener('response', onResponse);

      // Build summary counts
      const allIssues = [
        ...pageErrors.map(e => e.category),
        ...failedRequests.map(r => r.category),
      ];
      const summary = {
        totalErrors: pageErrors.length,
        totalFailedRequests: failedRequests.length,
        cors: allIssues.filter(c => c === 'cors').length,
        runtime: allIssues.filter(c => c === 'runtime').length,
        network: allIssues.filter(c => c === 'network').length,
        security: allIssues.filter(c => c === 'security').length,
        deprecation: allIssues.filter(c => c === 'deprecation').length,
      };

      allResults.push({
        url: urlPath,
        httpStatus,
        loadTimeMs: loadTime,
        errors: pageErrors,
        failedRequests,
        summary,
      });

      // Detailed logging for pages with issues
      if (pageErrors.length > 0 || failedRequests.length > 0) {
        for (const e of pageErrors) {
          console.log(`  [${e.category}] ${e.type}: ${e.message.substring(0, 120)}`);
        }
        for (const r of failedRequests) {
          console.log(`  [${r.category}] ${r.resourceType} FAILED: ${r.url.substring(0, 120)} (${r.failure})`);
        }
      }
    }

    await context.close();
  });

  test.afterAll(async () => {
    // Write full results
    fs.writeFileSync(JS_ERRORS_FILE, JSON.stringify(allResults, null, 2));

    // Print summary
    const pagesWithIssues = allResults.filter(r => r.summary.totalErrors > 0 || r.summary.totalFailedRequests > 0);
    const totalCors = allResults.reduce((n, r) => n + r.summary.cors, 0);
    const totalRuntime = allResults.reduce((n, r) => n + r.summary.runtime, 0);
    const totalNetwork = allResults.reduce((n, r) => n + r.summary.network, 0);
    const totalSecurity = allResults.reduce((n, r) => n + r.summary.security, 0);

    console.log(`\n${'='.repeat(60)}`);
    console.log(`CRAWL SUMMARY`);
    console.log(`${'='.repeat(60)}`);
    console.log(`URLs crawled:        ${allResults.length}`);
    console.log(`Pages with issues:   ${pagesWithIssues.length}`);
    console.log(`  CORS errors:       ${totalCors}`);
    console.log(`  Runtime errors:    ${totalRuntime}`);
    console.log(`  Network failures:  ${totalNetwork}`);
    console.log(`  Security issues:   ${totalSecurity}`);
    console.log(`\nResults: ${JS_ERRORS_FILE}`);

    const qmFiles = fs.readdirSync(QM_OUTPUT).filter((f: string) => f.endsWith('.json') && f !== 'js-errors.json');
    console.log(`QM JSON files: ${qmFiles.length}`);
    console.log(`\nRun audit.py to analyze:\n  python3 .claude/skills/site-audit/audit.py --base .\n`);
  });
});
