import { readFile } from 'node:fs/promises';

import { expect, type Page, test } from '@playwright/test';

import { addEntryThroughUi, EMAIL, loginThroughUi, MASTER_PASSWORD, passSecondFactor, registerThroughUi, resetState, sql } from './support';

const NEW_PASSWORD = 'nouvelle-phrase-secrete-du-coffre-2026';

test.beforeEach(() => {
  resetState();
});

async function openSettings(page: Page): Promise<void> {
  if (await page.getByRole('heading', { name: 'Paramètres de sécurité' }).isVisible()) {
    return;
  }
  await page.getByRole('button', { name: 'Paramètres' }).click();
  await expect(page.getByRole('heading', { name: 'Paramètres de sécurité' })).toBeVisible();
}

async function changePassword(page: Page, options: { rotate?: boolean; newSecretKey?: boolean } = {}): Promise<void> {
  await openSettings(page);
  await page.getByLabel('Mot de passe maître actuel').fill(MASTER_PASSWORD);
  await page.getByLabel('Nouveau mot de passe maître').fill(NEW_PASSWORD);
  await page.getByLabel('Confirmation du nouveau mot de passe').fill(NEW_PASSWORD);
  if (options.rotate === true) {
    await page.getByLabel(/Renouveler aussi la clé/).check();
  }
  if (options.newSecretKey === true) {
    await page.getByLabel(/Générer une nouvelle Secret Key/).check();
  }
  await page.getByRole('button', { name: 'Changer le mot de passe maître' }).click();
}

async function exportBackup(page: Page): Promise<string> {
  await openSettings(page);
  await page.getByLabel('Mot de passe maître (pour l’export)').fill(MASTER_PASSWORD);
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Exporter le coffre chiffré' }).click();
  const path = await (await download).path();
  return readFile(path, 'utf8');
}

async function importBackup(page: Page, content: string, password: string, secretKey?: string): Promise<void> {
  await openSettings(page);
  await page.getByLabel('Fichier de sauvegarde').setInputFiles({ name: 'coffre.json', mimeType: 'application/json', buffer: Buffer.from(content) });
  await page.getByLabel('Mot de passe maître de la sauvegarde').fill(password);
  if (secretKey !== undefined) {
    await page.getByLabel('Secret Key de la sauvegarde').fill(secretKey);
  }
  await page.getByRole('button', { name: 'Importer' }).click();
}

test('changing the master password keeps the entries and retires the old password', async ({ page }) => {
  const { totpSecret } = await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'keep.example', username: 'k', password: 'Keep-Me-Safe-11!' });

  await changePassword(page);
  await expect(page.getByText('Mot de passe maître changé.')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('entries')).toContainText('keep.example');

  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await loginThroughUi(page);
  await expect(page.getByRole('alert')).toContainText('Identifiants incorrects', { timeout: 30_000 });

  await page.getByLabel('Mot de passe maître').fill(NEW_PASSWORD);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await passSecondFactor(page, { totpSecret });
  await expect(page.getByTestId('entries')).toContainText('keep.example', { timeout: 30_000 });
});

test('renewing the vault key re-encrypts every entry', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'one.example', username: 'a', password: 'First-Entry-Pass-1' });
  await addEntryThroughUi(page, { site: 'two.example', username: 'b', password: 'Second-Entry-Pass-2' });
  const before = sql('SELECT ciphertext FROM vault_entry ORDER BY uuid');

  await changePassword(page, { rotate: true });
  await expect(page.getByText('Mot de passe maître changé et coffre rechiffré.')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('entries').locator('li')).toHaveCount(2);

  const after = sql('SELECT ciphertext FROM vault_entry ORDER BY uuid');
  const ciphertexts = (output: string): string[] => output.match(/[A-Za-z0-9+/]{40,}={0,2}/g) ?? [];
  expect(ciphertexts(after)).toHaveLength(2);
  for (const value of ciphertexts(after)) {
    expect(ciphertexts(before)).not.toContain(value);
  }

  // The renewed key really decrypts everything after a fresh unlock.
  await page.reload();
  await page.getByLabel('Mot de passe maître').fill(NEW_PASSWORD);
  await page.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(page.getByTestId('entries')).toContainText('two.example', { timeout: 30_000 });
});

test('a new Secret Key comes with a new emergency kit and replaces the old one', async ({ page, browser }) => {
  const { secretKey: oldSecretKey, backupCodes } = await registerThroughUi(page);

  await changePassword(page, { newSecretKey: true });
  await expect(page.getByRole('heading', { name: 'Conservez votre kit de secours' })).toBeVisible({ timeout: 30_000 });
  const newSecretKey = (await page.getByTestId('secret-key').textContent()) ?? '';
  expect(newSecretKey).not.toBe(oldSecretKey);
  await page.getByLabel('J’ai imprimé ou enregistré mon kit de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByText('Mot de passe maître et Secret Key changés.')).toBeVisible({ timeout: 30_000 });

  const otherDevice = await browser.newContext();
  const other = await otherDevice.newPage();
  await other.goto('/');
  await loginThroughUi(other, { password: NEW_PASSWORD, secretKey: oldSecretKey });
  await expect(other.getByRole('alert')).toContainText('Identifiants incorrects', { timeout: 30_000 });
  await other.getByLabel('Secret Key').fill(newSecretKey);
  await other.getByRole('button', { name: 'Se connecter' }).click();
  await passSecondFactor(other, { backupCode: backupCodes[0] ?? '' });
  await expect(other.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });
  await otherDevice.close();
});

