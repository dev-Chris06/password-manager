import { expect, test } from '@playwright/test';

import { addEntryThroughUi, EMAIL, MASTER_PASSWORD, resetState } from './support';
import { totp } from './totp';

// Captures the screenshots of the README. Skipped unless explicitly requested:
//   CAPTURE_SCREENSHOTS=1 npm run test:e2e -- tests/e2e/screenshots.spec.ts
test.skip(process.env.CAPTURE_SCREENSHOTS !== '1', 'Run with CAPTURE_SCREENSHOTS=1 to refresh docs/images.');
test.use({ viewport: { width: 1100, height: 760 }, colorScheme: 'light' });

const shot = (name: string): string => `docs/images/${name}.png`;

test('README screenshots', async ({ page }) => {
  test.setTimeout(180_000);
  resetState();

  await page.goto('/');
  await page.getByLabel('Adresse email').fill(EMAIL);
  await page.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await page.getByLabel('Confirmation').fill(MASTER_PASSWORD);
  await page.screenshot({ path: shot('01-inscription') });
  await page.getByRole('button', { name: 'Créer le coffre' }).click();
  await expect(page.getByRole('heading', { name: 'Conservez votre kit de secours' })).toBeVisible({ timeout: 30_000 });
  await page.screenshot({ path: shot('02-kit-de-secours') });
  await page.getByLabel('J’ai imprimé ou enregistré mon kit de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByRole('heading', { name: 'Activez la double authentification' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('img', { name: /QR code/ })).toBeVisible();
  await page.screenshot({ path: shot('03-double-authentification') });
  const secret = ((await page.getByTestId('totp-secret').textContent()) ?? '').replace(/\s/g, '');
  await page.getByLabel('Code affiché par l’application').fill(totp(secret));
  await page.getByRole('button', { name: 'Activer' }).click();
  await page.getByLabel('J’ai enregistré mes codes de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();

  for (const entry of [
    { site: 'banque-exemple.fr', username: 'alice.martin', password: 'Tr3s-S3cret-Bancaire!', category: 'Banque' },
    { site: 'mail.exemple.fr', username: 'alice@exemple.fr', password: 'Mail-Password-1!', category: 'Email' },
    { site: 'reseau.exemple.fr', username: 'alice_m', password: 'Social-Password-2!', category: 'Réseaux sociaux' },
    { site: 'ecole.exemple.fr', username: 'a.martin', password: 'Ecole-Password-3!', category: 'École' },
  ]) {
    await addEntryThroughUi(page, entry);
  }
  await page.screenshot({ path: shot('04-coffre') });

  await page.getByRole('button', { name: 'Ajouter' }).click();
  await page.getByLabel('Site', { exact: true }).fill('nouveau-site.fr');
  await page.getByLabel('Identifiant').fill('alice');
  await page.getByRole('button', { name: 'Générer' }).click();
  await page.screenshot({ path: shot('05-generateur') });
  await page.getByRole('button', { name: 'Annuler' }).click();

  await page.getByRole('button', { name: 'Paramètres' }).click();
  await page.screenshot({ path: shot('06-parametres'), fullPage: true });
});
