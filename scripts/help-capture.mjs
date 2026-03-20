import fs from 'node:fs/promises';
import path from 'node:path';

const manifestPath = process.argv[2];

if (!manifestPath) {
  console.error('Usage: node scripts/help-capture.mjs <manifest.json>');
  process.exit(1);
}

let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch (error) {
  console.error('Playwright is not installed in this workspace. Install it before running help:capture-screenshots.');
  process.exit(1);
}

const manifest = JSON.parse(await fs.readFile(manifestPath, 'utf8'));
const browser = await chromium.launch({ headless: true });

async function waitForAnySelector(page, selectors, timeout = 12000) {
  const usableSelectors = selectors.filter((selector) => typeof selector === 'string' && selector.trim() !== '');
  const start = Date.now();

  for (const selector of usableSelectors) {
    const elapsed = Date.now() - start;
    const remaining = timeout - elapsed;

    if (remaining <= 0) {
      break;
    }

    try {
      await page.waitForSelector(selector, { timeout: remaining, state: 'visible' });
      return selector;
    } catch {
      // Try the next selector before falling back to a generic page readiness check.
    }
  }

  return null;
}

async function maskSelectors(page, selectors) {
  const maskRefs = [];

  for (const selector of selectors) {
    try {
      const locator = page.locator(selector);
      const count = await locator.count();

      for (let index = 0; index < count; index += 1) {
        maskRefs.push(locator.nth(index));
      }
    } catch {
      // Ignore invalid or missing selectors so one bad mask does not stop the capture run.
    }
  }

  return maskRefs;
}

async function runAction(page, action) {
  const type = typeof action?.type === 'string' ? action.type : '';
  const selector = typeof action?.selector === 'string' ? action.selector : '';
  const waitFor = Array.isArray(action?.wait_for)
    ? action.wait_for
    : (typeof action?.wait_for === 'string' && action.wait_for.trim() !== '' ? [action.wait_for] : []);

  switch (type) {
    case 'click':
      await page.locator(selector).first().click();
      break;
    case 'fill':
      await page.locator(selector).first().fill(String(action?.value ?? ''));
      break;
    case 'press':
      await page.locator(selector).first().press(String(action?.key ?? 'Enter'));
      break;
    case 'select': {
      const values = Array.isArray(action?.value) ? action.value.map(String) : [String(action?.value ?? '')];
      await page.locator(selector).first().selectOption(values);
      break;
    }
    case 'wait':
      await page.waitForTimeout(Number(action?.ms ?? 400));
      break;
    case 'wait_for':
      await waitForAnySelector(page, Array.isArray(action?.selector) ? action.selector : [selector], Number(action?.timeout ?? 12000));
      break;
    default:
      throw new Error(`Unsupported capture action type: ${type}`);
  }

  if (waitFor.length > 0) {
    await waitForAnySelector(page, [...waitFor, 'body'], Number(action?.timeout ?? 12000));
  }

  await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(Number(action?.after_ms ?? 250));
}

try {
  for (const scenario of manifest) {
    const context = await browser.newContext({
      viewport: scenario.viewport || { width: 1440, height: 960 },
    });
    const page = await context.newPage();

    await page.goto(scenario.login_url, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', scenario.username);
    await page.fill('input[name="password"]', scenario.password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');

    await page.goto(scenario.url, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('domcontentloaded');

    const requestedSelectors = Array.isArray(scenario.wait_for)
      ? scenario.wait_for
      : (typeof scenario.wait_for === 'string' && scenario.wait_for.trim() !== '' ? [scenario.wait_for] : []);
    const fallbackSelectors = ['h1', '[aria-current="page"]', 'nav', 'body'];
    await waitForAnySelector(page, [...requestedSelectors, ...fallbackSelectors]);
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(400);

    const actions = Array.isArray(scenario.actions) ? scenario.actions : [];
    for (const action of actions) {
      await runAction(page, action);
    }

    await fs.mkdir(path.dirname(scenario.output_absolute_path), { recursive: true });
    const masks = await maskSelectors(page, Array.isArray(scenario.mask_selectors) ? scenario.mask_selectors : []);
    await page.screenshot({
      path: scenario.output_absolute_path,
      type: 'png',
      fullPage: Boolean(scenario.full_page),
      mask: masks,
    });

    console.log(`Captured ${scenario.key} -> ${scenario.output_relative_path}`);
    await context.close();
  }
} finally {
  await browser.close();
}
