'use strict';
/**
 * IPDAMS v0.2.380 browser QA: isolated local synthetic DB only.
 * NEVER sends PR/DV writes. The only POST is test-account auth.login from
 * context.request, before the page request firewall is installed.
 */
const { test, expect } = require('@playwright/test');

const SCHOOL_EMAIL = 'qa-school@example.invalid';
const DIVISION_EMAIL = 'qa-division@example.invalid';
const ITEM_NAME = 'QA-FICTIONAL COLORED PRINTER';
const VALID_DATE = '2026-11-12';
const EARLY_DATE = '2026-11-05';
const UACS = '5021305002';

function assertIsolation() {
  if (process.env.PLAYWRIGHT_BASE_URL !== 'http://web' ||
      process.env.IPDAMS_QA_V0380_ISOLATED !== '1' ||
      !process.env.QA_V0380_TEST_PASSWORD) {
    throw new Error('BLOCKED: requires Docker-local http://web, v0380 isolation flag and synthetic test password');
  }
}

async function session(context) {
  const response = await context.request.get('/api/index.php?route=auth.session');
  expect(response.status(), 'QA session endpoint must respond').toBe(200);
  const payload = await response.json();
  expect(typeof payload?.data?.csrf).toBe('string');
  expect(Array.isArray(payload?.data?.organizations)).toBe(true);
  return payload.data;
}

async function login(context, role) {
  assertIsolation();
  const before = await session(context);
  expect(before.user, 'Test must start from anonymous context').toBeNull();
  const email = role === 'SCHOOL' ? SCHOOL_EMAIL : DIVISION_EMAIL;
  const response = await context.request.post('/api/index.php?route=auth.login', {
    headers: { 'X-CSRF-Token': before.csrf },
    data: { email, password: process.env.QA_V0380_TEST_PASSWORD },
  });
  // Deliberately omit the response body: never print credentials or session tokens.
  expect(response.status(), `Synthetic ${role} sign-in must succeed`).toBe(200);
  const after = await session(context);
  expect(after.user?.role).toBe(role);
  expect(after.organizations.length).toBeGreaterThan(0);
  expect(after.organizations.every(o => /^QA-/.test(o.code))).toBe(true);
  return after;
}

async function getData(context, path) {
  const res = await context.request.get(path);
  expect(res.status(), 'Synthetic read-only API request must succeed').toBe(200);
  const json = await res.json();
  expect(json).toHaveProperty('data');
  return json.data;
}

async function syntheticItem(context, schoolId) {
  const params = new URLSearchParams({route:'catalog.search', organization_id:String(schoolId), term:'QA-FICTIONAL COLORED PRINTER'});
  const rows = await getData(context, `/api/index.php?${params}`);
  expect(Array.isArray(rows)).toBe(true);
  const match = rows.find(r => r.name === ITEM_NAME);
  expect(match, 'Synthetic item must be visible in School catalog').toBeTruthy();
  return match;
}

async function classification(context, schoolId, itemId, date) {
  const params = new URLSearchParams({
    route:'pr.classification.preview', organization_id:String(schoolId), item_id:String(itemId),
    unit_price:'9500.00', as_of:date,
  });
  return getData(context, `/api/index.php?${params}`);
}

