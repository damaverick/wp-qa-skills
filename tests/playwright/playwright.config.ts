import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './src',
  fullyParallel: false,      // Serial crawl — one page at a time
  workers: 1,
  timeout: 30000,            // 30s per page
  retries: 0,
  use: {
    headless: true,
    ignoreHTTPSErrors: true,
    baseURL: 'https://capral-2026.local',
  },
});
