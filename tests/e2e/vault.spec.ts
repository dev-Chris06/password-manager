import { expect, test } from '@playwright/test';

import { addEntryThroughUi, MASTER_PASSWORD, registerThroughUi, resetState, sql } from './support';
import { totp } from './totp';

test.beforeEach(() => {
  resetState();
});

test('entries are encrypted before leaving the browser and the database holds no plaintext', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'banque-exemple.fr', username: 'alice.martin', password: 'Tr3s-S3cret-Bancaire!', category: 'Banque', notes: 'Question secrète : chat' });

  const entry = page.getByTestId('entries').locator('li').first();
  await expect(entry).toContainText('banque-exemple.fr');
  await expect(entry).toContainText('alice.martin');
  await expect(entry).toContainText('Banque');
  await expect(entry).not.toContainText('Tr3s-S3cret-Bancaire!');
  await entry.getByRole('button', { name: 'Afficher' }).click();
  await expect(entry).toContainText('Tr3s-S3cret-Bancaire!');

  const stored = sql('SELECT ciphertext FROM vault_entry') + sql('SELECT action, detail FROM audit_log');
  for (const secret of ['banque-exemple', 'alice.martin', 'Tr3s-S3cret-Bancaire!', 'chat', 'bank']) {
    expect(stored).not.toContain(secret);
  }
});

test('entries can be edited, searched, filtered and deleted', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'mail.example.com', username: 'alice', password: 'Mail-Password-1!', category: 'Email' });
  await addEntryThroughUi(page, { site: 'social.example.com', username: 'alice_s', password: 'Social-Password-2!', category: 'Réseaux sociaux' });

  await page.getByRole('searchbox', { name: 'Rechercher' }).fill('social');
  await expect(page.getByTestId('entries').locator('li')).toHaveCount(1);
  await page.getByRole('searchbox', { name: 'Rechercher' }).fill('');
  await page.getByLabel('Catégorie').selectOption({ label: 'Email' });
  await expect(page.getByTestId('entries').locator('li')).toHaveCount(1);
  await expect(page.getByTestId('entries')).toContainText('mail.example.com');
  await page.getByLabel('Catégorie').selectOption({ label: 'Toutes les catégories' });

  const mail = page.getByTestId('entries').locator('li', { hasText: 'mail.example.com' });
  await mail.getByRole('button', { name: 'Modifier' }).click();
  await page.getByLabel('Identifiant').fill('alice.new');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Entrée modifiée.')).toBeVisible();
  await expect(page.getByTestId('entries')).toContainText('alice.new');

  page.once('dialog', (dialog) => { void dialog.accept(); });
  await page.getByTestId('entries').locator('li', { hasText: 'social.example.com' }).getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByTestId('entries').locator('li')).toHaveCount(1);

  await page.reload();
  await page.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await page.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(page.getByTestId('entries').locator('li')).toHaveCount(1, { timeout: 30_000 });
  await expect(page.getByTestId('entries')).toContainText('alice.new');
});

test('the generator, the strength meter and the reuse warning help choosing passwords', async ({ page }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'first.example', username: 'a', password: 'Same-Password-Everywhere-9' });

  await page.getByRole('button', { name: 'Ajouter' }).click();
  await page.getByLabel('Site', { exact: true }).fill('second.example');
  await page.getByLabel('Mot de passe', { exact: true }).fill('Same-Password-Everywhere-9');
  await expect(page.getByText('Mot de passe déjà utilisé pour : first.example.')).toBeVisible();
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByRole('alert')).toContainText('déjà utilisé ailleurs');

  await page.getByRole('button', { name: 'Générer' }).click();
  const generated = await page.getByLabel('Mot de passe', { exact: true }).inputValue();
  expect(generated).toHaveLength(20);
  await expect(page.getByText('Robustesse : Très fort')).toBeVisible();
  await expect(page.getByText('déjà utilisé pour')).toBeHidden();
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Entrée ajoutée.')).toBeVisible();
});

test('copying puts the password in the clipboard', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'copy.example', username: 'bob', password: 'Copy-Me-Please-77' });

  await page.getByRole('button', { name: 'Copier mot de passe' }).click();
  await expect(page.getByText('Effacement du presse-papiers tenté')).toBeVisible();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('Copy-Me-Please-77');
});

test('a concurrent modification is detected instead of being overwritten', async ({ page, context }) => {
  await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'shared.example', username: 'v1', password: 'Shared-Password-1!' });

  // A second tab of the same session edits the entry first.
  const other = await context.newPage();
  await other.goto('/');
  await other.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await other.getByRole('button', { name: 'Déverrouiller' }).click();
  await other.getByRole('button', { name: 'Modifier' }).click();
  await other.getByLabel('Identifiant').fill('from-other-tab');
  await other.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(other.getByText('Entrée modifiée.')).toBeVisible({ timeout: 30_000 });

  await page.getByRole('button', { name: 'Modifier' }).click();
  await page.getByLabel('Identifiant').fill('stale-edit');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('modifiée ailleurs')).toBeVisible();
  await expect(page.getByTestId('entries')).toContainText('from-other-tab');
});

test('the vault locks itself after five minutes without activity', async ({ page }) => {
  await page.clock.install();
  await registerThroughUi(page);
  await expect(page.getByRole('heading', { name: 'Mon coffre' })).toBeVisible();

  await page.clock.fastForward('05:01');
  await expect(page.getByRole('heading', { name: 'Coffre verrouillé' })).toBeVisible();
});

test('the journal lists the account activity and backup codes can be renewed', async ({ page }) => {
  const { totpSecret } = await registerThroughUi(page);
  await addEntryThroughUi(page, { site: 'journal.example', username: 'j', password: 'Journal-Password-5!' });

  await page.getByRole('button', { name: 'Journal' }).click();
  await expect(page.getByRole('table')).toContainText('Entrée ajoutée');
  await expect(page.getByRole('table')).toContainText('Double authentification activée');
  await page.getByRole('button', { name: 'Retour au coffre' }).click();

  await page.getByRole('button', { name: 'Paramètres' }).click();
  await page.getByLabel('Mot de passe maître (pour les codes)').fill(MASTER_PASSWORD);
  await page.getByLabel('Code de l’application d’authentification').fill(totp(totpSecret, 1));
  await page.getByRole('button', { name: 'Régénérer les codes de secours' }).click();
  await expect(page.getByRole('heading', { name: 'Vos codes de secours' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('backup-codes').locator('li')).toHaveCount(10);
});
