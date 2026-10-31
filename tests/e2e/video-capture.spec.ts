import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

import { type BrowserContext, chromium, expect, type Page, test as base, type Worker } from '@playwright/test';

import { EMAIL, MASTER_PASSWORD, resetState, sql } from './support';
import { totp } from './totp';

// Films the real application for the demo video (docs/VIDEO.md). Skipped unless requested:
//   CAPTURE_VIDEO=1 npm run test:e2e -- tests/e2e/video-capture.spec.ts
// Output: var/video-capture/ (raw .webm files, step markers and real data for the montage).
const OUT = path.resolve('var/video-capture');
const EXTENSION_PATH = path.resolve('extension/dist');
const VIEWPORT = { width: 1920, height: 1080 };

const test = base.extend<{ context: BrowserContext; worker: Worker }>({
  context: async ({ baseURL }, use) => {
    mkdirSync(OUT, { recursive: true });
    const context = await chromium.launchPersistentContext('', {
      channel: 'chromium',
      baseURL: baseURL ?? '',
      viewport: VIEWPORT,
      colorScheme: 'light',
      locale: 'fr-FR',
      recordVideo: { dir: OUT, size: { width: 1920, height: 1080 } },
      permissions: ['clipboard-read', 'clipboard-write'],
      args: [`--disable-extensions-except=${EXTENSION_PATH}`, `--load-extension=${EXTENSION_PATH}`],
    });
    // Readable footage: the interface is zoomed (CSSOM, so the CSP of the app is untouched).
    await context.addInitScript(() => {
      const zoom = window.location.protocol === 'chrome-extension:' ? '2.5' : '1.5';
      document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.style.setProperty('zoom', zoom);
      });
    });
    await use(context);
    await context.close();
  },
  worker: async ({ context }, use) => {
    await use(context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker')));
  },
});

test.skip(process.env.CAPTURE_VIDEO !== '1', 'Run with CAPTURE_VIDEO=1 to film the demo.');

/** Records when each step starts, in seconds since the page was opened (for trimming). */
function markers(): { mark: (name: string) => void; save: (file: string) => void } {
  const start = Date.now();
  const list: Record<string, number> = {};
  return {
    mark: (name) => {
      list[name] = (Date.now() - start) / 1000;
    },
    save: (file) => {
      writeFileSync(path.join(OUT, file), `${JSON.stringify(list, null, 2)}\n`);
    },
  };
}

const pause = (page: Page, ms: number): Promise<void> => page.waitForTimeout(ms);
const type = async (page: Page, label: string | RegExp, text: string, exact = false): Promise<void> => {
  await page.getByLabel(label, { exact }).pressSequentially(text, { delay: 55 });
};

