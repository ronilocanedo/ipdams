const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://web';
// Fail closed: never test Hostinger or any real external site.
if (baseURL !== 'http://web' && baseURL !== 'http://127.0.0.1:8877') {
  throw new Error(`BLOCKED: Playwright baseURL must be local QA, got: ${baseURL}`);
}
module.exports = defineConfig({
  testDir: './tests',
  testMatch: ['anonymous-smoke.spec.cjs'], // fixture-dependent PR test has a separate gated config
  timeout: 30_000,
  expect: { timeout: 15_000 },
  retries: 0,
  workers: 1,
  fullyParallel: false,
  reporter: [ ['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }],
              ['json', { outputFile: 'test-results/results.json' }] ],
  outputDir: 'test-results',
  use: { baseURL, trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'off' },
  projects: [
    { name: 'chromium-desktop', use: { browserName: 'chromium', viewport: { width: 1366, height: 768 } } },
    { name: 'chromium-mobile', use: { ...devices['Pixel 7'], browserName: 'chromium' } },
  ],
});
