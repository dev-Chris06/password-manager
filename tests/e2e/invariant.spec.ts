import { expect, type Page, test } from '@playwright/test';

import { addEntryThroughUi, loginThroughUi, MASTER_PASSWORD, passSecondFactor, registerThroughUi, resetState, sql } from './support';

// docs/PLAN.md §3 rule 5: the server never receives the master password, the Secret Key, a key
// or any plaintext entry. Every request of a complete session is recorded and searched.

const NEW_PASSWORD = 'invariant-nouvelle-phrase-de-passe-2026';
const ENTRY = { site: 'invariant-bank.example', username: 'invariant.user', password: 'Invariant-Entry-Secret-1!', notes: 'invariant-private-note' };

interface Recorded {
  readonly url: string;
  readonly body: string;
  readonly headers: string;
}

function record(page: Page): Recorded[] {
  const requests: Recorded[] = [];
  page.on('request', (request) => {
    requests.push({ url: request.url(), body: request.postData() ?? '', headers: JSON.stringify(request.headers()) });
  });
  return requests;
}

test('no secret ever leaves the browser during a complete session', async ({ page }) => {
  test.setTimeout(180_000);
  resetState();
  const requests = record(page);

  const { secretKey, totpSecret } = await registerThroughUi(page);
  await addEntryThroughUi(page, { ...ENTRY, category: 'Banque' });

  // Lock, unlock, log out and in again with the second factor.
  await page.getByRole('button', { name: 'Verrouiller' }).click();
  await page.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await page.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(page.getByTestId('entries')).toContainText(ENTRY.site, { timeout: 30_000 });
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await loginThroughUi(page);
  await passSecondFactor(page, { totpSecret });
  await expect(page.getByTestId('entries')).toContainText(ENTRY.site, { timeout: 30_000 });

  // Export, then change the master password with a vault key rotation.
  await page.getByRole('button', { name: 'Paramètres' }).click();
  await page.getByLabel('Mot de passe maître (pour l’export)').fill(MASTER_PASSWORD);
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Exporter le coffre chiffré' }).click();
  await download;
  await page.getByLabel('Mot de passe maître actuel').fill(MASTER_PASSWORD);
  await page.getByLabel('Nouveau mot de passe maître').fill(NEW_PASSWORD);
  await page.getByLabel('Confirmation du nouveau mot de passe').fill(NEW_PASSWORD);
  await page.getByLabel(/Renouveler aussi la clé/).check();
  await page.getByRole('button', { name: 'Changer le mot de passe maître' }).click();
  await expect(page.getByText('Mot de passe maître changé et coffre rechiffré.')).toBeVisible({ timeout: 30_000 });

  const secrets = [
    MASTER_PASSWORD,
    NEW_PASSWORD,
    secretKey,
    secretKey.replaceAll('-', ''),
    totpSecret,
    ENTRY.site,
    ENTRY.username,
    ENTRY.password,
    ENTRY.notes,
    encodeURIComponent(ENTRY.password),
  ];
  const sentToServer = requests.filter((request) => !request.url.startsWith('https://api.pwnedpasswords.com/'));
  expect(sentToServer.length).toBeGreaterThan(20);
  for (const request of sentToServer) {
    for (const secret of secrets) {
      expect(`${request.url}\n${request.body}\n${request.headers}`, `request to ${request.url}`).not.toContain(secret);
    }
  }

  // Nothing readable at rest either: entries, journal, users.
  const stored = [
    sql('SELECT ciphertext FROM vault_entry'),
    sql('SELECT action, detail, user_agent FROM audit_log'),
    sql('SELECT email, kdf_salt, auth_hash, wrapped_vault_key, totp_secret_encrypted FROM app_user'),
  ].join('\n');
  for (const secret of secrets) {
    expect(stored).not.toContain(secret);
  }
});
