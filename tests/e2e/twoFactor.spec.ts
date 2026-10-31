import { expect, test } from '@playwright/test';

import { EMAIL, loginThroughUi, passSecondFactor, registerThroughUi, resetState } from './support';

test.beforeEach(() => {
  resetState();
});

test('the encrypted vault is never served before the second factor', async ({ page }) => {
  const { totpSecret } = await registerThroughUi(page);
  await page.getByRole('button', { name: 'Se déconnecter' }).click();

  await loginThroughUi(page);
  await expect(page.getByRole('heading', { name: 'Double authentification' })).toBeVisible({ timeout: 30_000 });

  const status = await page.evaluate(async () => {
    const response = await fetch('/api/vault', { headers: { 'X-PM-Client': '1' } });
    return response.status;
  });
  expect(status).toBe(403);

  await passSecondFactor(page, { totpSecret });
  await expect(page.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });
});

test('a wrong code is refused and cancelling returns to the login screen', async ({ page }) => {
  await registerThroughUi(page);
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await loginThroughUi(page);

  await expect(page.getByRole('heading', { name: 'Double authentification' })).toBeVisible({ timeout: 30_000 });
  await page.getByLabel('Code à 6 chiffres').fill('000000');
  await page.getByRole('button', { name: 'Valider' }).click();
  await expect(page.getByRole('alert')).toContainText('Code incorrect');

  await page.getByRole('button', { name: 'Annuler' }).click();
  await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
});

test('a backup code works once', async ({ page }) => {
  const { backupCodes } = await registerThroughUi(page);
  const backupCode = backupCodes[5] ?? '';
  await page.getByRole('button', { name: 'Se déconnecter' }).click();

  await loginThroughUi(page);
  await passSecondFactor(page, { backupCode });
  await expect(page.getByTestId('vault-status')).toContainText(EMAIL, { timeout: 30_000 });

  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await loginThroughUi(page);
  await passSecondFactor(page, { backupCode });
  await expect(page.getByRole('alert')).toContainText('Code incorrect');
});
