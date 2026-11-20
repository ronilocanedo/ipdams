const { test, expect } = require('@playwright/test');

// Local anonymous session only. Do not use real user accounts or financial data.
test('anonymous session is returned by isolated QA API', async ({ request }) => {
  const result = await request.get('/api/index.php?route=auth.session');
  expect(result.status()).toBe(200);
  const payload = await result.json();
  expect(payload).toHaveProperty('data');
  expect(payload.data.user).toBeNull();
  expect(payload.data).toHaveProperty('csrf');
  expect(typeof payload.data.csrf).toBe('string');
  expect(payload.data.csrf.length).toBeGreaterThan(16);
});

test('application shell renders without white screen, browser errors or overflow', async ({ page }, testInfo) => {
  const failures = [];
  page.on('pageerror', err => failures.push('pageerror: ' + err.message));
  page.on('console', msg => { if (msg.type() === 'error') failures.push('console: ' + msg.text()); });
  page.on('requestfailed', req => failures.push('request: ' + req.url() + ' ' + (req.failure()?.errorText || 'failed')));
  // Block off-site HTTP requests, including any attempt to call the production domain.
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.protocol === 'http:' || u.protocol === 'https:') {
      if (u.origin !== new URL(testInfo.project.use.baseURL || process.env.PLAYWRIGHT_BASE_URL || 'http://web').origin) {
        failures.push('BLOCKED outbound URL: ' + u.origin);
        return route.abort();
      }
    }
    return route.continue();
  });
  const response = await page.goto('/', { waitUntil: 'domcontentloaded' });
  expect(response && response.status()).toBe(200);
  await expect(page.locator('#root')).not.toBeEmpty({ timeout: 20_000 });
  await page.waitForTimeout(1200); // catch delayed React errors and layout changes
  const doc = await page.evaluate(() => ({
    pageWidth: document.documentElement.scrollWidth,
    viewWidth: document.documentElement.clientWidth,
    hasVisibleBody: document.body && document.body.innerText.trim().length > 10,
  }));
  expect(doc.hasVisibleBody, 'blank screen / no visible application text').toBeTruthy();
  expect(doc.pageWidth - doc.viewWidth, 'horizontal overflow').toBeLessThanOrEqual(2);
  expect(failures, 'JavaScript, console, network or outbound errors').toEqual([]);
  const screenshot = await page.screenshot({ fullPage: true });
  await testInfo.attach('anonymous-ui', { body: screenshot, contentType: 'image/png' });
});