async function protectPage(page) {
  const failures = { browser:[], network:[], writes:[], outbound:[], server:[] };
  page.on('pageerror', e => failures.browser.push(String(e.message).slice(0,240)));
  page.on('console', msg => {
    if (msg.type() === 'error') failures.browser.push(String(msg.text()).slice(0,240));
  });
  page.on('response', res => {
    if (res.status() >= 500) failures.server.push(`${res.status()} ${new URL(res.url()).pathname}`);
  });
  page.on('requestfailed', req => {
    // The firewall itself can intentionally abort writes/outbound requests.
    if (req.method() === 'GET' && new URL(req.url()).origin === 'http://web')
      failures.network.push(`${new URL(req.url()).pathname}: ${req.failure()?.errorText || 'failed'}`);
  });
  await page.route('**/*', async route => {
    const req = route.request();
    const url = new URL(req.url());
    if ((url.protocol === 'http:' || url.protocol === 'https:') && url.origin !== 'http://web') {
      failures.outbound.push(`${url.protocol}//${url.host}`);
      return route.abort('blockedbyclient');
    }
    if (!['GET', 'HEAD'].includes(req.method())) {
      failures.writes.push(`${req.method()} ${url.pathname}`);
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  return failures;
}

async function checkShell(page, failures, info, tag) {
  await expect(page.locator('#root')).toBeVisible();
  await expect.poll(async () => (await page.locator('#root').innerText()).trim().length, {timeout:20_000}).toBeGreaterThan(20);
  await expect(page.getByText('Unable to restore your workspace')).toHaveCount(0);
  await expect(page.getByText(/Cannot read properties of undefined/i)).toHaveCount(0);
  await page.waitForTimeout(600);
  await info.attach(tag, {body:await page.screenshot({fullPage:true}), contentType:'image/png'});
  const width = await page.evaluate(() => ({actual:document.documentElement.scrollWidth, viewport:document.documentElement.clientWidth}));
  expect(width.actual - width.viewport, 'Horizontal page overflow').toBeLessThanOrEqual(4);
  expect(failures.browser, 'JavaScript/console errors').toEqual([]);
  expect(failures.network, 'Failed local GET requests').toEqual([]);
  expect(failures.server, 'HTTP 5xx on local site').toEqual([]);
  expect(failures.outbound, 'Unexpected outbound requests were blocked').toEqual([]);
  expect(failures.writes, 'The read-only browser must not issue write requests').toEqual([]);
}

async function attachUiInventory(page, info, label) {
  // Only synthetic UI labels and input types; no text field values, session tokens,
  // cookies, passwords, external URLs, or response bodies are collected.
  const inventory = await page.evaluate(() => ({
    headings:Array.from(document.querySelectorAll('h1,h2,h3')).map(e=>(e.textContent||'').trim()).filter(Boolean).slice(0,35),
    buttons:Array.from(document.querySelectorAll('button')).map(e=>(e.innerText||e.getAttribute('aria-label')||'').trim()).filter(Boolean).slice(0,100),
    fields:Array.from(document.querySelectorAll('input,select,textarea')).map(e=>({tag:e.tagName.toLowerCase(),type:e.getAttribute('type')||'',placeholder:e.getAttribute('placeholder')||'',name:e.getAttribute('name')||''})).slice(0,70),
    section:new URL(location.href).searchParams.get('section')||'',
  }));
  await info.attach(label, {body:Buffer.from(JSON.stringify(inventory,null,2)),contentType:'application/json'});
}

async function clickVisible(page, patterns) {
  for (const pattern of patterns) {
    const locators = [page.getByRole('button',{name:pattern}),page.getByRole('tab',{name:pattern}),page.getByRole('link',{name:pattern})];
    for (const locator of locators) {
      const count = await locator.count();
      for(let i=0;i<Math.min(count,8);i++){
        const target=locator.nth(i);
        if (await target.isVisible()) {await target.click({timeout:6_000});return true;}
      }
    }
  }
  return false;
}

async function openPRForm(page, info) {
  const res=await page.goto('/?section=Procurement',{waitUntil:'domcontentloaded'});
  expect(res?.status()).toBe(200);
  await expect(page.locator('#root')).not.toBeEmpty();
  await page.waitForTimeout(500);
  await attachUiInventory(page, info, 'procurement-controls-before-pr');
  // Match accessible button names including the actual "+ New Purchase Request".
  // Avoid brittle DOM classes. Never click Save/Finalize.
  const buttons = [
    /^\s*(?:\+\s*)?(?:New|Create|Start|Prepare|Add)\s+(?:a\s+)?(?:New\s+)?(?:Purchase Request|PR)\b/i,
    /^(New|Create|Start|Prepare)\s+(a\s+)?(Request|Procurement Request)\b/i,
  ];
  if (!await clickVisible(page,buttons)) {
    await clickVisible(page,[/^(Purchase Requests?|PR Register|Requests?)$/i]);
    await page.waitForTimeout(350);
    if (!await clickVisible(page,buttons)) {
      await info.attach('procurement-no-pr-launch', {body:await page.screenshot({fullPage:true}),contentType:'image/png'});
      await attachUiInventory(page,info,'procurement-controls-after-nav');
      throw new Error('PR_FORM_NOT_FOUND: no visible New/Create/Start PR button; inspect attached UI inventory and screenshot');
    }
  }
  // React may mount the modal header before its fields finish rendering.
  // An arbitrary 400 ms sleep can race that mount on either viewport. Wait
  // for the actual PR date control, rather than mistaking a slow UI for a
  // missing form. The test must still FAIL if the field never appears.
  const dateInput=page.locator('input[type="date"]:visible:enabled').first();
  try {
    await expect(dateInput,'New PR modal must expose an enabled date field')
      .toBeVisible({timeout:20_000});
  } catch (error) {
    await attachUiInventory(page,info,'pr-date-field-not-ready');
    await info.attach('pr-date-field-not-ready-screen',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png',
    });
    throw error;
  }
  await attachUiInventory(page,info,'pr-form-controls');
  await info.attach('pr-form-open', {body:await page.screenshot({fullPage:true}),contentType:'image/png'});
}

async function setPrDate(page,date){
  // Scoped to a VISIBLE, ENABLED native date control, not an unrelated
  // hidden input from a previous modal. Avoid snapshot timing and index races.
  const input=page.locator('input[type="date"]:visible:enabled').first();
  await expect(input,'PR date must remain visible and enabled').toBeVisible({timeout:10_000});
  await input.fill(date,{timeout:10_000});
  await expect(input,`PR date should be ${date}`).toHaveValue(date,{timeout:10_000});
  return true;
}

async function chooseSyntheticItem(page) {
  // Supports a native select, searchable input, and a clickable catalog row.
  const selects = page.locator('select');
  for(let i=0;i<Math.min(await selects.count(),20);i++){
    const s=selects.nth(i);if(!await s.isVisible())continue;
    const options=await s.locator('option').allTextContents();
    const exact=options.find(x=>x.includes(ITEM_NAME));
    if(exact){await s.selectOption({label:exact});return true;}
  }
  const inputs=[page.getByPlaceholder(/search.*(item|catalog)|item.*search|catalog/i),page.getByLabel(/Search Item|Catalog Item|Select Item/i)];
  for(const candidates of inputs){
    for(let i=0;i<Math.min(await candidates.count(),8);i++){
      const field=candidates.nth(i);if(!await field.isVisible()||!await field.isEnabled())continue;
      await field.fill('QA-FICTIONAL');
      await page.waitForTimeout(500);
      const option=page.getByText(ITEM_NAME,{exact:false});
      for(let j=0;j<Math.min(await option.count(),8);j++){
        if(await option.nth(j).isVisible()) {await option.nth(j).click({timeout:5_000});return true;}
      }
    }
  }
  const direct=page.getByText(ITEM_NAME,{exact:false});
  for(let i=0;i<Math.min(await direct.count(),8);i++){
    if(await direct.nth(i).isVisible()) {await direct.nth(i).click({timeout:5_000});return true;}
  }
  return false;
}

async function setUnitPrice(page) {
  const candidates=[page.getByLabel(/Estimated Unit Price|Unit Price|Price per Unit/i),page.getByPlaceholder(/unit price|estimated price/i)];
  for (const candidatesLocator of candidates) {
    for(let i=0;i<Math.min(await candidatesLocator.count(),8);i++){
      const field=candidatesLocator.nth(i);
      if(await field.isVisible()&&await field.isEnabled()){await field.fill('9500');return true;}
    }
  }
  return false;
}

/**
 * The PR entry form has an explicit 'Check property / UACS' control.
 * Earlier UI QA only selected the item and waited for text that is not
 * guaranteed to be rendered before the user opens this read-only preview.
 * Do not open Save or Finalize. This control must never issue a POST.
 */
async function openPRClassificationReview(page, info, label) {
  const candidates = [
    page.getByRole('button', {name:/Check\s+property\s*\/\s*UACS/i}),
    page.getByRole('link', {name:/Check\s+property\s*\/\s*UACS/i}),
    page.getByText(/Check\s+property\s*\/\s*UACS/i),
  ];
  let clicked=false;
  for (const locator of candidates) {
    const total=Math.min(await locator.count(),5);
    for(let i=0;i<total;i++){
      const button=locator.nth(i);
      if(!await button.isVisible() || !await button.isEnabled()) continue;
      await button.click({timeout:7_000});
      clicked=true;
      break;
    }
    if(clicked)break;
  }
  if(!clicked) {
    await attachUiInventory(page,info,`${label}-missing-check-control`);
    await info.attach(`${label}-missing-check-control-screen`, {
      body:await page.screenshot({fullPage:true}),contentType:'image/png',
    });
    throw new Error('PR_UACS_PREVIEW_CONTROL_MISSING: no actionable Check property / UACS control');
  }
  await page.waitForTimeout(700);
  await attachUiInventory(page,info,`${label}-preview-controls`);
  await info.attach(`${label}-preview-screen`, {
    body:await page.screenshot({fullPage:true}),contentType:'image/png',
  });
}

async function prepareSyntheticPrItem(page, info, documentDate) {
  await openPRForm(page,info);
  expect(await setPrDate(page,documentDate),'PR form must expose an editable document date').toBe(true);
  expect(await chooseSyntheticItem(page),'PR form must allow selecting synthetic catalog item').toBe(true);
  expect(await setUnitPrice(page),'PR form must expose a unit price field').toBe(true);
  await page.waitForTimeout(600);
}

test.beforeEach(() => assertIsolation());

test('school: read-only PR preview uses original PR date and approved Division UACS', async ({context}) => {
  const auth=await login(context,'SCHOOL');
  const school=auth.organizations.find(o=>o.code==='QA-SCH');
  expect(school,'Fixture school must be present').toBeTruthy();
  const item=await syntheticItem(context,school.id);
  const valid=await classification(context,school.id,item.id,VALID_DATE);
  expect(valid.stage).toBe('PR_PRELIMINARY_DOCUMENTATION_ONLY');
  expect(valid.uacs_code).toBe(UACS);
  expect(valid.uacs_effective_from).toBe(VALID_DATE);
  const early=await classification(context,school.id,item.id,EARLY_DATE);
  expect(early.uacs_code,'An earlier PR must not inherit a future mapping').toBe('');
  expect(early.warnings.join(' ')).toMatch(/no unambiguous.*mapping.*effective/i);
});

for(const role of ['SCHOOL','DIVISION']) {
  test(`${role.toLowerCase()}: authenticated browser shell is stable and responsive`, async ({context,page},info)=>{
    await login(context,role);
    const errors=await protectPage(page);
    const response=await page.goto('/',{waitUntil:'domcontentloaded'});
    expect(response?.status()).toBe(200);
    await checkShell(page,errors,info,`${role.toLowerCase()}-shell`);
    await attachUiInventory(page,info,`${role.toLowerCase()}-navigation`);
  });
}

test('school: PR item entry shows effective UACS in Check property / UACS review and an earlier-date warning', async ({context,page},info)=>{
  await login(context,'SCHOOL');
  const errors=await protectPage(page);

  // Always start from a fresh unsaved PR form; this exercises the real
  // read-only classification control, not direct API-only assertions.
  await prepareSyntheticPrItem(page,info,VALID_DATE);
  const initiallyVisible=(await page.locator('#root').innerText()).includes(UACS);
  await info.attach('pr-classification-display-mode', {
    body:Buffer.from(JSON.stringify({
      approvedCode:UACS, date:VALID_DATE,
      visibleWithoutOpeningReview:initiallyVisible,
      control:'Check property / UACS',
    },null,2)),contentType:'application/json',
  });
  await openPRClassificationReview(page,info,'pr-valid-date');
  await expect(page.locator('#root'),
    `PR classification review must display approved debit UACS ${UACS}`)
    .toContainText(UACS,{timeout:12_000});

  // The preview is a second overlay; navigating and immediately reopening a PR
  // in the SAME page can race overlay unmounting (observed on desktop only).
  // Use a separate tab within the same authenticated synthetic-only context:
  // both date scenarios start with a genuinely fresh, unsaved form. This does
  // NOT mask an incorrect UACS or effectivity warning: those assertions remain.
  const earlyPage=await context.newPage();
  const earlyErrors=await protectPage(earlyPage);
  try {
    await prepareSyntheticPrItem(earlyPage,info,EARLY_DATE);
    await openPRClassificationReview(earlyPage,info,'pr-earlier-date');
    await expect(earlyPage.locator('#root'),
      'PR classification review must warn that no mapping is effective for an earlier PR date')
      .toContainText(/(no\s+(eligible|effective|unambiguous).*UACS|no\s+.*mapping.*effective|mapping.*not.*effective|UACS.*not.*effective)/i,
        {timeout:12_000});
    await expect(earlyPage.locator('#root'),
      'Earlier PR date must not silently display later-approved debit UACS').not.toContainText(UACS);

    for (const [scenario, observed] of [
      ['effective-date',errors], ['earlier-date',earlyErrors],
    ]) {
      expect(observed.browser,`${scenario}: browser script errors`).toEqual([]);
      expect(observed.network,`${scenario}: failed local GET requests`).toEqual([]);
      expect(observed.server,`${scenario}: HTTP 5xx`).toEqual([]);
      expect(observed.outbound,`${scenario}: offsite requests`).toEqual([]);
      expect(observed.writes,`${scenario}: no browser writes permitted`).toEqual([]);
    }
  } finally {
    await earlyPage.close();
  }
});