test('film the demo', async ({ context, worker, baseURL }) => {
  test.setTimeout(600_000);
  resetState();

  await context.route(/^https:\/\/(banque-exemple|autre-site)\.fr\//, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Banque Exemple</title>
        <style>body{font-family:system-ui;background:#0b3d5c;color:#fff;display:flex;justify-content:center;padding-top:110px;margin:0}
        form{background:#fff;color:#123;padding:2.5rem 3rem;border-radius:14px;width:24rem;box-shadow:0 10px 40px #0005}
        h1{margin:0 0 1.5rem;font-size:1.6rem}label{display:block;font-weight:600;margin:.9rem 0 .3rem}
        input{width:100%;box-sizing:border-box;padding:.7rem;border:1px solid #9ab;border-radius:8px;font-size:1rem}
        button{margin-top:1.5rem;width:100%;padding:.8rem;border:0;border-radius:8px;background:#0b6fb8;color:#fff;font-size:1rem}</style>
        </head><body><form><h1>Espace client</h1>
        <label for="u">Identifiant</label><input id="u" name="login" autocomplete="username">
        <label for="p">Mot de passe</label><input id="p" type="password" name="password" autocomplete="current-password">
        <button type="button">Se connecter</button></form></body></html>`,
    }),
  );

  // ---------------------------------------------------------------- Web application
  const app = await context.newPage();
  const appMarks = markers();
  const requests: { url: string; body: string }[] = [];
  app.on('request', (request) => {
    if (request.url().includes('/api/')) {
      requests.push({ url: request.url().replace(baseURL ?? '', ''), body: request.postData() ?? '' });
    }
  });

  appMarks.mark('register');
  await app.goto('/');
  await pause(app, 1200);
  await type(app, 'Adresse email', EMAIL);
  await type(app, 'Mot de passe maître', 'password1234');
  await pause(app, 1800);
  appMarks.mark('register-strong');
  await app.getByLabel('Mot de passe maître').fill('');
  await type(app, 'Mot de passe maître', MASTER_PASSWORD);
  await pause(app, 1000);
  await type(app, 'Confirmation', MASTER_PASSWORD);
  await pause(app, 600);
  await app.getByRole('button', { name: 'Créer le coffre' }).click();

  await expect(app.getByRole('heading', { name: 'Conservez votre kit de secours' })).toBeVisible({ timeout: 30_000 });
  appMarks.mark('kit');
  await pause(app, 3500);
  await app.getByLabel('J’ai imprimé ou enregistré mon kit de secours en lieu sûr.').check();
  await pause(app, 600);
  await app.getByRole('button', { name: 'Continuer' }).click();

  await expect(app.getByRole('heading', { name: 'Activez la double authentification' })).toBeVisible({ timeout: 30_000 });
  appMarks.mark('two-factor');
  await pause(app, 3000);
  const totpSecret = ((await app.getByTestId('totp-secret').textContent()) ?? '').replace(/\s/g, '');
  await type(app, 'Code affiché par l’application', totp(totpSecret));
  await app.getByRole('button', { name: 'Activer' }).click();
  await expect(app.getByRole('heading', { name: 'Vos codes de secours' })).toBeVisible();
  appMarks.mark('backup-codes');
  await pause(app, 2500);
  await app.getByLabel('J’ai enregistré mes codes de secours en lieu sûr.').check();
  await app.getByRole('button', { name: 'Continuer' }).click();
  await expect(app.getByRole('heading', { name: 'Mon coffre' })).toBeVisible({ timeout: 30_000 });

  // Entries: one typed, one generated (with the reuse warning on the way), then search and copy.
  appMarks.mark('vault-add');
  const add = async (site: string, username: string, password: string | null, category: string): Promise<void> => {
    await app.getByRole('button', { name: 'Ajouter' }).click();
    await type(app, 'Site', site, true);
    await type(app, 'Identifiant', username);
    if (password === null) {
      await pause(app, 400);
      await app.getByRole('button', { name: 'Générer' }).click();
      await pause(app, 1500);
    } else {
      await type(app, 'Mot de passe', password, true);
      await pause(app, 900);
    }
    await app.getByLabel('Catégorie').selectOption({ label: category });
    await app.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(app.getByText('Entrée ajoutée.')).toBeVisible();
    await pause(app, 700);
  };
  await add('banque-exemple.fr', 'alice.martin', 'Tr3s-S3cret-Bancaire!', 'Banque');
  appMarks.mark('vault-reuse');
  await app.getByRole('button', { name: 'Ajouter' }).click();
  await type(app, 'Site', 'mail.exemple.fr', true);
  await type(app, 'Identifiant', 'alice@exemple.fr');
  await type(app, 'Mot de passe', 'Tr3s-S3cret-Bancaire!', true);
  await pause(app, 2200);
  appMarks.mark('vault-generate');
  await app.getByRole('button', { name: 'Générer' }).click();
  await pause(app, 2000);
  await app.getByLabel('Catégorie').selectOption({ label: 'Email' });
  await app.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(app.getByText('Entrée ajoutée.')).toBeVisible();
  await pause(app, 600);
  await add('reseau.exemple.fr', 'alice_m', 'azerty123', 'Réseaux sociaux');
  appMarks.mark('vault-search');
  await type(app, /Rechercher/, 'banque');
  await pause(app, 1500);
  await app.getByRole('button', { name: 'Afficher' }).first().click();
  await pause(app, 1500);
  await app.getByRole('button', { name: 'Copier mot de passe' }).first().click();
  await pause(app, 2200);
  await app.getByRole('searchbox', { name: 'Rechercher' }).fill('');
  await pause(app, 800);

  // Settings: encrypted backup and breach check (real Have I Been Pwned range API).
  appMarks.mark('settings');
  await app.getByRole('button', { name: 'Paramètres' }).click();
  await pause(app, 1200);
  await type(app, 'Mot de passe maître (pour l’export)', MASTER_PASSWORD);
  const download = app.waitForEvent('download');
  await app.getByRole('button', { name: 'Exporter le coffre chiffré' }).click();
  await download;
  await pause(app, 1500);
  appMarks.mark('breach');
  await app.getByRole('button', { name: 'Vérifier mes mots de passe' }).scrollIntoViewIfNeeded();
  await app.getByRole('button', { name: 'Vérifier mes mots de passe' }).click();
  await expect(app.getByTestId('breach-results')).toContainText('reseau.exemple.fr', { timeout: 30_000 });
  await pause(app, 3500);
  await app.getByRole('button', { name: 'Retour au coffre' }).click();
  await pause(app, 800);
  appMarks.mark('lock');
  await app.getByRole('button', { name: 'Verrouiller' }).click();
  await pause(app, 2500);
  appMarks.mark('end');
  appMarks.save('app-markers.json');

  // Real data for the "database" and "network" scenes.
  writeFileSync(path.join(OUT, 'database.txt'), sql('SELECT HEX(uuid) AS uuid, LEFT(ciphertext, 64) AS ciphertext, version FROM vault_entry'));
  writeFileSync(path.join(OUT, 'requests.json'), `${JSON.stringify(requests.filter((r) => r.body !== ''), null, 2)}\n`);

  // ---------------------------------------------------------------- Extension
  // Unlock the vault again for the extension scene (the app tab stays locked in the background).
  await app.getByLabel('Mot de passe maître').fill(MASTER_PASSWORD);
  await app.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(app.getByRole('heading', { name: 'Mon coffre' })).toBeVisible({ timeout: 30_000 });
  const secretKey = await app.evaluate((email) => localStorage.getItem(`pm:secret-key:${email}`) ?? '', EMAIL);

  const bank = await context.newPage();
  const bankMarks = markers();
  await bank.goto('https://banque-exemple.fr/connexion');
  const bankTab = await worker.evaluate(async () => (await chrome.tabs.query({ url: 'https://banque-exemple.fr/*' }))[0]?.id ?? -1);
  await pause(bank, 1500);

  const extensionId = new URL(worker.url()).host;
  const popup = await context.newPage();
  const popupMarks = markers();
  await popup.goto(`chrome-extension://${extensionId}/popup.html?tab=${String(bankTab)}`);
  await popup.getByLabel('URL du serveur').pressSequentially(baseURL ?? '', { delay: 40 });
  await popup.getByRole('button', { name: 'Enregistrer' }).click();
  popupMarks.mark('unlock');
  await popup.getByLabel('Email').pressSequentially(EMAIL, { delay: 40 });
  await popup.getByLabel('Mot de passe maître').pressSequentially(MASTER_PASSWORD, { delay: 40 });
  await popup.getByLabel('Secret Key').fill(secretKey);
  await pause(popup, 600);
  await popup.getByRole('button', { name: 'Déverrouiller' }).click();
  await expect(popup.getByTestId('popup-entries')).toContainText('alice.martin', { timeout: 30_000 });
  popupMarks.mark('entries');
  bankMarks.mark('before-fill');
  await pause(popup, 2500);
  await popup.getByRole('button', { name: 'Remplir' }).click();
  bankMarks.mark('filled');
  await pause(bank, 3000);

  // Another site: nothing is offered.
  const other = await context.newPage();
  await other.goto('https://autre-site.fr/connexion');
  const otherTab = await worker.evaluate(async () => (await chrome.tabs.query({ url: 'https://autre-site.fr/*' }))[0]?.id ?? -1);
  const popup2 = await context.newPage();
  const popup2Marks = markers();
  await popup2.goto(`chrome-extension://${extensionId}/popup.html?tab=${String(otherTab)}`);
  await expect(popup2.getByText('Aucun compte enregistré pour autre-site.fr.')).toBeVisible({ timeout: 30_000 });
  popup2Marks.mark('nothing');
  await pause(popup2, 3000);

  bankMarks.save('bank-markers.json');
  popupMarks.save('popup-markers.json');
  popup2Marks.save('popup2-markers.json');

  // Name the recordings after their page (paths are known before the browser closes).
  const names: [Page, string][] = [[app, 'app'], [bank, 'bank'], [popup, 'popup'], [popup2, 'popup-other'], [other, 'other']];
  const files: Record<string, string> = {};
  for (const [page, name] of names) {
    const video = page.video();
    if (video !== null) {
      files[name] = path.basename(await video.path());
    }
  }
  writeFileSync(path.join(OUT, 'videos.json'), `${JSON.stringify(files, null, 2)}\n`);
});
