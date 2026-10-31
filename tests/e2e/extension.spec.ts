import path from 'node:path';

import { type BrowserContext, chromium, expect, type Page, test as base, type Worker } from '@playwright/test';

import { addEntryThroughUi, EMAIL, MASTER_PASSWORD, registerThroughUi, resetState } from './support';

// The extension is loaded unpacked, as built by `node tools/build.mjs --e2e`.
const EXTENSION_PATH = path.resolve('extension/dist');

const test = base.extend<{ context: BrowserContext; worker: Worker; extensionId: string }>({
  context: async ({ baseURL }, use) => {
    const context = await chromium.launchPersistentContext('', {
      channel: 'chromium',
      baseURL: baseURL ?? '',
      args: [`--disable-extensions-except=${EXTENSION_PATH}`, `--load-extension=${EXTENSION_PATH}`],
    });
    await use(context);
    await context.close();
  },
  worker: async ({ context }, use) => {
    const worker = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
    await use(worker);
  },
  extensionId: async ({ worker }, use) => {
    await use(new URL(worker.url()).host);
  },
});

const LOGIN_FORM = `<!doctype html><html><body>
  <form action="/session" method="post">
    <label>Email <input type="email" name="login" autocomplete="username"></label>
    <label>Mot de passe <input type="password" name="password" autocomplete="current-password"></label>
    <button>Connexion</button>
  </form>
  OVERLAY
</body></html>`;

async function fakeSite(context: BrowserContext): Promise<void> {
  await context.route(/^https?:\/\/(bank|evil)\.test\//, async (route) => {
    const url = new URL(route.request().url());
    const overlay = url.pathname === '/overlay'
      ? '<div style="position:fixed;inset:0;background:transparent;z-index:9999"></div>'
      : '';
    await route.fulfill({ status: 200, contentType: 'text/html', body: LOGIN_FORM.replace('OVERLAY', overlay) });
  });
}

async function tabIdOf(worker: Worker, url: string): Promise<number> {
  return worker.evaluate(async (target) => {
    const [tab] = await chrome.tabs.query({ url: target });
    return tab?.id ?? -1;
  }, url);
}

async function openPopup(context: BrowserContext, extensionId: string, tabId: number): Promise<Page> {
  const popup = await context.newPage();
  await popup.goto(`chrome-extension://${extensionId}/popup.html?tab=${String(tabId)}`);
  return popup;
}

test('the extension unlocks locally and fills only the matching, visible fields', async ({ context, worker, extensionId, baseURL }) => {
  test.setTimeout(180_000);
  resetState();
  await fakeSite(context);

  const app = await context.newPage();
  const { secretKey } = await registerThroughUi(app);
  await addEntryThroughUi(app, { site: 'bank.test', username: 'alice@bank.test', password: 'Bank-Pass-123!' });

  const bank = await context.newPage();
  await bank.goto('https://bank.test/login');
  const bankTab = await tabIdOf(worker, 'https://bank.test/login');

  // First use: server address, then unlock with the master password and the Secret Key.
  let popup = await openPopup(context, extensionId, bankTab);
  await popup.getByLabel('URL du serveur').fill(baseURL ?? '');
  await popup.getByRole('button', { name: 'Enregistrer' }).click();
  // This browser does not know the Secret Key yet: it is asked along with the email.
  await expect(popup.getByLabel('Secret Key')).toBeVisible();
  await popup.getByLabel('Email').fill(EMAIL);
  await popup.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await popup.getByLabel('Secret Key').fill(secretKey);
  await popup.getByRole('button', { name: 'Déverrouiller' }).click();

  await expect(popup.getByTestId('popup-entries')).toContainText('alice@bank.test', { timeout: 30_000 });
  await popup.getByRole('button', { name: 'Remplir' }).click();
  await expect(bank.locator('input[name=login]')).toHaveValue('alice@bank.test');
  await expect(bank.locator('input[name=password]')).toHaveValue('Bank-Pass-123!');

  // Another domain: nothing is offered.
  const evil = await context.newPage();
  await evil.goto('https://evil.test/login');
  popup = await openPopup(context, extensionId, await tabIdOf(worker, 'https://evil.test/login'));
  await expect(popup.getByText('Aucun compte enregistré pour evil.test.')).toBeVisible({ timeout: 30_000 });
  await expect(evil.locator('input[name=password]')).toHaveValue('');

  // A field covered by an invisible overlay (clickjacking attempt) is not filled.
  const overlay = await context.newPage();
  await overlay.goto('https://bank.test/overlay');
  popup = await openPopup(context, extensionId, await tabIdOf(worker, 'https://bank.test/overlay'));
  await popup.getByRole('button', { name: 'Remplir' }).click();
  await expect(popup.getByRole('alert')).toContainText('masqué ou recouvert');
  await expect(overlay.locator('input[name=password]')).toHaveValue('');

  // Plain HTTP pages never receive credentials.
  const http = await context.newPage();
  await http.goto('http://bank.test/login');
  popup = await openPopup(context, extensionId, await tabIdOf(worker, 'http://bank.test/login'));
  await expect(popup.getByText('HTTPS obligatoire')).toBeVisible({ timeout: 30_000 });

  // Locking forgets the key.
  await popup.getByRole('button', { name: 'Verrouiller' }).click();
  await expect(popup.getByRole('heading', { name: 'Déverrouiller le coffre' })).toBeVisible();
});

test('web pages cannot talk to the service worker', async ({ context, worker }) => {
  const page = await context.newPage();
  await fakeSite(context);
  await page.goto('https://bank.test/login');
  const extensionId = new URL(worker.url()).host;

  // Without externally_connectable, a page has no channel to the extension.
  const reachable = await page.evaluate((id) => {
    const runtime = (globalThis as { chrome?: { runtime?: { sendMessage?: unknown } } }).chrome?.runtime;
    return typeof runtime?.sendMessage === 'function' && id !== '';
  }, extensionId);
  expect(reachable).toBe(false);
});