test('a backup made before a password change is restored with the old secrets', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'restore.example', username: 'r', password: 'Restore-Me-Later-3' });
  const backup = await exportBackup(page);
  expect(backup).not.toContain('restore.example');
  expect(JSON.parse(backup)).toMatchObject({ format: 'pm-backup-v2' });

  await page.getByRole('button', { name: 'Retour au coffre' }).click();
  page.once('dialog', (dialog) => { void dialog.accept(); });
  await page.getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByTestId('entries')).toContainText('Votre coffre est vide');
  await changePassword(page);
  await expect(page.getByText('Mot de passe maître changé.')).toBeVisible({ timeout: 30_000 });

  await importBackup(page, backup, MASTER_PASSWORD);
  await expect(page.getByText('1 entrée(s) importée(s), 0 déjà présente(s) ignorée(s).')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('entries')).toContainText('restore.example');

  // Importing again skips what is already there.
  await importBackup(page, backup, MASTER_PASSWORD);
  await expect(page.getByText('0 entrée(s) importée(s), 1 déjà présente(s) ignorée(s).')).toBeVisible({ timeout: 30_000 });
});

test('a backup survives the loss of the whole database', async ({ page, browser }) => {
  const { secretKey } = await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'disaster.example', username: 'd', password: 'Survive-The-Disaster-4' });
  const backup = await exportBackup(page);

  resetState();
  const freshContext = await browser.newContext();
  const fresh = await freshContext.newPage();
  await registerThroughUi(fresh, 'new-owner@example.com', 'un-tout-nouveau-coffre-apres-sinistre');
  await importBackup(fresh, backup, MASTER_PASSWORD, secretKey);
  await expect(fresh.getByText('1 entrée(s) importée(s)')).toBeVisible({ timeout: 30_000 });
  await expect(fresh.getByTestId('entries')).toContainText('disaster.example');
  await freshContext.close();
});

test('a tampered backup or wrong secrets are refused', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'tamper.example', username: 't', password: 'Do-Not-Tamper-5' });
  const backup = JSON.parse(await exportBackup(page)) as { kdf: { iterations: number } };

  await importBackup(page, JSON.stringify(backup), 'not-the-right-password');
  await expect(page.getByRole('alert')).toContainText('Déchiffrement impossible', { timeout: 30_000 });

  await importBackup(page, JSON.stringify({ ...backup, kdf: { ...backup.kdf, iterations: 1 } }), MASTER_PASSWORD);
  await expect(page.getByRole('alert')).toContainText('Alerte de sécurité');
});

test('the breach check only sends hash prefixes to Have I Been Pwned', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'leaked.example', username: 'l', password: 'password' });
  await page.getByText('déjà utilisé').waitFor({ state: 'detached' }).catch(() => undefined);

  const requested: string[] = [];
  await page.route('https://api.pwnedpasswords.com/range/**', async (route) => {
    requested.push(route.request().url());
    await route.fulfill({
      status: 200,
      headers: { 'Access-Control-Allow-Origin': '*' },
      body: '1E4C9B93F3F0682250B6CF8331B7EE68FD8:9659365\r\n',
    });
  });

  await openSettings(page);
  await page.getByRole('button', { name: 'Vérifier mes mots de passe' }).click();
  await expect(page.getByTestId('breach-results')).toContainText('leaked.example');
  expect(requested).toEqual(['https://api.pwnedpasswords.com/range/5BAA6']);
});

test('a Secret Key change whose answer is lost does not lock the owner out', async ({ page }) => {
  const { totpSecret } = await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'lost-answer.example', username: 'l', password: 'Lost-Answer-Pass-6' });

  // The server applies the change, but the answer never reaches the browser.
  await page.route('**/api/account/password', async (route) => {
    await route.fetch();
    await route.abort('connectionreset');
  });
  await changePassword(page, { newSecretKey: true });
  await expect(page.getByRole('heading', { name: 'Conservez votre kit de secours' })).toBeVisible({ timeout: 30_000 });
  await page.getByLabel('J’ai imprimé ou enregistré mon kit de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByRole('alert')).toContainText('n’a pas confirmé le changement', { timeout: 30_000 });
  await page.unroute('**/api/account/password');

  // The lost answer also carried the renewed session cookie: the browser never got it.
  await page.context().clearCookies();

  // The device kept the new Secret Key aside: logging in with the new password just works.
  await page.goto('/');
  await loginThroughUi(page, { password: NEW_PASSWORD });
  await passSecondFactor(page, { totpSecret });
  await expect(page.getByTestId('entries')).toContainText('lost-answer.example', { timeout: 30_000 });
});
