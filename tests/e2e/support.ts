import { execFileSync } from 'node:child_process';

import { expect, type Page, type Request } from '@playwright/test';

export const EMAIL = 'owner@example.com';
export const MASTER_PASSWORD = 'violet-tramway-cerf-volant-42';

function console(...args: string[]): void {
  execFileSync('php', ['bin/console', ...args, '--env=e2e', '--no-debug', '--quiet'], { stdio: 'inherit' });
}

/**
 * Empties the e2e database and the rate limiter storage through the Symfony console:
 * the application itself exposes no reset endpoint.
 */
export function resetState(): void {
  console('dbal:run-sql', 'DELETE FROM app_user');
  console('dbal:run-sql', 'DELETE FROM sessions');
  console('cache:pool:clear', 'cache.rate_limiter');
}

/** Records every request body and URL sent by the page, to check that no secret leaves it. */
export function recordTraffic(page: Page): string[] {
  const traffic: string[] = [];
  page.on('request', (request: Request) => {
    traffic.push(request.url(), request.postData() ?? '', JSON.stringify(request.headers()));
  });
  return traffic;
}

export async function registerThroughUi(page: Page, email = EMAIL, password = MASTER_PASSWORD): Promise<string> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Créer votre coffre' })).toBeVisible();
  await page.getByLabel('Adresse email').fill(email);
  await page.getByLabel('Mot de passe maître').fill(password);
  await page.getByLabel('Confirmation').fill(password);
  await page.getByRole('button', { name: 'Créer le coffre' }).click();

  await expect(page.getByRole('heading', { name: 'Conservez votre kit de secours' })).toBeVisible({ timeout: 30_000 });
  const secretKey = (await page.getByTestId('secret-key').textContent()) ?? '';
  expect(secretKey).toMatch(/^A1-/);

  await page.getByLabel('J’ai imprimé ou enregistré mon kit de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByTestId('vault-status')).toContainText(email, { timeout: 30_000 });

  return secretKey;
}

export async function loginThroughUi(page: Page, options: { email?: string; password?: string; secretKey?: string } = {}): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
  await page.getByLabel('Adresse email').fill(options.email ?? EMAIL);
  await page.getByLabel('Mot de passe maître').fill(options.password ?? MASTER_PASSWORD);
  if (options.secretKey !== undefined) {
    await page.getByLabel('Secret Key').fill(options.secretKey);
  }
  await page.getByRole('button', { name: 'Se connecter' }).click();
}
