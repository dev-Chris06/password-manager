import { execFileSync } from 'node:child_process';

import { expect, type Page, type Request } from '@playwright/test';

import { totp } from './totp';

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

export interface Account {
  readonly secretKey: string;
  readonly totpSecret: string;
  readonly backupCodes: string[];
}

export async function registerThroughUi(page: Page, email = EMAIL, password = MASTER_PASSWORD): Promise<Account> {
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

  // Mandatory two-factor enrollment.
  await expect(page.getByRole('heading', { name: 'Activez la double authentification' })).toBeVisible({ timeout: 30_000 });
  const totpSecret = ((await page.getByTestId('totp-secret').textContent()) ?? '').replace(/\s/g, '');
  await expect(page.getByRole('img', { name: /QR code/ })).toBeVisible();
  await page.getByLabel('Code affiché par l’application').fill(totp(totpSecret));
  await page.getByRole('button', { name: 'Activer' }).click();

  await expect(page.getByRole('heading', { name: 'Vos codes de secours' })).toBeVisible();
  const backupCodes = await page.getByTestId('backup-codes').locator('li').allTextContents();
  expect(backupCodes).toHaveLength(10);
  await page.getByLabel('J’ai enregistré mes codes de secours en lieu sûr.').check();
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByTestId('vault-status')).toContainText(email, { timeout: 30_000 });

  return { secretKey, totpSecret, backupCodes };
}

/** Answers the second-factor step with a TOTP code (stepOffset 1 avoids reusing the enrollment code). */
export async function passSecondFactor(page: Page, answer: { totpSecret: string; stepOffset?: number } | { backupCode: string }): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Double authentification' })).toBeVisible({ timeout: 30_000 });
  if ('backupCode' in answer) {
    await page.getByRole('button', { name: 'Utiliser un code de secours' }).click();
    await page.getByLabel('Code de secours').fill(answer.backupCode);
  } else {
    await page.getByLabel('Code à 6 chiffres').fill(totp(answer.totpSecret, answer.stepOffset ?? 1));
  }
  await page.getByRole('button', { name: 'Valider' }).click();
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
