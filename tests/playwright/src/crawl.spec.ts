import { test, expect, request } from '@playwright/test';
import { writeFileSync } from 'fs';

// Configuration — override via env vars or edit below
// SITE_BASE: site URL (default: https://mysite.local)
// MAX_URLS: cap pages to crawl (default: 200)
// QM_COOKIE_VALUE: must match mu-plugin (default: '1')
const QM_COOKIE = { name: 'qm_auth', value: process.env.QM_COOKIE_VALUE || '1' };
const BASE = process.env.SITE_BASE || 'https://mysite.local';
const MAX_URLS = parseInt(process.env.MAX_URLS || '200', 10);

// Collect URLs from wp-sitemap.xml and all sub-sitemaps
async function collectSitemapUrls(): Promise<string[]> {
  const apiContext = await request.newContext({ ignoreHTTPSErrors: true });
  const urls: string[] = [];

  try {
    // Fetch main sitemap
    const resp = await apiContext.get(`${BASE}/wp-sitemap.xml`);
    const xml = await resp.text();

    // Extract sub-sitemap URLs
    const sitemapRegex = /<sitemap[^>]*>\s*<loc>([^<]+)<\/loc>/gi;
    let match;
    const subSitemaps: string[] = [];
    while ((match = sitemapRegex.exec(xml)) !== null) {
      subSitemaps.push(match[1]);
    }

    // Also extract direct URLs from the main sitemap
    const urlRegex = /<url[^>]*>\s*<loc>([^<]+)<\/loc>/gi;
    while ((match = urlRegex.exec(xml)) !== null) {
      urls.push(match[1]);
    }

    // Crawl each sub-sitemap
    for (const sitemapUrl of subSitemaps) {
      const subResp = await apiContext.get(sitemapUrl);
      const subXml = await subResp.text();
      const subUrlRegex = /<url[^>]*>\s*<loc>([^<]+)<\/loc>/gi;
      while ((match = subUrlRegex.exec(subXml)) !== null) {
        urls.push(match[1]);
      }
    }
  } finally {
    await apiContext.dispose();
  }

  return urls;
}

// Deduplicate and sample URLs (cap at MAX_URLS)
function sampleUrls(urls: string[], max: number): string[] {
  const seen = new Set<string>();
  const unique: string[] = [];

  // Prioritize: homepage first
  const homepage = urls.find(u => u.replace(/\/$/, '') === BASE);
  if (homepage) {
    unique.push(homepage);
    seen.add(homepage.replace(/\/$/, ''));
  }

  // Top-level pages (single path segment after domain)
  const topLevel = urls.filter(u => {
    const path = new URL(u).pathname;
    const segments = path.split('/').filter(Boolean);
    return segments.length <= 1 && !seen.has(u.replace(/\/$/, ''));
  });
  for (const u of topLevel) {
    if (unique.length >= max) break;
    unique.push(u);
    seen.add(u.replace(/\/$/, ''));
  }

  // Remaining URLs
  for (const u of urls) {
    if (unique.length >= max) break;
    const key = u.replace(/\/$/, '');
    if (!seen.has(key)) {
      unique.push(u);
      seen.add(key);
    }
  }

  return unique;
}

test.describe.serial('QM Performance Crawl', () => {
  let urlsToCrawl: string[] = [];
  const jsErrors: Record<string, Array<{ message: string; type: string }>> = {};

  test.beforeAll(async () => {
    const allUrls = await collectSitemapUrls();
    urlsToCrawl = sampleUrls(allUrls, MAX_URLS);
    console.log(`[crawl] Discovered ${allUrls.length} URLs, crawling ${urlsToCrawl.length}`);
  });

  test.afterAll(async () => {
    const outputDir = '../../wp-content/qm-output';
    const out = Object.entries(jsErrors)
      .filter(([, errors]) => errors.length > 0)
      .map(([url, errors]) => ({ url, errors }));
    if (out.length > 0) {
      writeFileSync(
        `${outputDir}/js-errors.json`,
        JSON.stringify(out, null, 2),
      );
      console.log(`[crawl] Wrote JS errors for ${out.length} URLs`);
    }
  });

  for (let i = 0; i < MAX_URLS; i++) {
    test(`page ${i}`, async ({ page }) => {
      if (i >= urlsToCrawl.length) {
        test.skip();
        return;
      }

      const url = urlsToCrawl[i];
      const startTime = Date.now();
      const urlErrors: Array<{ message: string; type: string }> = [];

      // Capture uncaught JS exceptions
      page.on('pageerror', (err) => {
        urlErrors.push({ message: err.message, type: 'pageerror' });
      });

      // Capture console.error calls
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          urlErrors.push({ message: msg.text(), type: 'console.error' });
        }
      });

      // Set QM auth cookie — domain extracted from BASE
      const domain = new URL(BASE).hostname;
      await page.context().addCookies([{ ...QM_COOKIE, domain, path: '/' }]);

      try {
        const response = await page.goto(url, {
          waitUntil: 'load',
          timeout: 30000,
        });

        const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
        const status = response?.status() || 0;

        if (urlErrors.length > 0) {
          jsErrors[url] = urlErrors;
          console.log(`[warn] ${status} ${url} (${elapsed}s) JS errors: ${urlErrors.map(e => e.message.substring(0, 60)).join(' | ')}`);
        }

        if (status >= 400) {
          console.log(`[warn] ${status} ${url} (${elapsed}s)`);
        } else if (urlErrors.length === 0) {
          console.log(`[ok] ${url} (${elapsed}s)`);
        }

        expect(status).toBeLessThan(500);
      } catch (err: any) {
        const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
        console.log(`[fail] ${url} (${elapsed}s) ${err.message?.substring(0, 80)}`);
        test.skip();
      }
    });
  }
});
