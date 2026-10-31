import { expect, test } from '@playwright/test';

import { EMAIL, loginThroughUi, MASTER_PASSWORD, recordTraffic, registerThroughUi, resetState } from './support';

test.beforeEach(() => {
  resetState();
});

test('registration, emergency kit and first login never send the master password or the Secret Key', async ({ page }) => {
  const traffic = recordTraffic(page);

  const secretKey = await registerThroughUi(page);

  const sent = traffic.join('\n');
  expect(sent).toContain('/api/auth/register');
  expect(sent).not.toContain(MASTER_PASSWORD);
  expect(sent).not.toContain(secretKey);
  expect(sent).not.toContain(secretKey.replaceAll('-', ''));
});

test('locking keeps the session and unlocking only needs the master password', async ({ page }) => {
  await registerThroughUi(page);

  await page.getByRole('button', { name: 'Verrouiller' }).click();
  await expect(page.getByRole('heading', { name: 'Coffre verrouillé' })).toBeVisible();
  await expect(page.getByLabel('Secret Key')).toBeHidden();

  await page.getByLabel('Mot de passe maître').fill('not the password at all');
  await page.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(page.getByRole('alert')).toContainText('Déchiffrement impossible', { timeout: 30_000 });

  await page.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await page.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(page.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });

  // Reloading the page forgets the key: the vault is locked again, the session is kept.
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Coffre verrouillé' })).toBeVisible();
});

test('a known device logs in without the Secret Key, a new one must type it', async ({ page, browser }) => {
  const secretKey = await registerThroughUi(page);
  await page.getByRole('button', { name: 'Se déconnecter' }).click();

  await loginThroughUi(page);
  await expect(page.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });

  const newDevice = await browser.newContext();
  const other = await newDevice.newPage();
  await other.goto('/');
  await expect(other.getByLabel('Secret Key')).toBeHidden();
  await other.getByLabel('Adresse email').fill(EMAIL);
  await expect(other.getByLabel('Secret Key')).toBeVisible();

  const wrongKey = 'A1-000000-000000-00000-00000-00000';
  await loginThroughUi(other, { secretKey: wrongKey });
  await expect(other.getByRole('alert')).toBeVisible({ timeout: 30_000 });

  await other.getByLabel('Secret Key').fill(secretKey.toLowerCase().replaceAll('-', ' '));
  await other.getByRole('button', { name: 'Se connecter' }).click();
  await expect(other.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });
  await newDevice.close();
});

test('weak or common master passwords are refused before anything is sent', async ({ page }) => {
  const traffic = recordTraffic(page);
  await page.goto('/');
  await page.getByLabel('Adresse email').fill(EMAIL);
  await page.getByLabel('Mot de passe maître').fill('password1234');
  await expect(page.getByText('Ce mot de passe fait partie des plus courants.')).toBeVisible();
  await page.getByLabel('Confirmation').fill('password1234');
  await page.getByRole('button', { name: 'Créer le coffre' }).click();

  await expect(page.getByRole('alert')).toContainText('plus courants');
  expect(traffic.join('\n')).not.toContain('/api/auth/register');
});

test('the client refuses key derivation parameters weakened by the server', async ({ page }) => {
  await registerThroughUi(page);
  await page.getByRole('button', { name: 'Se déconnecter' }).click();

  await page.route('**/api/auth/prelogin', async (route) => {
    const response = await route.fetch();
    const body = (await response.json()) as { kdf: Record<string, unknown>; salt: string };
    await route.fulfill({ response, json: { ...body, kdf: { ...body.kdf, iterations: 1, memoryKib: 1024 } } });
  });

  const traffic = recordTraffic(page);
  await loginThroughUi(page);
  await expect(page.getByRole('alert')).toContainText('Alerte de sécurité');
  expect(traffic.join('\n')).not.toContain('/api/auth/login');
});

test('only one of two simultaneous registrations succeeds', async ({ playwright, baseURL }) => {
  const register = async (email: string): Promise<number> => {
    const context = await playwright.request.newContext({ baseURL: baseURL ?? '' });
    const session = await context.get('/api/session', { headers: { 'X-PM-Client': '1' } });
    const { csrfToken } = (await session.json()) as { csrfToken: string };
    const random = (bytes: number): string => Buffer.from(crypto.getRandomValues(new Uint8Array(bytes))).toString('base64');
    const response = await context.post('/api/auth/register', {
      headers: { 'X-PM-Client': '1', 'X-CSRF-Token': csrfToken },
      data: {
        email,
        kdf: { algorithm: 'argon2id', memoryKib: 65536, iterations: 3, parallelism: 1 },
        salt: random(16),
        authProof: random(32),
        wrappedVaultKey: random(61),
      },
    });
    await context.dispose();
    return response.status();
  };

  const statuses = await Promise.all([register('first@example.com'), register('second@example.com')]);
  expect(statuses.sort()).toEqual([201, 403]);
});
